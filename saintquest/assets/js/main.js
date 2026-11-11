import * as api from './api.js';
import { $, $$, el, toast, openModal, sparkle, thumb, fmt, closeAllModals } from './ui.js';
import { state, setMe, onChange, def, homeSaint } from './state.js';
import { initHome, showSaint, cheer, layout, pause, onDoor, onNear, freeze, placeAtDoor, doorRect, saintScreen, preloadWalk } from './home.js';
import { onAction, isTouch } from './walkinput.js';
import { enterChurch, doorTransition, inChurch } from './church.js';
import { openStore } from './store.js';
import { openTrade, closeTrade } from './trade.js';
import { openSaint, openPlayer } from './saintview.js';
import { openAdd } from './addcard.js';
import { openNotes } from './notes.js';
import { openBattleSelect } from './battle.js';
import { openDeed } from './deeds.js';
import { openCredits, openPrintCards } from './extras.js';

window.__sq = state; // read-only debug view for tests

async function boot() {
  const cat = await fetch((window.SQ_BASE || '') + '/assets/data/saints.json').then((r) => r.json());
  state.list = cat.saints;
  for (const s of cat.saints) state.catalog[s.key] = s;
  initHome();
  showSaint(def('francis'));
  wire();
  wireWalking();
  loadLeaderboard();
  let sess;
  try {
    sess = await api.session();
  } catch (e) {
    toast(e.status === 503 ? 'Saint Quest is waking up. Please try again in a minute.' : e.message, true, 8000);
    showAuth();
    return;
  }
  if (sess.me) enter(sess.me); else showAuth();
  handleHash();
  window.addEventListener('hashchange', handleHash);
  setInterval(() => { if (state.me && !document.hidden) refresh(true); }, 45000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && state.me) refresh(true); });
}

function handleHash() {
  const h = location.hash;
  if (h === '#credits') { openCredits(); history.replaceState(null, '', location.pathname + location.search); }
  else if (h === '#cards' && state.me) { openPrintCards(); history.replaceState(null, '', location.pathname + location.search); }
  const card = new URLSearchParams(location.search).get('card');
  if (card && state.me) { openAdd(card); history.replaceState(null, '', location.pathname); }
}

function enter(me) {
  $('#auth').hidden = true;
  $('#auth').replaceChildren();
  setMe(me);
  $('#profile').hidden = false;
  $('#deeds-pill').hidden = false;
  $('#saint-plate').hidden = false;
  layout();
  preloadWalk();
  if (me.questions && me.questions.some((q) => q.role === 'owner' && q.status === 'pending')) {
    setTimeout(() => openNotes(), 600);
  }
  handleHash();
}

export async function refresh(quiet = false) {
  try {
    const { me } = await api.get('me');
    setMe(me);
  } catch (e) {
    if (e.status === 401) { state.me = null; showAuth(); }
    else if (!quiet) toast(e.message, true);
  }
  loadLeaderboard();
}
window.__sqRefresh = refresh;
state.refresh = refresh;

onChange(({ me }) => {
  if (!me) return;
  $('#p-name').textContent = me.username;
  $('#p-level').textContent = 'Level ' + me.level.level;
  $('#p-meter').style.width = Math.round((me.level.into / me.level.need) * 100) + '%';
  $('#p-meter-text').textContent = `${fmt(me.level.into)} / ${fmt(me.level.need)}`;
  $('#p-wallet').textContent = fmt(me.virtue);
  $('#p-deeds').textContent = fmt(me.goodDeeds);
  const best = me.saints[0];
  $('#avatar-img').src = best ? thumb(best.key, 'head') : '';
  const unseen = me.unseen + me.questions.filter((q) => (q.role === 'owner' && (q.status === 'pending' || q.status === 'awaiting_photo'))).length;
  $('#bell-count').hidden = unseen === 0;
  $('#bell-count').textContent = unseen > 9 ? '9+' : String(unseen);
  for (const b of $$('.villager')) b.classList.toggle('done', me.deedsToday.includes(b.dataset.deed));
  const left = 3 - me.deedsToday.length;
  $('#quest-title').textContent = left > 0 ? 'Quest: Help the villagers' : 'Quest complete for today!';
  $('#quest-text').textContent = left > 0 ? `Tap a villager with a “!” or walk up and press E (${me.deedsToday.length}/3 today).` : 'Come back tomorrow, or win a Holy Battle for more virtue.';
  renderHomeSaint();
});

