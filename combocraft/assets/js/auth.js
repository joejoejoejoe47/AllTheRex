// Morse Chess username/password sign-in, plus Legendary picture sign-in when the account is unlocked.
import { esc } from './fx.js?v=40';

let ctx = { api: null, toast: null, state: null, reload: null };

export function initAuth(c) {
  ctx = c;
  bindHeader();
  syncCoins(ctx.state);
}

export function syncCoins(state) {
  const el = document.getElementById('ccCoins');
  const btn = document.getElementById('btnAuth');
  if (!el || !btn) return;
  const m = state?.morse || {};
  const signed = !!m.signedIn;
  const coins = Number(m.coins || 0);
  el.hidden = !signed;
  if (signed) {
    el.innerHTML = `<img src="../chess/morse-coin.png" alt="" width="22" height="22" /> <b>${fmtCoins(coins)}</b>`;
    el.title = (m.morseName ? m.morseName + ' · ' : '') + coins.toLocaleString() + ' Morse coins';
  }
  btn.hidden = signed;
  btn.textContent = 'Sign in';
}

function fmtCoins(n) {
  if (n >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
  if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
  if (n >= 10000) return Math.round(n / 1000) + 'K';
  return String(n);
}

function bindHeader() {
  const btn = document.getElementById('btnAuth');
  if (btn && !btn._cc) {
    btn._cc = true;
    btn.onclick = () => openAuth('enter');
  }
  const coins = document.getElementById('ccCoins');
  if (coins && !coins._cc) {
    coins._cc = true;
    coins.onclick = () => {
      const m = ctx.state?.morse || {};
      ctx.toast((m.morseName ? m.morseName + ' · ' : '') + Number(m.coins || 0).toLocaleString() + ' Morse coins');
    };
  }
}

export function openAuth(mode = 'enter') {
  document.querySelectorAll('.overlay.cc-auth-ov').forEach((e) => e.remove());
  const o = document.createElement('div');
  o.className = 'overlay cc-auth-ov';
  const paint = (legendary) => {
    const box = o.querySelector('.cc-auth');
    box.classList.toggle('lg', !!legendary);
    box.innerHTML = legendary ? legendaryForm(mode) : simpleForm(mode);
    wireForm(o, () => paint(legendary));
  };
  o.innerHTML = `<div class="modal cc-auth" role="dialog" aria-label="Sign in"></div>`;
  document.body.appendChild(o);
  paint(!!ctx.state?.morse?.legendary);
  o.addEventListener('click', (e) => { if (e.target === o || e.target.closest('button.x')) o.remove(); });
}

function simpleForm(mode) {
  return `<button class="x" type="button" aria-label="Close">×</button>
    <div class="cc-auth-tabs" role="tablist">
      <button type="button" data-mode="enter" class="${mode === 'enter' ? 'on' : ''}">Sign in</button>
      <button type="button" data-mode="create" class="${mode === 'create' ? 'on' : ''}">Create account</button>
    </div>
    <p class="cc-auth-lead">${mode === 'create' ? 'Create a Morse Chess account with just a username and password. You’ll be signed in on ComboCraft and Morse Chess.' : 'Sign in with your Morse Chess username and password. That also signs you into Morse Chess.'}</p>
    <form class="cc-auth-form" data-mode="${mode}">
      <label for="ccUser">Username</label>
      <input id="ccUser" name="username" autocomplete="username" maxlength="20" required placeholder="YourUsername" />
      <label for="ccPass">Password</label>
      <input id="ccPass" name="password" type="password" autocomplete="${mode === 'create' ? 'new-password' : 'current-password'}" required placeholder="at least 8 characters" />
      <p class="cc-auth-err" role="alert"></p>
      <button type="submit" class="cc-auth-go">${mode === 'create' ? 'Create account' : 'Enter'}</button>
    </form>`;
}

function legendaryForm(mode) {
  return `<button class="x" type="button" aria-label="Close">×</button>
    <div class="mcl-auth-mini">
      <img class="mcl-auth-medal" src="../chess/legendary/screens/medal-gem.webp" alt="" />
      <p class="mcl-auth-kicker">The Morse Table</p>
      <div class="mcl-auth-stage">
        <div class="mcl-auth-card mcl-flop-in" data-mode="${mode}">
          <div class="mcl-auth-back" aria-hidden="true">
            <img src="../chess/legendary/screens/medal-gem.webp" alt="" />
            <span>The Morse Table</span>
          </div>
          <div class="mcl-auth-face">
            <div class="mcl-auth-tabs" role="tablist">
              <button type="button" data-mode="enter" class="${mode === 'enter' ? 'is-on' : ''}">Sign in</button>
              <button type="button" data-mode="create" class="${mode === 'create' ? 'is-on' : ''}">Create account</button>
            </div>
            <form class="mcl-auth-form cc-auth-form" data-mode="${mode}">
              <label for="ccUser">Username</label>
              <input id="ccUser" name="username" autocomplete="username" maxlength="20" required placeholder="IvoryRook" />
              <label for="ccPass">Password</label>
              <input id="ccPass" name="password" type="password" autocomplete="${mode === 'create' ? 'new-password' : 'current-password'}" required placeholder="at least 8 characters" />
              <p class="mcl-auth-err cc-auth-err" role="alert"></p>
              <button type="submit" class="mcl-auth-go">${mode === 'create' ? 'Create account' : 'Enter'}</button>
            </form>
          </div>
        </div>
      </div>
    </div>`;
}

function wireForm(overlay, repaint) {
  const form = overlay.querySelector('form.cc-auth-form');
  const err = overlay.querySelector('.cc-auth-err');
  overlay.querySelectorAll('[data-mode]').forEach((b) => {
    b.onclick = () => {
      const next = b.dataset.mode;
      form.dataset.mode = next;
      overlay.querySelectorAll('[data-mode]').forEach((x) => {
        x.classList.toggle('on', x.dataset.mode === next);
        x.classList.toggle('is-on', x.dataset.mode === next);
      });
      const go = form.querySelector('[type=submit]');
      if (go) go.textContent = next === 'create' ? 'Create account' : 'Enter';
      const lead = overlay.querySelector('.cc-auth-lead');
      if (lead) lead.textContent = next === 'create'
        ? 'Create a Morse Chess account with just a username and password. You’ll be signed in on ComboCraft and Morse Chess.'
        : 'Sign in with your Morse Chess username and password. That also signs you into Morse Chess.';
      const pass = form.querySelector('#ccPass');
      if (pass) pass.autocomplete = next === 'create' ? 'new-password' : 'current-password';
    };
  });
  const user = form.querySelector('#ccUser');
  let t = 0;
  if (user) {
    user.addEventListener('blur', () => probeAndMaybeFlip(user.value, overlay, form.dataset.mode, repaint));
    user.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => probeAndMaybeFlip(user.value, overlay, form.dataset.mode, repaint), 600);
    });
  }
  form.onsubmit = async (e) => {
    e.preventDefault();
    const mode = form.dataset.mode || 'enter';
    const username = (form.username.value || '').trim();
    const password = form.password.value || '';
    const go = form.querySelector('[type=submit]');
    if (go) go.disabled = true;
    if (err) err.textContent = '';
    try {
      const path = mode === 'create' ? 'auth/signup' : 'auth/signin';
      const d = await ctx.api(path, { username, password });
      if (ctx.state) {
        if (d.me) ctx.state.me = d.me;
        if (d.morse) ctx.state.morse = d.morse;
        if (d.prefs) ctx.state.prefs = d.prefs;
      }
      syncCoins(ctx.state);
      overlay.remove();
      if (ctx.toast) ctx.toast(mode === 'create' ? 'Account created. You’re signed in!' : 'Signed in as ' + (d.morse?.morseName || username) + '.');
      if (ctx.reload) await ctx.reload();
    } catch (ex) {
      if (err) err.textContent = ex.message || 'Could not sign in.';
    } finally {
      if (go) go.disabled = false;
    }
  };
}

async function probeAndMaybeFlip(username, overlay, mode, repaint) {
  if (!username || username.length < 8 || !ctx.api) return;
  try {
    const d = await ctx.api('auth/probe?username=' + encodeURIComponent(username));
    const box = overlay.querySelector('.cc-auth');
    if (!box) return;
    const wantLg = !!d.legendary;
    if (wantLg && !box.classList.contains('lg')) {
      box.classList.add('lg');
      box.innerHTML = legendaryForm(mode);
      wireForm(overlay, repaint);
      const u = box.querySelector('#ccUser');
      if (u) u.value = username;
    }
  } catch {}
}

export async function signOut() {
  try {
    const d = await ctx.api('auth/signout', {});
    if (ctx.state) ctx.state.morse = d.morse || { signedIn: false, coins: 0 };
    syncCoins(ctx.state);
    if (ctx.toast) ctx.toast('Signed out of Morse Chess.');
    if (ctx.reload) await ctx.reload();
  } catch (e) {
    if (ctx.toast) ctx.toast(e.message);
  }
}
