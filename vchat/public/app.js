import { h, api, avatar, toast } from './ui.js';
import { startMeeting } from './meeting.js';

const root = document.getElementById('app');
const S = { config: null, user: null, meeting: null };

const show = (...nodes) => root.replaceChildren(...nodes);

async function boot() {
  try { S.config = await api('/config'); } catch { S.config = { googleClientId: '', iceServers: [], user: null, maxParticipants: 20 }; }
  S.user = S.config.user;
  window.addEventListener('hashchange', route);
  route();
}

function meetingCodeFromHash() {
  const m = location.hash.match(/^#\/m\/([a-z-]{1,16})$/i);
  return m ? m[1].toLowerCase() : null;
}

function route() {
  if (S.meeting) return; // in a call; leaving handles navigation
  if (!S.user) return renderAuth();
  const code = meetingCodeFromHash();
  return code ? renderPreJoin(code) : renderLobby();
}

/* ---------------- sign in ---------------- */
function renderAuth() {
  const err = h('div', { class: 'err', role: 'alert' });
  const setErr = (m) => { err.textContent = m || ''; };
  const phoneBox = h('div');
  const done = async (r) => {
    S.user = r.user;
    if (r.recoveryCodes) return renderRecovery(r.recoveryCodes);
    if (r.recoveryLeft !== undefined && r.recoveryLeft < 3) toast(`Recovery code used. ${r.recoveryLeft} left.`);
    route();
  };

  const googleBox = h('div', { id: 'gbtn', style: 'display:flex;justify-content:center;min-height:44px' });
  if (!S.config.googleClientId) {
    googleBox.replaceChildren(h('div', { class: 'note' }, 'Google sign-in is not configured (set GOOGLE_CLIENT_ID).'));
  } else {
    loadGoogle().then(() => {
      window.google.accounts.id.initialize({
        client_id: S.config.googleClientId,
        callback: async (resp) => {
          try { await done(await api('/auth/google', 'POST', { credential: resp.credential })); } catch (e) { setErr(e.message); }
        },
      });
      window.google.accounts.id.renderButton(googleBox, { theme: 'filled_black', size: 'large', text: 'continue_with', width: 280 });
    }).catch(() => googleBox.replaceChildren(h('div', { class: 'note' }, 'Could not load Google sign-in.')));
  }

  phoneStep1();

  function phoneStep1(phoneValue = '') {
    setErr('');
    const phone = h('input', { type: 'tel', autocomplete: 'tel', placeholder: '+1 555 123 4567', value: phoneValue, required: true });
    const btn = h('button', { class: 'btn block', type: 'submit' }, 'Text me a code');
    phoneBox.replaceChildren(h('form', {
      onsubmit: async (e) => {
        e.preventDefault(); btn.disabled = true; setErr('');
        try { await api('/auth/phone/start', 'POST', { phone: phone.value }); phoneStep2(phone.value); }
        catch (x) { setErr(x.message); btn.disabled = false; }
      },
    }, h('label', { class: 'field' }, h('span', {}, 'Phone number (with country code)'), phone), btn));
  }

  function phoneStep2(phoneValue) {
    setErr('');
    const code = h('input', { type: 'text', inputMode: 'numeric', autocomplete: 'one-time-code', maxLength: 6, placeholder: '123456', required: true });
    const btn = h('button', { class: 'btn block', type: 'submit' }, 'Verify');
    phoneBox.replaceChildren(h('form', {
      onsubmit: async (e) => {
        e.preventDefault(); btn.disabled = true; setErr('');
        try { phoneStep3(await api('/auth/phone/verify', 'POST', { phone: phoneValue, code: code.value.trim() })); }
        catch (x) { setErr(x.message); btn.disabled = false; }
      },
    },
    h('p', { class: 'note' }, `Step 1 of 2: enter the 6-digit code we texted to ${phoneValue}.`),
    h('label', { class: 'field' }, h('span', {}, 'SMS code'), code), btn,
    h('p', { class: 'note' }, h('a', { href: '#', onclick: (e) => { e.preventDefault(); phoneStep1(phoneValue); } }, 'Use a different number'))));
    code.focus();
  }

  function phoneStep3(v) {
    setErr('');
    const code = h('input', { type: 'text', inputMode: 'numeric', autocomplete: 'one-time-code', placeholder: '123456', required: true });
    const btn = h('button', { class: 'btn block', type: 'submit' }, v.step === 'enroll' ? 'Turn on 2-step & sign in' : 'Sign in');
    const intro = v.step === 'enroll'
      ? [h('p', { class: 'note' }, 'Step 2 of 2: set up your authenticator app (Google Authenticator, Authy, 1Password…). Add this key, then enter the 6-digit code it shows.'),
         h('div', { class: 'secret' }, v.secret),
         h('p', { class: 'note' }, h('a', { href: v.otpauth }, 'Open in authenticator app (on this phone)'))]
      : [h('p', { class: 'note' }, 'Step 2 of 2: enter the code from your authenticator app, or one of your recovery codes.')];
    phoneBox.replaceChildren(h('form', {
      onsubmit: async (e) => {
        e.preventDefault(); btn.disabled = true; setErr('');
        try { await done(await api('/auth/phone/2fa', 'POST', { ticket: v.ticket, code: code.value.trim() })); }
        catch (x) { setErr(x.message); btn.disabled = false; if (x.status === 401 && /expired|again/i.test(x.message)) phoneStep1(); }
      },
    }, intro, h('label', { class: 'field' }, h('span', {}, v.step === 'enroll' ? 'Authenticator code' : 'Authenticator or recovery code'), code), btn));
    code.focus();
  }

  show(h('div', { class: 'screen' }, h('div', { class: 'card' },
    h('h1', {}, 'Sign in to vchat'),
    h('p', { class: 'sub' }, 'Video meetings for up to 20 people.'),
    googleBox,
    h('div', { class: 'divider' }, 'or use your phone'),
    phoneBox, err)));
}

function renderRecovery(codes) {
  show(h('div', { class: 'screen' }, h('div', { class: 'card' },
    h('h1', {}, '2-step is on'),
    h('p', { class: 'sub' }, 'Save these recovery codes somewhere safe. Each works once if you lose your authenticator app. They will not be shown again.'),
    h('div', { class: 'codes' }, codes.map((c) => h('div', {}, c))),
    h('button', { class: 'btn block', onclick: route }, "I've saved them — continue"))));
}

let gPromise;
function loadGoogle() {
  gPromise ||= new Promise((res, rej) => {
    const s = h('script', { src: 'https://accounts.google.com/gsi/client', async: true, onload: res, onerror: rej });
    document.head.append(s);
  });
  return gPromise;
}

/* ---------------- lobby ---------------- */
function header() {
  return h('div', { class: 'topbar' },
    h('strong', {}, 'vchat'),
    h('div', { class: 'who' }, avatar(S.user), h('span', {}, S.user.name),
      h('button', { class: 'btn ghost', onclick: async () => { await api('/auth/logout', 'POST', {}); S.user = null; location.hash = ''; route(); } }, 'Sign out')));
}

function renderLobby() {
  const err = h('div', { class: 'err', role: 'alert' });
  const name = h('input', { type: 'text', value: S.user.name, maxLength: 60 });
  const title = h('input', { type: 'text', placeholder: `${S.user.name}'s meeting`, maxLength: 120 });
  const code = h('input', { type: 'text', placeholder: 'abc-defg-hij', autocapitalize: 'none', spellcheck: false });

  const saveName = async () => {
    const v = name.value.trim();
    if (v && v !== S.user.name) { try { S.user = (await api('/me', 'PATCH', { name: v })).user; } catch (e) { err.textContent = e.message; } }
  };

  show(header(), h('div', { class: 'lobby' },
    h('div', { class: 'card' },
      h('h1', {}, 'New meeting'), h('p', { class: 'sub' }, `Start a call and invite up to ${S.config.maxParticipants - 1} others.`),
      h('label', { class: 'field' }, h('span', {}, 'Your display name'), name),
      h('label', { class: 'field' }, h('span', {}, 'Meeting title (optional)'), title),
      h('button', { class: 'btn block', onclick: async () => {
        err.textContent = '';
        try { await saveName(); const m = await api('/meetings', 'POST', { title: title.value }); location.hash = `#/m/${m.code}`; }
        catch (e) { err.textContent = e.message; }
      } }, 'Start meeting')),
    h('div', { class: 'card' },
      h('h1', {}, 'Join a meeting'), h('p', { class: 'sub' }, 'Paste an invite link or enter the meeting code.'),
      h('label', { class: 'field' }, h('span', {}, 'Meeting code or link'), code),
      h('button', { class: 'btn block ghost', onclick: () => {
        const m = code.value.match(/([a-z]{3}-[a-z]{4}-[a-z]{3})/i);
        if (m) { err.textContent = ''; location.hash = `#/m/${m[1].toLowerCase()}`; } else err.textContent = 'That does not look like a meeting code.';
      } }, 'Join'), err)));
}

/* ---------------- pre-join + meeting ---------------- */
async function renderPreJoin(code) {
  let info;
  try { info = await api(`/meetings/${code}`); }
  catch (e) {
    return show(header(), h('div', { class: 'screen' }, h('div', { class: 'card' },
      h('h1', {}, 'Cannot join'), h('p', { class: 'sub' }, e.message),
      h('button', { class: 'btn block', onclick: () => { location.hash = ''; } }, 'Back'))));
  }
  const mic = h('input', { type: 'checkbox', checked: true });
  const cam = h('input', { type: 'checkbox', checked: true });
  const err = h('div', { class: 'err', role: 'alert' });
  const btn = h('button', { class: 'btn block', disabled: info.full, onclick: async () => {
    btn.disabled = true;
    S.meeting = true;
    try {
      await startMeeting({ root, code, info, user: S.user, iceServers: S.config.iceServers, mic: mic.checked, cam: cam.checked,
        onExit: (msg) => { S.meeting = null; location.hash = ''; if (msg) toast(msg, 6000); route(); } });
    } catch (e) { S.meeting = null; toast(e.message, 6000); route(); }
  } }, info.full ? 'Meeting is full' : 'Join now');

  show(header(), h('div', { class: 'screen' }, h('div', { class: 'card' },
    h('h1', {}, info.title),
    h('p', { class: 'sub' }, `${info.participants} of ${info.max} in the meeting${info.isHost ? ' · you are the host' : ''}`),
    h('label', { class: 'check' }, mic, 'Join with microphone on'),
    h('label', { class: 'check' }, cam, 'Join with camera on'),
    btn, err,
    h('p', { class: 'note' }, h('a', { href: '#', onclick: (e) => { e.preventDefault(); location.hash = ''; } }, 'Cancel')))));
}

boot();