function renderHomeSaint() {
  const s = homeSaint();
  if (!s) return;
  const d = def(s.key);
  showSaint(d);
  $('#saint-name').textContent = d.name;
  const n = state.me.saints.length;
  const extra = s.status === 'listed' ? ' · for trade' : s.status === 'frozen' ? ' · frozen (card check)' : '';
  $('#saint-sub').textContent = (state.homeIndex === 0 ? 'Your top saint' : `Saint ${state.homeIndex + 1} of ${n}`) + ` · ★ ${s.rating}` + extra;
  $('#prev-saint').hidden = $('#next-saint').hidden = n < 2;
  $('#signpost .sign-tip').textContent = n > 1 ? 'Next saint' : 'Get more saints';
}

function nextSaint(step = 1) {
  if (!state.me) return;
  const n = state.me.saints.length;
  if (n < 2) { toast('You have one saint so far. Visit the Store or add a saint card to get more!'); return; }
  state.homeIndex = (state.homeIndex + step + n) % n;
  renderHomeSaint();
}

async function loadLeaderboard() {
  try {
    const { top } = await api.get('leaderboard');
    const ol = $('#lb-list');
    ol.replaceChildren();
    if (!top.length) { ol.append(el('li.lb-empty', { text: 'No champions yet. Be the first!' })); return; }
    top.slice(0, 3).forEach((p, i) => {
      ol.append(el('li', {}, el('button', { type: 'button', on: { click: () => openPlayer(p.username) }, title: `See ${p.username}'s saints` },
        el('span.medal.m' + (i + 1), { text: String(i + 1) }),
        el('img.lb-face', { src: p.saint ? thumb(p.saint, 'head') : '', alt: '' }),
        el('span.lb-name', { text: p.username }),
        el('span.lb-pts', { text: fmt(p.points) }))));
    });
    if (top.length > 3) {
      ol.append(el('li', {}, el('button.tiny-link', { type: 'button', style: { display: 'block', textAlign: 'center', border: 0, background: 'none' }, text: 'See top 10', on: { click: () => openTop10(top) } })));
    }
  } catch { /* the board is decoration if the API is down */ }
}

function openTop10(top) {
  openModal(el('div', {}, el('h2', { text: '👑 Top 10 saints keepers' }),
    el('ol', { style: { paddingLeft: '0', listStyle: 'none', display: 'grid', gap: '6px' } }, top.map((p, i) => el('li', {}, el('button.sr-item', { type: 'button', on: { click: () => openPlayer(p.username) } },
      el('span.medal.' + (i < 3 ? 'm' + (i + 1) : 'mx'), { text: String(i + 1) }), el('img', { src: p.saint ? thumb(p.saint, 'head') : '', alt: '' }),
      el('span', { style: { flex: '1' } }, p.username, el('small', { text: `Level ${p.level}` })), el('b', { text: fmt(p.points) })))))), { narrow: true, label: 'Top 10' });
}

function showAuth() {
  $('#profile').hidden = true;
  $('#deeds-pill').hidden = true;
  $('#saint-plate').hidden = true;
  const box = $('#auth');
  box.hidden = false;
  let mode = 'signup';
  const err = el('p.err', { role: 'alert' });
  const user = el('input.input', { name: 'username', autocomplete: 'username', required: true, minlength: 3, maxlength: 24, placeholder: 'e.g. SaintlySam' });
  const pass = el('input.input', { name: 'password', type: 'password', autocomplete: 'new-password', required: true, minlength: 8, placeholder: 'At least 8 characters' });
  const submit = el('button.btn.blue', { type: 'submit', style: { width: '100%', marginTop: '12px' } });
  const toggle = el('button.tiny-link', { type: 'button', style: { fontSize: '14px', marginTop: '10px' } });
  const title = el('h2', { style: { margin: '0 0 4px' } });
  const sync = () => {
    title.textContent = mode === 'signup' ? 'Create your account' : 'Welcome back!';
    submit.textContent = mode === 'signup' ? 'Start my quest ✨' : 'Log in';
    toggle.textContent = mode === 'signup' ? 'Already have an account? Log in' : 'New here? Create an account';
    pass.autocomplete = mode === 'signup' ? 'new-password' : 'current-password';
    err.textContent = '';
  };
  toggle.addEventListener('click', () => { mode = mode === 'signup' ? 'login' : 'signup'; sync(); });
  const form = el('form', { id: 'auth-form', on: { submit: async (e) => {
    e.preventDefault();
    submit.disabled = true;
    err.textContent = '';
    try {
      const r = await api.post(mode === 'signup' ? 'auth/signup' : 'auth/login', { username: user.value, password: pass.value });
      enter(r.me);
      if (mode === 'signup') { toast('Welcome, ' + r.me.username + '! St. Francis is your first saint.'); sparkle(innerWidth / 2, innerHeight / 2); }
      loadLeaderboard();
    } catch (ex) { err.textContent = ex.message; }
    submit.disabled = false;
  } } },
  el('label.field', { text: 'Username' }), user, el('label.field', { text: 'Password' }), pass, err, submit);
  box.replaceChildren(el('div.auth-card', {}, el('div.brand-wrap', {}, el('h1.brand', { text: 'Saint Quest' }), el('small', { text: 'Collect saints · do good deeds · win Holy Battles' })), title,
    el('p.muted', { text: 'Pick a username and a password. No email needed.' }), form, toggle, el('p.muted', { style: { fontSize: '12px', marginTop: '10px' } }, el('a', { href: '#credits', text: 'Credits & 3D model licenses' }))));
  sync();
  setTimeout(() => user.focus(), 50);
}

function wire() {
  $('#signpost').addEventListener('click', () => (state.me ? (state.me.saints.length > 1 ? nextSaint(1) : openStore()) : null));
  $('#edge-next').addEventListener('click', () => nextSaint(1));
  $('#next-saint').addEventListener('click', () => nextSaint(1));
  $('#prev-saint').addEventListener('click', () => nextSaint(-1));
  const openHome = () => { const s = homeSaint(); if (s) { cheer(); openSaint(s.id); } };
  $('#saint-canvas').addEventListener('click', openHome);
  $('#saint-canvas').addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openHome(); } });
  $('#avatar').addEventListener('click', () => state.me && openPlayer(state.me.username));
  $('#store-btn').addEventListener('click', () => openStore());
  $('#battle-btn').addEventListener('click', () => openBattleSelect());
  $('#trade-btn').addEventListener('click', () => ($('#trade-drop').hidden ? openTrade() : closeTrade()));
  $('#add-btn').addEventListener('click', () => openAdd());
  $('#bell-btn').addEventListener('click', () => openNotes());
  $('#quest-banner').addEventListener('click', () => { const v = $$('.villager').find((b) => !b.classList.contains('done')); if (v) v.click(); });
  for (const b of $$('.villager')) b.addEventListener('click', () => openDeed(b.dataset.deed));
  $('#logout-btn').addEventListener('click', async () => { try { await api.post('auth/logout'); } catch { /* ignore */ } closeAllModals(); closeTrade(); state.me = null; showAuth(); });
  $('#leaderboard').addEventListener('click', (e) => { if (window.matchMedia('(max-width: 820px), (max-height: 520px) and (orientation: landscape)').matches && !e.target.closest('button')) $('#leaderboard').classList.toggle('open'); });
  // search
  const input = $('#search');
  const results = $('#search-results');
  let timer = null, seq = 0;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (!q) { results.hidden = true; return; }
    timer = setTimeout(async () => {
      const my = ++seq;
      try {
        const r = await api.get('search', { q });
        if (my !== seq) return;
        renderSearch(r, q);
      } catch (e) { results.hidden = false; results.replaceChildren(el('p.err', { text: e.message })); }
    }, 200);
  });
  $('#search-form').addEventListener('submit', (e) => { e.preventDefault(); results.hidden = true; openStore(input.value.trim()); });
  document.addEventListener('pointerdown', (e) => { if (!e.target.closest('#search-form')) results.hidden = true; });
  input.addEventListener('focus', () => { if (input.value.trim() && results.childElementCount) results.hidden = false; });
}

function renderSearch(r, q) {
  const results = $('#search-results');
  const out = [];
  const close = () => { results.hidden = true; };
  if (r.store.length) {
    out.push(el('h4', { text: 'Store saints' }));
    for (const k of r.store.slice(0, 6)) {
      const d = def(k);
      out.push(el('button.sr-item', { type: 'button', on: { click: () => { close(); openStore(q, k); } } }, el('img', { src: thumb(k, 'head'), alt: '' }), el('span', {}, d.name, el('small', { text: `${d.patron} · ✦ ${fmt(d.price)}` }))));
    }
  }
  if (r.listings.length) {
    out.push(el('h4', { text: 'Saints for trade' }));
    for (const l of r.listings.slice(0, 6)) {
      out.push(el('button.sr-item', { type: 'button', on: { click: () => { close(); openSaint(l.saintId); } } }, el('img', { src: thumb(l.key, 'head'), alt: '' }), el('span', {}, l.name, el('small', { text: `from ${l.seller} · ✦ ${fmt(l.price)}` }))));
    }
  }
  if (r.players.length) {
    out.push(el('h4', { text: 'Players' }));
    for (const p of r.players) out.push(el('button.sr-item', { type: 'button', on: { click: () => { close(); openPlayer(p.username); } } }, el('span', { text: '🙂', style: { fontSize: '24px' } }), el('span', {}, p.username, el('small', { text: 'Level ' + p.level }))));
  }
  if (!out.length) out.push(el('p.muted', { text: `No saints found for “${q}”.` }));
  results.replaceChildren(...out);
  results.hidden = false;
}

// ---------- walking: villagers (E), church doors ----------
let nearV = null;
function wireWalking() {
  const hint = $('#walk-hint');
  const help = $('#walk-help');
  if (isTouch()) help.innerHTML = 'Push the joystick up to walk to the church, down to walk back';
  let helpShown = false;
  const showHelp = () => {
    if (helpShown || !state.me) return;
    helpShown = true;
    help.hidden = false;
    setTimeout(() => help.classList.add('gone'), 7000);
    setTimeout(() => { help.hidden = true; }, 7800);
  };
  setTimeout(showHelp, 1500);
  const placeHint = () => {
    if (!nearV || hint.hidden) return;
    const p = saintScreen();
    hint.style.left = p.x + 'px';
    hint.style.top = (p.y - 10 - 0.0) + 'px';
  };
  onNear((v) => {
    nearV = v && state.me && !state.me.deedsToday.includes(v.deed) ? v : null;
    if (!nearV) { hint.hidden = true; return; }
    hint.replaceChildren(el('kbd', { text: isTouch() ? 'Tap' : 'E' }), `Help ${nearV.name}`);
    hint.hidden = false;
  });
  const helpNear = () => { if (!nearV || !state.me) return false; const v = nearV; hint.hidden = true; openDeed(v.deed); return true; };
  hint.addEventListener('click', helpNear);
  onAction(() => helpNear());
  (function follow() { placeHint(); requestAnimationFrame(follow); })();
  onDoor(async () => {
    if (!state.me || inChurch()) return;
    const s = homeSaint();
    const d = s ? def(s.key) : def('francis');
    freeze(true);
    closeTrade();
    hint.hidden = true;
    let fx = null;
    try {
      fx = await doorTransition(doorRect());
      pause(true);
      await enterChurch(d, {
        fx,
        onLeave: () => { placeAtDoor(); pause(false); freeze(false); layout(); },
      });
    } catch (e) {
      console.warn('church failed', e);
      if (fx) fx.remove();
      toast('The church doors are stuck. Please try again.', true);
      placeAtDoor(); pause(false); freeze(false);
    }
  });
}

export { pause as pauseHome, nextSaint };

boot().catch((e) => { console.error(e); toast('Saint Quest could not start: ' + e.message, true, 10000); });
