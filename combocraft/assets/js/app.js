// ComboCraft front end: routing, state, pages.
import { sfx, sound, loadFluent, ico, iconUrl, artHtml, esc, confetti, svg, shake, burst } from './fx.js?v=27';
import { storyHtml, storyPng } from './share.js?v=27';
import { applyMe, playChrome, bindPlayChrome, onCombineExtras, openRecipe, pageAchievements, rarityClass, shareDaily, copyInvite, dailyWin, showBadges, nameClass, nameVars } from './features.js?v=27';
import { initBg, syncPrefs } from './bg.js?v=27';

const BASE = (document.querySelector('base')?.getAttribute('href')) || '/';
const ROOT = new URL(BASE, location.href).pathname; // "/combocraft/" or "/"
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const view = $('#view');

const COLORS = {
  water: '#4da3f7', fire: '#f45b45', cat: '#a47aeb', meme: '#ffd23f', ai: '#35c9be', chaos: '#ff9838',
  money: '#6fd06b', love: '#ff8db5', politician: '#6b8cff', earth: '#2fb38a', wind: '#8fd3f4', pizza: '#f7b046',
};
const PALETTE = ['#4da3f7', '#f45b45', '#a47aeb', '#ffd23f', '#35c9be', '#ff9838', '#6fd06b', '#ff8db5', '#6b8cff', '#2fb38a', '#8fd3f4', '#f7b046', '#c084fc', '#f472b6', '#34d399', '#fbbf24', '#60a5fa', '#fb923c'];
function colorOf(el) {
  const k = el.name.toLowerCase();
  if (COLORS[k]) return COLORS[k];
  let h = 0;
  for (const ch of el.name) h = (h * 31 + ch.codePointAt(0)) >>> 0;
  return PALETTE[h % PALETTE.length];
}

const state = {
  me: null,
  elements: [],
  byId: new Map(),
  recent: [],
  slots: [null, null],
  busy: false,
  lastResult: null,
  newIds: new Set(),
  showAll: false,
  filter: '',
  daily: null,
  hints: null,
  progress: null,
  badges: [],
  referral: null,
  morse: null,
  run: null,
};

// ---------------- API ----------------
async function api(path, body) {
  const opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
  if (body !== undefined) {
    opts.method = 'POST';
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CC'] = '1';
    opts.body = JSON.stringify(body);
  }
  const r = await fetch(BASE + 'api/' + path, opts);
  let data = null;
  try { data = await r.json(); } catch {}
  if (!r.ok || !data || data.ok === false) {
    const err = new Error((data && data.error) || 'Something went wrong. Try again.');
    err.status = r.status;
    err.data = data;
    throw err;
  }
  return data;
}

function toast(msg, ms = 2400) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.add('on');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => t.classList.remove('on'), ms);
}

function setTheme(dark) {
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  $('meta[name="theme-color"]').setAttribute('content', dark ? '#0d0e12' : '#ffffff');
  try { localStorage.setItem('cc_theme', dark ? 'dark' : 'light'); } catch {}
}
const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';

let meP = null;
function loadMe() {
  if (!meP) meP = loadMeNow().finally(() => { meP = null; });
  return meP;
}
async function loadMeNow() {
  const d = await api('me');
  state.me = d.me;
  state.elements = d.elements;
  state.byId = new Map(d.elements.map((e) => [e.id, e]));
  state.recent = d.recent;
  applyMe(state, d);
  if (d.prefs) syncPrefs(d.prefs);
}

// ---------------- Router ----------------
const routes = {
  '': pageHero,
  play: pagePlay,
  daily: pagePlay,
  discoveries: pageGallery,
  leaderboard: pageLeaderboard,
  chat: pageChat,
  pricing: pagePricing,
  premium: pagePricing,
  achievements: () => pageAchievements({ api, state, view, toast }),
  speedrun: () => go('play', true),
  clash: pageClash,
};
let cleanup = null;
function currentRoute() {
  let p = location.pathname;
  if (p.startsWith(ROOT)) p = p.slice(ROOT.length);
  p = p.replace(/^\/+|\/+$/g, '');
  return p;
}
function go(path, replace = false) {
  const url = ROOT + path.replace(/^\//, '');
  if (replace) history.replaceState({}, '', url); else history.pushState({}, '', url);
  render();
  window.scrollTo(0, 0);
}
async function render() {
  if (cleanup) { try { cleanup(); } catch {} cleanup = null; }
  closeMenus();
  const r = currentRoute();
  const [head, arg] = r.split('/');
  document.body.classList.toggle('hero-mode', head === '');
  $$('[data-r]').forEach((a) => a.classList.toggle('on', a.dataset.r === head || (head === 'premium' && a.dataset.r === 'pricing')));
  setPageTitle('');
  if (head === 'e' && arg) return pageElement(parseInt(arg, 10));
  const fn = routes[head] || pageHero;
  await fn();
}
function setPageTitle(t) {
  const el = $('#pageTitle');
  el.textContent = t;
  el.classList.toggle('on', !!t);
}
document.addEventListener('click', (e) => {
  const a = e.target.closest('a[data-link]');
  if (!a || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') return;
  e.preventDefault();
  const href = a.getAttribute('href');
  go(href === './' ? '' : href);
});
addEventListener('popstate', render);

// ---------------- Hero (01) ----------------
async function pageHero() {
  document.title = 'ComboCraft — What if you could combine anything?';
  view.innerHTML = `
  <section class="hero">
    <div class="float f-fire"><img src="${BASE}assets/hero/fire.webp" alt=""></div>
    <div class="float f-robot"><img src="${BASE}assets/hero/robot.webp" alt=""></div>
    <div class="float f-heart"><img src="${BASE}assets/hero/heart.webp" alt=""></div>
    <div class="float f-cat"><img src="${BASE}assets/hero/cat.webp" alt=""></div>
    <div class="float f-dollar"><img src="${BASE}assets/hero/dollar.webp" alt=""></div>
    <div class="float f-swirl"><img src="${BASE}assets/hero/swirl.webp" alt=""></div>
    <div class="hero-inner">
      <h1>What if you could combine anything?</h1>
      <p>Mix any two ideas and invent something new. Discover endless combinations.</p>
      <a class="btn-primary" href="play" data-link id="startBtn">Start Combining</a>
    </div>
    <nav class="hero-links">
      <a href="discoveries" data-link>Discoveries</a><a href="leaderboard" data-link>Leaderboard</a><a href="chat" data-link>Chat</a><a href="pricing" data-link>Premium</a>
    </nav>
  </section>`;
  const floats = $$('.float', view);
  const onMove = (e) => {
    const x = e.clientX / innerWidth - 0.5, y = e.clientY / innerHeight - 0.5;
    floats.forEach((f, i) => { f.style.translate = `${x * (10 + i * 4)}px ${y * (10 + i * 4)}px`; });
  };
  addEventListener('pointermove', onMove);
  cleanup = () => removeEventListener('pointermove', onMove);
}

// ---------------- Play (02 / 03 / 05 / 08) ----------------
function elCard(el, extra = '') {
  const isNew = state.newIds.has(el.id) ? ' is-new' : '';
  const rar = ' ' + rarityClass(el);
  return `<button class="el${nameClass(el.name)}${isNew}${extra}${rar}" data-id="${el.id}" style="--c:${colorOf(el)};${nameVars(el.name)}" title="${esc(el.name)}${el.tagline ? ' — ' + esc(el.tagline) : ''}${el.rarity ? ' · ' + el.rarity : ''} — Double-click or long-press for the recipe">
    ${el.mineFirst ? '<span class="star" title="Your First Discovery">⭐</span>' : ''}
    <span class="i">${ico(el.emoji)}</span><span class="n">${esc(el.name)}</span></button>`;
}

async function pagePlay() {
  document.title = 'Play — ComboCraft';
  if (!state.me) {
    view.innerHTML = '<div class="wrap play"><div class="thinking" style="justify-content:center;margin-top:80px">Loading your elements <span class="dots"><i></i><i></i><i></i></span></div></div>';
    try { await loadMe(); } catch (e) { view.innerHTML = `<div class="wrap play"><div class="lb-empty"><b>Couldn't load the game</b>${esc(e.message)}</div></div>`; return; }
  }
  view.innerHTML = `
  <div class="wrap play">
    <div class="play-grid">
      <aside class="play-activity" id="playActivity" aria-label="Activity">
        <div class="act-head">
          <h2>Activity</h2>
          <a href="clash" data-link class="act-clash">Combo Clash</a>
        </div>
        <div class="act-live" id="actLive"></div>
        <div class="act-feed" id="actFeed"></div>
      </aside>
      <div class="play-main">
        ${playChrome(state)}
        <div class="sec-h el-h"><h2>Elements</h2><span class="count-chip" id="elCount"></span><span class="grow"></span>
          <label class="search">${svg.search}<input id="elSearch" placeholder="Search" autocomplete="off" value="${esc(state.filter)}"></label>
          <button class="mini-btn" id="btnRecipe" title="Double-click, long-press, or right-click a card">Recipe Book</button>
          <button class="mini-btn" id="toggleAll">${state.showAll ? 'Show less' : 'Show all'}</button></div>
        <div class="strip-wrap">
          <button class="arrow" id="arrL" aria-label="Scroll left">${svg.left}</button>
          <div class="strip${state.showAll ? ' all' : ''}" id="strip"></div>
          <button class="arrow" id="arrR" aria-label="Scroll right">${svg.right}</button>
        </div>
        <section class="combine" id="combine">
          <h2>Combine</h2>
          <div class="sub">Drag elements here to discover new combinations</div>
          <div class="slots" id="slots">
            <div class="slot" data-slot="0"></div>
            <div class="plus" id="plus">+</div>
            <div class="slot" data-slot="1"></div>
          </div>
          <div class="result-zone" id="resultZone"></div>
        </section>
        <section class="recent">
          <div class="sec-h"><h2>Recent Discoveries</h2><span class="grow"></span><a href="#" id="viewAll">View all ›</a></div>
          <div class="recent-row" id="recentRow"></div>
        </section>
        <div class="limit-bar" id="limitBar"></div>
      </div>
    </div>
  </div>
  <div class="sheet" id="sheet"></div>`;
  renderStrip();
  renderSlots();
  renderRecent();
  renderLimit();
  bindPlayChrome({ api, toast, state, go, openRecipe });
  window.__ccClashMod?.mountPlayActivity?.();
  if (state.lastResult && state.slots[0] && state.slots[1]) showResult(state.lastResult, false);

  const strip = $('#strip');
  $('#arrL').onclick = () => strip.scrollBy({ left: -strip.clientWidth * 0.8 });
  $('#arrR').onclick = () => strip.scrollBy({ left: strip.clientWidth * 0.8 });
  $('#elSearch').oninput = (e) => { state.filter = e.target.value; renderStrip(); };
  $('#toggleAll').onclick = () => { state.showAll = !state.showAll; strip.classList.toggle('all', state.showAll); $('#toggleAll').textContent = state.showAll ? 'Show less' : 'Show all'; };
  const btnRecipe = $('#btnRecipe');
  if (btnRecipe) btnRecipe.onclick = () => openRecipePicker();
  $('#viewAll').onclick = (e) => { e.preventDefault(); openCollection(); };
  $('#slots').addEventListener('click', (e) => {
    const s = e.target.closest('.slot.full');
    if (!s || state.busy) return;
    state.slots[+s.dataset.slot] = null;
    clearResult();
    renderSlots();
    sfx('pick');
  });
  $('#resultZone').addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]');
    if (act) return resultAction(act.dataset.act);
    if (e.target.closest('.result') && state.lastResult) useResult();
  });
  $('#sheet').addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]');
    if (act) return resultAction(act.dataset.act);
  });
  const offDrag = setupDrag();
  cleanup = () => { offDrag(); };
}

function visibleElements() {
  const f = state.filter.trim().toLowerCase();
  return f ? state.elements.filter((e) => e.name.toLowerCase().includes(f)) : state.elements;
}
function renderStrip() {
  const strip = $('#strip');
  if (!strip) return;
  const list = visibleElements();
  strip.innerHTML = list.map((e) => elCard(e)).join('') || '<div class="empty-recent">No elements match.</div>';
  const n = state.elements.length;
  const cnt = $('#elCount');
  if (cnt) cnt.textContent = `${n} found`;
}
function renderSlots() {
  $$('.slot').forEach((s) => {
    const i = +s.dataset.slot;
    const el = state.slots[i];
    s.className = 'slot';
    if (!el) {
      s.innerHTML = isDark() ? '<div class="big" style="font-size:34px;font-weight:300">+</div><div>Drop here</div>' : '<div class="dot"></div><div>Drop an element</div>';
    } else {
      s.classList.add('full');
      if (el.name.toLowerCase() === 'chaos' || i === 1) s.classList.add('chaos-tint');
      s.innerHTML = `<div class="big">${ico(el.emoji)}</div><div class="nm">${esc(el.name)}</div>`;
    }
  });
  $$('.el.picked').forEach((x) => x.classList.remove('picked'));
  state.slots.forEach((el) => { if (el) $$(`.el[data-id="${el.id}"]`).forEach((x) => x.classList.add('picked')); });
}
function renderRecent() {
  const row = $('#recentRow');
  if (!row) return;
  if (!state.recent.length) {
    row.innerHTML = '<div class="empty-recent">Your discoveries will show up here. Try <b>Water + Fire</b>!</div>';
    return;
  }
  row.innerHTML = state.recent.map((r, i) => `
    <div class="rc" data-i="${i}" title="${esc(r.inputs[0].name)} + ${esc(r.inputs[1].name)} = ${esc(r.result.name)}">
      <div class="eq">${ico(r.inputs[0].emoji)}<span class="op">+</span>${ico(r.inputs[1].emoji)}<span class="op">=</span>${ico(r.result.emoji)}</div>
      <div class="rname lt">${esc(r.result.name)}</div>
      <div class="dk"><div style="min-width:0"><div class="t1">${esc(r.result.name)}</div><div class="t2">${esc(r.inputs[0].name)} + ${esc(r.inputs[1].name)}</div></div><div class="ic">${ico(r.result.emoji)}</div></div>
    </div>`).join('');
  row.onclick = (e) => {
    const c = e.target.closest('.rc');
    if (!c) return;
    const r = state.recent[+c.dataset.i];
    openShare({ ...r.result, recipe: r.inputs }, r.result.mineFirst);
  };
}
function renderLimit() {
  const b = $('#limitBar');
  if (!b || !state.me) return;
  const left = Math.max(0, state.me.dailyLimit - state.me.usedToday);
  b.textContent = `${left} of ${state.me.dailyLimit} free combinations left today`;
}

function pickElement(el, slotIndex = null) {
  if (state.busy) return;
  if (state.slots[0] && state.slots[1]) {
    state.slots = [null, null];
    clearResult();
  }
  let i = slotIndex;
  if (i === null) i = state.slots[0] ? 1 : 0;
  state.slots[i] = el;
  renderSlots();
  const s = $(`.slot[data-slot="${i}"]`);
  s?.classList.add('fill-anim');
  sfx('pop');
  if (state.slots[0] && state.slots[1]) combineNow();
}

function clearResult() {
  state.lastResult = null;
  const z = $('#resultZone');
  if (z) z.innerHTML = '';
  $('#sheet')?.classList.remove('on');
  $$('.slot').forEach((s) => s.classList.remove('glow'));
}

async function combineNow() {
  const [a, b] = state.slots;
  state.busy = true;
  $$('.slot').forEach((s) => s.classList.add('glow'));
  sfx('whoosh');
  const z = $('#resultZone');
  z.innerHTML = '<div class="thinking">Combining <span class="dots"><i></i><i></i><i></i></span></div>';
  const minWait = new Promise((r) => setTimeout(r, 700));
  let res;
  try {
    res = await api('combine', { a: a.id, b: b.id, relay: true });
    if (res.pending) {
      z.innerHTML = '<div class="thinking">Asking the AI <span class="dots"><i></i><i></i><i></i></span></div>';
      res = await relay(res);
    }
    await minWait;
  } catch (e) {
    state.busy = false;
    await minWait;
    sfx('error');
    z.innerHTML = '';
    $$('.slot').forEach((s) => s.classList.remove('glow'));
    if (e.data && e.data.limit) return openLimit(e.message);
    toast(e.message);
    state.slots[1] = null;
    renderSlots();
    return;
  }
  state.busy = false;
  if (res.me) state.me = res.me;
  const r = res.result;
  const known = state.byId.get(r.id);
  const el = { ...r, mineFirst: r.mineFirst || known?.mineFirst };
  if (res.newForYou && !known) {
    state.elements.push(el);
    state.byId.set(el.id, el);
    state.newIds.add(el.id);
  }
  if (res.newForYou) {
    state.recent.unshift({ result: el, inputs: res.inputs });
    state.recent = state.recent.slice(0, 12);
  }
  state.lastResult = { el, first: res.first, newForYou: res.newForYou, inputs: res.inputs };
  renderStrip();
  renderSlots();
  renderRecent();
  renderLimit();
  if (res.newForYou) {
    const card = $(`#strip .el[data-id="${el.id}"]`);
    if (card) { card.classList.add('pop-in'); if (!state.showAll) card.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' }); }
  }
  showResult(state.lastResult, true, res.daily);
  onCombineExtras(res, { state, toast, shake, burst });
  if (res.result?.rarity === 'Legendary' && res.newForYou) {
    sfx('fanfare');
    legendaryPop(el, res.inputs);
  } else if (res.first) {
    sfx('fanfare');
    setTimeout(() => celebrate(el, res.inputs), 750);
  } else sfx(res.newForYou ? 'success' : 'known');
}

// Browser relay: the free AI is per-IP rate limited, so when the server's turn
// is used up the player's own browser asks, and the server checks the answer.
async function relay(p) {
  let content = null;
  try {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), 20000);
    const r = await fetch(p.relay.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(p.relay.body), signal: ctl.signal });
    clearTimeout(t);
    if (r.ok) content = await r.text();
  } catch {}
  return api('combine/resolve', { ticket: p.ticket, content });
}

function showResult(lr, animate, daily) {
  const { el, first, newForYou } = lr;
  const z = $('#resultZone');
  if (!z) return;
  const recipeTxt = `${esc(lr.inputs[0].name)} + ${esc(lr.inputs[1].name)}`;
  let note = first ? 'You discovered it first! 🏆' : newForYou ? 'A new combination discovered!' : 'You already have this one.';
  if (daily?.justSolved) {
    note = daily.justName
      ? `Daily Level ${daily.justLevel || '?'} solved — ${daily.justName}!`
      : 'Daily challenge solved!';
    if (daily.cleared || daily.clearedAll) note += ' Chain complete!';
    else if (daily.target?.name) note += ` Next: Make ${daily.target.name}.`;
  } else if (daily?.autoSolved?.length) {
    note = 'You already had this — daily level claimed!';
    if (daily.target?.name && !daily.cleared) note += ` Next: Make ${daily.target.name}.`;
  }
  z.innerHTML = `
    <div class="result ${rarityClass(el)}${animate ? '' : '" style="animation:none'}" title="Click to use it in your next combo">
      <div class="sparkles">${['10%;top:8%', '88%;top:14%', '-2%;top:60%', '96%;top:68%', '50%;top:-4%'].map((p, i) => `<i style="left:${p};animation-delay:${i * 0.2}s">✦</i>`).join('')}</div>
      ${first ? `<div class="badge-first seal sm">${svg.seal}</div>` : ''}
      <div class="art">${artHtml(el, BASE)}</div>
      <div class="rn">${esc(el.name)}</div>
      ${el.tagline ? `<div class="tg">${esc(el.tagline)}</div>` : ''}
      <div class="rar-pill ${rarityClass(el)}">${esc(el.rarity || 'Common')}</div>
    </div>
    <div class="result-note">${note}<span class="paw">${ico('🐾')}</span></div>
    <div class="result-actions">
      <button class="mini-btn" data-act="use">Use it ➜</button>
      <button class="mini-btn" data-act="share">${svg.upload.replace('<svg', '<svg width="15" height="15"')} Share card</button>
      <button class="mini-btn" data-act="chat">💬 Post to chat</button>
    </div>`;
  // Phones: slide-up sheet (05)
  const sh = $('#sheet');
  if (sh) {
    sh.innerHTML = `<div class="grab"></div><div class="st">${recipeTxt} = ${esc(el.name)}</div><div class="sart">${artHtml(el, BASE)}</div>
      <div class="stag">${first ? '🏆 First Discovery! ' : ''}${esc(el.tagline || '')}</div>
      <div class="result-actions"><button class="mini-btn" data-act="use">Use it ➜</button><button class="mini-btn" data-act="share">Share</button><button class="mini-btn" data-act="close">Close</button></div>`;
    if (matchMedia('(max-width: 720px)').matches) requestAnimationFrame(() => sh.classList.add('on'));
  }
}
function useResult() {
  const lr = state.lastResult;
  if (!lr) return;
  const el = state.byId.get(lr.el.id) || lr.el;
  state.slots = [null, null];
  clearResult();
  pickElement(el, 0);
}
function resultAction(act) {
  const lr = state.lastResult;
  if (!lr) return;
  if (act === 'use') return useResult();
  if (act === 'close') return $('#sheet')?.classList.remove('on');
  if (act === 'share') return openShare({ ...lr.el, recipe: lr.inputs }, lr.el.mineFirst || lr.first);
  if (act === 'chat') return shareToChat(lr.el);
}

// ---------------- Drag and drop + tap ----------------
function setupDrag() {
  let start = null, ghost = null, src = null, hold = null, dragging = false, srcEl = null, lastTap = null, pendingPick = 0;
  const isTouch = (e) => e.pointerType === 'touch';
  const blockScroll = (e) => { if (dragging) e.preventDefault(); };
  document.addEventListener('touchmove', blockScroll, { passive: false });

  function begin(e) {
    dragging = true;
    ghost = src.cloneNode(true);
    ghost.classList.add('ghost');
    ghost.classList.remove('pop-in', 'is-new', 'picked');
    const r = src.getBoundingClientRect();
    ghost.style.width = r.width + 'px';
    ghost.style.height = r.height + 'px';
    document.body.appendChild(ghost);
    move(e);
    sfx('pick');
    if (navigator.vibrate && isTouch(e)) navigator.vibrate(12);
  }
  function targetAt(x, y) {
    const t = document.elementFromPoint(x, y);
    return t ? (t.closest('.slot') || t.closest('#strip .el') || t.closest('.combine')) : null;
  }
  function move(e) {
    if (!ghost) return;
    ghost.style.left = e.clientX + 'px';
    ghost.style.top = e.clientY + 'px';
    $$('.slot.hot').forEach((s) => s.classList.remove('hot'));
    const t = targetAt(e.clientX, e.clientY);
    if (t && t.classList.contains('slot')) t.classList.add('hot');
  }
  function onDown(e) {
    const card = e.target.closest('#strip .el, .coll-grid .el, .recent-row .el');
    if (!card || e.button > 0) return;
    src = card;
    srcEl = state.byId.get(+card.dataset.id);
    start = { x: e.clientX, y: e.clientY, t: performance.now(), id: e.pointerId, elId: +card.dataset.id };
    dragging = false;
    hold = setTimeout(() => {
      if (!start || dragging) return;
      const id = start.elId;
      start = null;
      if (navigator.vibrate) navigator.vibrate(18);
      if (id) openRecipe(id, { api, toast, ico, artHtml, BASE });
    }, 520);
  }
  function onMove(e) {
    if (!start || e.pointerId !== start.id) return;
    const dx = e.clientX - start.x, dy = e.clientY - start.y;
    if (!dragging) {
      if (Math.hypot(dx, dy) > 8) {
        const held = performance.now() - start.t;
        if (isTouch(e) && held < 180) { clearTimeout(hold); start = null; return; }
        clearTimeout(hold);
        begin(e);
      }
      return;
    }
    move(e);
  }
  function onUp(e) {
    clearTimeout(hold);
    if (!start) return;
    const wasDrag = dragging;
    const s = start;
    start = null;
    dragging = false;
    if (!wasDrag) {
      if (Math.hypot(e.clientX - s.x, e.clientY - s.y) < 10 && srcEl) {
        const now = performance.now();
        if (lastTap && now - lastTap.t < 340 && lastTap.id === srcEl.id) {
          clearTimeout(pendingPick);
          pendingPick = 0;
          lastTap = null;
          if (navigator.vibrate) navigator.vibrate(12);
          openRecipe(srcEl.id, { api, toast, ico, artHtml, BASE });
        } else {
          lastTap = { t: now, id: srcEl.id };
          clearTimeout(pendingPick);
          const picked = srcEl;
          pendingPick = setTimeout(() => { pendingPick = 0; pickElement(picked); }, 280);
        }
      }
      return;
    }
    const t = targetAt(e.clientX, e.clientY);
    $$('.slot.hot').forEach((x) => x.classList.remove('hot'));
    const g = ghost;
    ghost = null;
    if (t && srcEl) {
      g.remove();
      if (t.classList.contains('slot')) {
        const i = +t.dataset.slot;
        if (state.slots[0] && state.slots[1]) { state.slots = [null, null]; clearResult(); }
        pickElement(srcEl, state.slots[i] ? i : i);
        return;
      }
      if (t.classList.contains('el') && t !== src) {
        const other = state.byId.get(+t.dataset.id);
        if (other) { state.slots = [null, null]; clearResult(); state.slots[0] = other; renderSlots(); pickElement(srcEl, 1); }
        return;
      }
      if (t.classList.contains('combine')) { pickElement(srcEl); return; }
    }
    const r = src.getBoundingClientRect();
    g.classList.add('back');
    g.style.left = r.left + r.width / 2 + 'px';
    g.style.top = r.top + r.height / 2 + 'px';
    setTimeout(() => g.remove(), 260);
  }
  function onCancel() { clearTimeout(hold); clearTimeout(pendingPick); start = null; dragging = false; if (ghost) { ghost.remove(); ghost = null; } }
  function onDbl(e) {
    const card = e.target.closest('#strip .el, .coll-grid .el, .recent-row .el, .sheet .el, .rb-pick .el');
    if (!card) return;
    e.preventDefault();
    clearTimeout(pendingPick);
    pendingPick = 0;
    lastTap = null;
    const id = +card.dataset.id;
    if (id) openRecipe(id, { api, toast, ico, artHtml, BASE });
  }
  document.addEventListener('pointerdown', onDown);
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onCancel);
  document.addEventListener('dblclick', onDbl);
  document.addEventListener('contextmenu', ctxOpen);
  function ctxOpen(e) {
    const card = e.target.closest('#strip .el, .coll-grid .el, .recent-row .el, .sheet .el, .rb-pick .el');
    if (!card) return;
    e.preventDefault();
    const id = +card.dataset.id;
    if (id) openRecipe(id, { api, toast, ico, artHtml, BASE });
  }
  return () => {
    document.removeEventListener('pointerdown', onDown);
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onCancel);
    document.removeEventListener('touchmove', blockScroll);
    document.removeEventListener('dblclick', onDbl);
    document.removeEventListener('contextmenu', ctxOpen);
  };
}

// ---------------- Celebration (10) ----------------
function celebrate(el, inputs) {
  const wrap = document.createElement('div');
  wrap.className = 'celebrate';
  const streak = state.me?.streak || 1;
  const rarity = el.rarity || 'Rare';
  const desc = el.tagline || `${inputs[0].name} meets ${inputs[1].name}. Nothing will ever be the same.`;
  if (rarity === 'Legendary') wrap.classList.add('cel-legendary');
  wrap.innerHTML = `
    <canvas class="confetti"></canvas>
    ${rarity === 'Legendary' ? '<div class="leg-banner">LEGENDARY</div>' : ''}
    <div class="cel-top">
      <span><img class="logo-light" src="${BASE}assets/logo/logo.png" alt="ComboCraft"><img class="logo-dark" src="${BASE}assets/logo/logo-dark.png" alt="ComboCraft"></span>
      <span style="display:flex;align-items:center"><span class="streak">You're on a ${streak}-day streak ${ico('🔥')}</span><button class="x" aria-label="Close">${svg.close}</button></span>
    </div>
    <div class="ribbon">${svg.ribbon}<div class="rt">You discovered it first!</div></div>
    <div class="cel-sub">This combo was never seen before. You beat everyone to it. ✨</div>
    <div class="cel-card">
      <div class="streaks"></div>
      <div class="txt">
        <div class="ne">NEW ELEMENT</div>
        <div class="nm">${esc(el.name)}</div>
        <div class="ln"></div>
        <div class="ds">${esc(inputs[0].name)} + ${esc(inputs[1].name)}.<br>${esc(desc)}</div>
        <div class="rar">✦ ${rarity}</div>
      </div>
      <div class="pic">${artHtml(el, BASE)}</div>
    </div>
    <div class="cel-actions">
      <button class="act share" data-a="share"><span class="bx">${svg.arrowShare}</span>Share</button>
      <button class="act copy" data-a="copy"><span class="bx">${svg.link}</span>Copy link</button>
      <button class="act dl" data-a="dl"><span class="bx">${svg.download}</span>Download</button>
      <button class="act share" data-a="friend"><span class="bx">${svg.arrowShare}</span>Challenge a friend</button>
    </div>
    <div class="cel-saved">${svg.lock} Your discovery is saved in your Collection.</div>
    ${state.me && !state.me.nickname ? `<form class="claim"><span>Put your name on the leaderboard:</span><input maxlength="20" placeholder="Pick a nickname" required><button>Claim it</button><span class="err"></span></form>` : '<div style="height:24px"></div>'}`;
  document.body.appendChild(wrap);
  const stop = confetti($('canvas', wrap), 5200);
  const full = { ...el, recipe: inputs };
  const close = () => { stop(); wrap.remove(); removeEventListener('keydown', onKey); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  addEventListener('keydown', onKey);
  $('.x', wrap).onclick = close;
  wrap.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-a]');
    if (!b) return;
    if (b.dataset.a === 'share') shareNative(full, true);
    if (b.dataset.a === 'copy') copyLink(full);
    if (b.dataset.a === 'dl') downloadPng(full, true);
    if (b.dataset.a === 'friend') copyInvite(state, toast, true);
  });
  const f = $('.claim', wrap);
  if (f) f.onsubmit = async (e) => {
    e.preventDefault();
    try {
      const d = await api('nickname', { nickname: $('input', f).value });
      state.me = d.me;
      f.innerHTML = `<span>Nice, <b>${esc(d.me.nickname)}</b>! You're on the leaderboard.</span>`;
    } catch (er) { $('.err', f).textContent = er.message; }
  };
}

// ---------------- Share card (04) ----------------
function linkFor(el) { return location.origin + ROOT + 'e/' + el.id; }
async function copyLink(el) {
  const url = linkFor(el);
  try { await navigator.clipboard.writeText(url); toast('Link copied!'); }
  catch { prompt('Copy this link:', url); }
}
async function downloadPng(el, first) {
  toast('Making your card…', 1500);
  const blob = await storyPng(el, { base: BASE, first });
  if (!blob) return toast('Could not make the image.');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'combocraft-' + el.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.png';
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  toast('Card downloaded!');
}
async function shareNative(el, first) {
  const url = linkFor(el);
  const text = el.recipe ? `${el.recipe[0].name} + ${el.recipe[1].name} = ${el.emoji} ${el.name}! Can you find it on ComboCraft?` : `${el.emoji} ${el.name} on ComboCraft`;
  try {
    if (navigator.share) {
      let files;
      try {
        const blob = await storyPng(el, { base: BASE, first });
        const file = new File([blob], 'combocraft.png', { type: 'image/png' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) files = [file];
      } catch {}
      await navigator.share(files ? { files, text: text + ' ' + url } : { title: 'ComboCraft', text, url });
      return;
    }
  } catch (e) { if (e && e.name === 'AbortError') return; }
  try { await navigator.clipboard.writeText(text + ' ' + url); toast('Copied! Paste it anywhere.'); }
  catch { prompt('Copy this:', text + ' ' + url); }
}
function openShare(el, first) {
  const m = modal(`<div class="share-wrap">${storyHtml(el, { base: BASE, first })}
    <div class="share-actions">
      <button class="mini-btn" data-s="copy">${svg.link.replace('<svg', '<svg width="15" height="15"')} Copy link</button>
      <button class="mini-btn" data-s="dl">${svg.download.replace('<svg', '<svg width="15" height="15"')} Download PNG</button>
      <button class="mini-btn" data-s="chat">💬 Post to chat</button>
    </div></div>`, { wide: true });
  m.box.style.width = 'auto';
  m.box.style.background = 'transparent';
  m.box.style.boxShadow = 'none';
  m.box.style.border = '0';
  m.box.addEventListener('click', (e) => {
    const s = e.target.closest('[data-s], [data-act="share"]');
    if (!s) return;
    if (s.dataset.act === 'share') shareNative(el, first);
    if (s.dataset.s === 'copy') copyLink(el);
    if (s.dataset.s === 'dl') downloadPng(el, first);
    if (s.dataset.s === 'chat') { m.close(); shareToChat(el); }
  });
}

// ---------------- Modals & menus ----------------
function modal(html, { wide = false } = {}) {
  const o = document.createElement('div');
  o.className = 'overlay';
  o.innerHTML = `<div class="modal${wide ? ' wide' : ''}"${wide ? ' style="width:auto;padding:0"' : ''}><button class="x" aria-label="Close">${svg.close}</button>${html}</div>`;
  document.body.appendChild(o);
  const box = $('.modal', o);
  const close = () => { o.remove(); removeEventListener('keydown', onKey); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  addEventListener('keydown', onKey);
  o.addEventListener('click', (e) => { if (e.target === o || e.target.closest('.x')) close(); });
  return { o, box, close };
}
function closeMenus() { $$('.menu').forEach((m) => m.remove()); }
function menuAt(btn, html) {
  const open = $('.menu');
  closeMenus();
  if (open && open.dataset.for === btn.id) return null;
  const m = document.createElement('div');
  m.className = 'menu';
  m.dataset.for = btn.id;
  m.innerHTML = html;
  document.body.appendChild(m);
  const r = btn.getBoundingClientRect();
  m.style.top = r.bottom + 8 + 'px';
  m.style.right = Math.max(8, innerWidth - r.right) + 'px';
  setTimeout(() => document.addEventListener('click', function off(e) { if (!m.contains(e.target) && e.target !== btn && !btn.contains(e.target)) { m.remove(); document.removeEventListener('click', off); } }), 0);
  return m;
}
function openLimit(msg) {
  const m = modal(`<h3>That's all for today ⏳</h3><p>${esc(msg)}</p><p>Premium (unlimited combinations) is coming soon.</p><div class="field"><a class="btn" href="pricing" data-link style="display:grid;place-items:center;text-decoration:none">See Premium</a><button class="btn gray" data-c>OK</button></div>`);
  m.box.addEventListener('click', (e) => { if (e.target.closest('[data-c], a')) m.close(); });
}
async function askNickname(reason = 'Pick a nickname so other players know who you are.') {
  return new Promise((resolve) => {
    const m = modal(`<h3>Choose a nickname</h3><p>${esc(reason)} No signup needed.</p>
      <form class="field"><input maxlength="20" placeholder="e.g. pixelpete" required autofocus value="${esc(state.me?.nickname || '')}"><button class="btn">Save</button></form><div class="err"></div>`);
    const f = $('form', m.box);
    setTimeout(() => $('input', f).focus(), 50);
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        const d = await api('nickname', { nickname: $('input', f).value });
        state.me = d.me;
        m.close();
        toast(`You're ${d.me.nickname} now!`);
        resolve(true);
      } catch (er) { $('.err', m.box).textContent = er.message; }
    };
    m.o.addEventListener('click', (e) => { if (e.target === m.o || e.target.closest('.x')) resolve(false); });
  });
}
async function ensureMe() { if (!state.me) await loadMe(); }

$('#btnGear').onclick = async (e) => {
  e.stopPropagation();
  try { await ensureMe(); } catch {}
  const me = state.me;
  const m = menuAt($('#btnGear'), `
    <div class="row">Dark mode <button class="toggle${isDark() ? ' on' : ''}" data-t="dark" aria-label="Dark mode"></button></div>
    <div class="row">Sound effects <button class="toggle${sound.on ? ' on' : ''}" data-t="sound" aria-label="Sound"></button></div>
    <hr>
    <div class="row"><span>Daily combinations<div class="small">${me ? `${me.usedToday} of ${me.dailyLimit} used today (free plan)` : 'Free plan'}</div></span></div>
    <div class="row btnrow" data-go="nick">Change nickname <span class="small">${esc(me?.nickname || 'not set')}</span></div>
    <div class="row btnrow" data-go="achievements">Achievements</div>
    <div class="row btnrow" data-go="clash">Combo Clash</div>
    <div class="row btnrow" data-go="invite">Invite a friend</div>
    ${state.morse?.available ? `<div class="row btnrow" data-go="morse">${state.morse.linked ? 'Morse Chess · linked' : 'Link Morse Chess'} <span class="small">${state.morse.linked ? '+' + (state.morse.earned || 0) + ' coins' : (state.morse.signedIn ? 'ready to link' : 'sign in on Morse first')}</span></div>` : ''}
    <div class="row btnrow" data-go="pricing">Premium <span class="small" style="color:var(--blue)">Coming soon</span></div>`);
  if (!m) return;
  m.addEventListener('click', (ev) => {
    const t = ev.target.closest('[data-t]');
    if (t) {
      if (t.dataset.t === 'dark') { setTheme(!isDark()); t.classList.toggle('on', isDark()); if (currentRoute() === 'play') { renderSlots(); } }
      else { sound.on = !sound.on; t.classList.toggle('on', sound.on); if (sound.on) sfx('pop'); }
      return;
    }
    const g = ev.target.closest('[data-go]');
    if (!g) return;
    closeMenus();
    if (g.dataset.go === 'nick') askNickname();
    else if (g.dataset.go === 'invite') copyInvite(state, toast, false);
    else if (g.dataset.go === 'morse') linkMorse();
    else go(g.dataset.go);
  });
};
$('#btnMe').onclick = async (e) => {
  e.stopPropagation();
  try { await ensureMe(); } catch (er) { return toast(er.message); }
  const me = state.me;
  const found = state.elements.filter((x) => !x.base).length;
  const m = menuAt($('#btnMe'), `
    <div class="me-head"><div class="avatar">${ico(me.avatar)}</div><div><b>${esc(me.nickname || 'Anonymous crafter')}</b><div class="small">${me.nickname ? 'Playing without signup' : 'No signup needed'}</div></div></div>
    <div class="stats"><div><b>${found}</b><span>Discovered</span></div><div><b>${me.firsts}</b><span>Firsts</span></div><div><b>${me.streak || 0}🔥</b><span>Day streak</span></div></div>
    <hr>
    <div class="row btnrow" data-go="coll">My collection <span class="small">${found} items</span></div>
    <div class="row btnrow" data-go="nick">${me.nickname ? 'Change nickname' : 'Set a nickname'}</div>
    <div class="row btnrow" data-go="lb">Leaderboard</div>`);
  if (!m) return;
  m.addEventListener('click', (ev) => {
    const g = ev.target.closest('[data-go]');
    if (!g) return;
    closeMenus();
    if (g.dataset.go === 'coll') openCollection();
    if (g.dataset.go === 'nick') askNickname();
    if (g.dataset.go === 'lb') go('leaderboard');
  });
};
async function openCollection() {
  await ensureMe();
  const mine = state.elements.filter((e) => !e.base);
  const m = modal(`<h3>My collection</h3><p>${mine.length} discovered · ${state.me.firsts} First Discoveries ⭐. Tap one to share it.</p>
    <div class="coll-grid">${mine.map((e) => elCard(e)).join('') || '<div class="empty-recent">Nothing yet — go combine something!</div>'}</div>`);
  m.box.style.width = 'min(760px, 100%)';
  m.box.addEventListener('click', (e) => {
    const c = e.target.closest('.el');
    if (!c) return;
    const el = state.byId.get(+c.dataset.id);
    m.close();
    api('element/' + el.id).then((d) => openShare(d.element, el.mineFirst)).catch(() => openShare(el, el.mineFirst));
  });
}
async function shareToChat(el) {
  try {
    await ensureMe();
    if (!state.me.nickname && !(await askNickname('Pick a nickname to post in chat.'))) return;
    await api('chat', { body: '', elementId: el.id });
    toast('Shared in Chat! 💬');
  } catch (e) { toast(e.message); }
}

// ---------------- Leaderboard (06) ----------------
async function pageLeaderboard() {
  document.title = 'Leaderboard — ComboCraft';
  setPageTitle('Leaderboard');
  view.innerHTML = '<div class="wrap lb"><div class="thinking" style="justify-content:center;margin-top:60px">Loading <span class="dots"><i></i><i></i><i></i></span></div></div>';
  let d;
  try { d = await api('leaderboard'); } catch (e) { view.innerHTML = `<div class="wrap lb"><div class="lb-empty"><b>Couldn't load the leaderboard</b>${esc(e.message)}</div></div>`; return; }
  const medal = ['gold', 'silver', 'bronze'];
  const rows = d.rows.map((r, i) => {
    const best = r.best;
    const combo = best ? (best.recipe ? `${esc(best.recipe[0].name)} + ${esc(best.recipe[1].name)} = ${esc(best.name)}` : esc(best.name)) : '—';
    const cls = i < 3 ? ` r${i + 1}` : '';
    const ti = i === 0 ? svg.trophy : i === 1 ? svg.trophyGray : i === 2 ? svg.trophyBronze : svg.star;
    return `<div class="lb-row${cls}${r.id === d.me ? ' me' : ''}" data-i="${i}">
      ${i < 3 ? svg.medal(medal[i]) : '<span></span>'}
      <div class="rk">${r.rank}.</div>
      <div class="who"><b>${esc(r.nickname)}</b><span>${ti}<b>${r.firsts}</b> first discover${r.firsts === 1 ? 'y' : 'ies'}</span></div>
      <div class="vr"></div>
      <div class="best"><div class="lbl">best combo:</div><div class="cb">${combo}</div></div>
    </div>`;
  }).join('');
  view.innerHTML = `<div class="wrap lb">
    ${d.mine ? `<div class="lb-mine">You're <b>#${d.mine.rank}</b> with ${d.mine.firsts} first discoveries.</div>` : ''}
    ${rows || `<div class="lb-empty"><b>No First Discoveries yet</b>Be the first name on the board — find a combo nobody has made.<br><br><a class="btn-primary" style="height:52px;font-size:17px" href="play" data-link>Start Combining</a></div>`}
  </div>`;
  view.onclick = (e) => {
    const row = e.target.closest('.lb-row');
    if (!row) return;
    const r = d.rows[+row.dataset.i];
    if (r.best) openShare(r.best, true);
  };
  cleanup = () => { view.onclick = null; };
}

// ---------------- Gallery (07) ----------------
async function pageGallery() {
  document.title = 'Discoveries — ComboCraft';
  const sort = sessionStorage.getItem('cc_sort') || 'latest';
  view.innerHTML = `<div class="wrap gal">
    <div class="gal-head"><div><h1>Discoveries</h1><p>Hilarious combos crafted by the community. Click any card to dive in.</p></div>
      <div class="gal-tools"><button class="pill-btn" id="submitCombo">${svg.sparkle} Submit a Combo</button>
      <div class="select"><select id="sortSel" aria-label="Sort"><option value="latest">Latest</option><option value="popular">Most combined</option><option value="featured">Featured</option></select></div></div></div>
    <div class="gal-grid" id="galGrid"><div class="gal-more">Loading…</div></div>
  </div>`;
  $('#sortSel').value = sort;
  $('#sortSel').onchange = (e) => { sessionStorage.setItem('cc_sort', e.target.value); load(e.target.value); };
  $('#submitCombo').onclick = submitCombo;
  let items = [];
  async function load(s) {
    try { items = (await api('gallery?sort=' + s)).items; } catch (e) { $('#galGrid').innerHTML = `<div class="gal-more">${esc(e.message)}</div>`; return; }
    $('#galGrid').innerHTML = items.map((it, i) => {
      const t = it.recipe ? `${esc(it.recipe[0].name)} + ${esc(it.recipe[1].name)} =<br>${esc(it.name)}` : esc(it.name);
      const g = it.label === 'FEATURED' ? (i % 3) : it.label === 'STAFF PICK' ? 3 : it.label === 'POPULAR' ? 4 : it.label === 'COMMUNITY' && it.art ? 5 : (i % 6);
      const shareIcon = g === 1 ? svg.share : svg.upload;
      return `<div class="gcard g${g}" data-i="${i}" role="button" tabindex="0">
        <span class="lab">${esc(it.label || 'COMMUNITY')}</span>
        <div class="gart">${artHtml(it, BASE)}</div>
        <div class="gt"><div class="t">${t}</div><div class="s">${esc(it.tagline || '')}${it.discoveredBy ? ` · by ${esc(it.discoveredBy)}` : it.label ? ' · Not discovered yet!' : ''}</div></div>
        <button class="gs" data-share="${i}" aria-label="Share">${shareIcon}</button>
      </div>`;
    }).join('') || '<div class="gal-more">No discoveries yet. Be the first!</div>';
  }
  $('#galGrid').onclick = (e) => {
    const sh = e.target.closest('[data-share]');
    const card = e.target.closest('.gcard');
    if (!card) return;
    const it = items[+card.dataset.i];
    if (sh) { e.stopPropagation(); return shareNative(it, !!it.discoveredBy); }
    openShare(it, !!it.discoveredBy);
  };
  load(sort);
}
async function submitCombo() {
  try { await ensureMe(); } catch (e) { return toast(e.message); }
  const mine = state.elements.filter((e) => !e.base);
  if (!mine.length) { toast('Make a discovery first!'); return go('play'); }
  const m = modal(`<h3>Submit a combo</h3><p>Share one of your discoveries with everyone in Chat.</p>
    <div class="pick-list">${mine.slice().reverse().map((e) => `<button data-id="${e.id}"><span class="ic">${ico(e.emoji)}</span><span><b>${esc(e.name)}</b>${e.mineFirst ? '<small>⭐ Your First Discovery</small>' : `<small>${esc(e.tagline || '')}</small>`}</span></button>`).join('')}</div>`);
  m.box.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-id]');
    if (!b) return;
    m.close();
    await shareToChat(state.byId.get(+b.dataset.id));
  });
}

// ---------------- Chat (11) ----------------
async function pageChat() {
  document.title = 'Chat — ComboCraft';
  setPageTitle('Chat');
  try { await ensureMe(); } catch {}
  view.innerHTML = `<div class="chat">
    <div class="chat-main"><div class="msgs" id="msgs"><div class="chat-empty">Loading chat…</div></div>
      <form class="composer" id="composer"><div class="comp-box">
        <button type="button" class="att" id="attach" title="Share a discovery" aria-label="Share a discovery">🧩</button>
        <input id="chatIn" maxlength="240" placeholder="Share a discovery..." autocomplete="off">
        <button class="btn-send" id="sendBtn">Send</button></div></form></div>
    <aside class="online"><h3>Online players</h3><div id="onlineList"></div></aside>
  </div>`;
  let lastId = 0, msgs = [], reactions = {}, timer = null, alive = true;
  const box = $('#msgs');
  const nearBottom = () => box.scrollHeight - box.scrollTop - box.clientHeight < 80;
  function msgHtml(m) {
    const rx = (reactions[m.id] || []).map((r) => `<button class="rx${r.mine ? ' mine' : ''}" data-rx="${m.id}" data-e="${esc(r.emoji)}">${ico(r.emoji)}${r.n > 1 ? `<small>${r.n}</small>` : ''}</button>`).join('');
    const el = m.element;
    const disc = el ? `<div class="dcard" data-el="${m.id}"><span class="ic">${ico(el.emoji)}</span><div><div class="eqt">${el.recipe ? `${esc(el.recipe[0].name)} + ${esc(el.recipe[1].name)} = ` : ''}${esc(el.name)}</div>${el.tagline ? `<div class="ts">${esc(el.tagline)}</div>` : ''}</div></div>` : '';
    const t = new Date(m.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    return `<div class="msg" data-id="${m.id}"><div class="avatar" style="background:${avatarBg(m.avatar)}">${ico(m.avatar)}</div><div style="min-width:0">
      <div class="nm">${esc(m.nickname)}<time>${t}</time></div>
      ${m.body ? `<div class="bubble">${esc(m.body)}</div>` : ''}${disc}
      <div class="reacts">${rx}<button class="rx-add" data-add="${m.id}" aria-label="React">☺+</button></div></div></div>`;
  }
  function paint(stick) {
    box.innerHTML = msgs.length ? msgs.map(msgHtml).join('') : '<div class="chat-empty">No messages yet. Say hi or share a discovery! 👋</div>';
    if (stick) box.scrollTop = box.scrollHeight;
  }
  async function poll(first = false) {
    try {
      const d = await api('chat' + (lastId ? '?after=' + lastId : ''));
      if (!alive) return;
      const stick = first || nearBottom();
      if (d.messages.length) { msgs = msgs.concat(d.messages).slice(-150); lastId = msgs[msgs.length - 1].id; }
      const rxChanged = JSON.stringify(d.reactions) !== JSON.stringify(reactions);
      reactions = d.reactions || {};
      if (first || d.messages.length || rxChanged) paint(stick);
      $('#onlineList').innerHTML = d.online.map((p) => `<div class="op"><div class="avatar" style="background:${avatarBg(p.avatar)}">${ico(p.avatar)}</div>${esc(p.nickname)}</div>`).join('') || '<div class="small" style="color:var(--muted)">Nobody else right now.</div>';
    } catch (e) { if (first) box.innerHTML = `<div class="chat-empty">${esc(e.message)}</div>`; }
    if (alive) timer = setTimeout(poll, document.hidden ? 12000 : 4000);
  }
  poll(true);
  $('#composer').onsubmit = async (e) => {
    e.preventDefault();
    const inp = $('#chatIn');
    const body = inp.value.trim();
    if (!body) return;
    if (!state.me?.nickname && !(await askNickname('Pick a nickname to chat.'))) return;
    $('#sendBtn').disabled = true;
    try { await api('chat', { body }); inp.value = ''; clearTimeout(timer); await poll(); box.scrollTop = box.scrollHeight; }
    catch (er) { toast(er.message); }
    $('#sendBtn').disabled = false;
  };
  $('#attach').onclick = submitCombo;
  box.onclick = async (e) => {
    const rx = e.target.closest('[data-rx]');
    const add = e.target.closest('[data-add]');
    const pick = e.target.closest('[data-pick]');
    const dc = e.target.closest('[data-el]');
    if (rx || pick) {
      const id = +(rx ? rx.dataset.rx : pick.dataset.pick), emoji = rx ? rx.dataset.e : pick.dataset.e;
      try { await api('chat/react', { id, emoji }); clearTimeout(timer); await poll(); } catch (er) { toast(er.message); }
      return;
    }
    if (add) {
      $$('.rx-pick', box).forEach((p) => p.remove());
      const p = document.createElement('span');
      p.className = 'rx-pick';
      p.innerHTML = ['😂', '🔥', '🤯', '❤️', '👏', '😮'].map((x) => `<button data-pick="${add.dataset.add}" data-e="${x}">${ico(x)}</button>`).join('');
      add.after(p);
      return;
    }
    if (dc) {
      const m = msgs.find((x) => x.id === +dc.dataset.el);
      if (m?.element) openShare(m.element, !!m.element.discoveredBy && m.element.discoveredBy === m.nickname);
    }
  };
  cleanup = () => { alive = false; clearTimeout(timer); };
}
function avatarBg(a) {
  const m = { '🐱': '#f6e7d6', '🦊': '#ffe0c7', '🐼': '#e9eaee', '🐸': '#d9f3d2', '🐙': '#fbd9e6', '🦄': '#efe1fb', '🐧': '#dfe9f7', '🦉': '#efe3d2', '🐯': '#ffe7b8', '🐨': '#e4e6ea', '🐵': '#f1e1cf', '🦖': '#d6f2e2', '🐳': '#d6ecfb', '🐝': '#fff1b8', '🦁': '#ffe6bf', '🐰': '#fbe3ee' };
  return m[a] || '#eef0f4';
}

// ---------------- Pricing (09) ----------------
function pagePricing() {
  document.title = 'Pricing — ComboCraft';
  view.innerHTML = `<div class="wrap pricing">
    <h1>Choose your plan</h1>
    <div class="pr-div"><i></i><b></b><i></i></div>
    <div class="plans">
      <div class="plan free">
        <div class="pi">${svg.gift}</div><h2>Free</h2><hr><div class="price">$0</div><hr>
        <ul><li>${svg.clock}Limited daily combinations</li><li>${svg.megaphone}Ads</li></ul>
        <button class="cta" id="getStarted">Get started</button>
      </div>
      <div class="plan pro">
        <div class="rec">${svg.starWhite} Recommended</div>
        <h2>Premium</h2><hr><div class="price">$4<small>/mo</small></div><hr>
        <ul><li>${svg.check}Unlimited combinations</li><li>${svg.check}No ads</li><li>${svg.check}Exclusive themes</li><li>${svg.check}Custom elements</li><li>${svg.check}Private rooms</li></ul>
        <button class="cta" id="upgrade">Upgrade</button>
      </div>
    </div>
    <div class="pr-note">Free players get 500 combinations a day. Premium isn't on sale yet, so no payment is ever taken here.</div>
  </div>`;
  $('#getStarted').onclick = () => go('play');
  $('#upgrade').onclick = () => {
    const m = modal(`<h3>Premium is coming soon ✨</h3><p>Unlimited combinations, no ads, exclusive themes, custom elements and private rooms are on the way. Nothing is charged today — there's no payment on this site yet.</p><div class="field"><button class="btn" data-c style="flex:1">Got it</button></div>`);
    m.box.addEventListener('click', (e) => { if (e.target.closest('[data-c]')) m.close(); });
  };
}

// ---------------- Shared element page (/e/123) ----------------
async function pageElement(id) {
  view.innerHTML = '<div class="wrap"><div class="thinking" style="justify-content:center;margin-top:60px">Loading <span class="dots"><i></i><i></i><i></i></span></div></div>';
  let el;
  try { el = (await api('element/' + id)).element; } catch (e) { view.innerHTML = `<div class="wrap"><div class="lb-empty"><b>Combo not found</b>${esc(e.message)}</div></div>`; return; }
  document.title = `${el.name} — ComboCraft`;
  const recipe = el.recipe ? `${esc(el.recipe[0].name)} + ${esc(el.recipe[1].name)}` : '';
  view.innerHTML = `<div class="wrap epage">
    ${storyHtml(el, { base: BASE, first: !!el.discoveredBy, small: matchMedia('(max-width: 720px)').matches })}
    <div class="side"><h1>${ico(el.emoji)} ${esc(el.name)}</h1>
      <p>${el.tagline ? esc(el.tagline) + '<br>' : ''}${el.discoveredBy ? `First discovered by <b>${esc(el.discoveredBy)}</b>.` : 'Nobody has discovered this one yet. It could be you!'}</p>
      <p>${recipe ? `Can you make it yourself? Hint: it takes <b>${el.depth}</b> step${el.depth === 1 ? '' : 's'}.` : ''}</p>
      <a class="btn-primary" href="play" data-link style="height:56px;font-size:18px">Start Combining</a></div></div>`;
  view.onclick = (e) => {
    if (e.target.closest('[data-act="share"]')) shareNative(el, !!el.discoveredBy);
  };
  cleanup = () => { view.onclick = null; };
}


function openRecipePicker() {
  const list = (state.elements || []);
  const wrap = document.createElement('div');
  wrap.className = 'overlay';
  wrap.innerHTML = `<div class="modal book rb-pick">
    <button class="x" aria-label="Close">${svg.close}</button>
    <h3>Recipe Book</h3>
    <p class="muted">Pick a card to see how you made it and what it can turn into. You can also double-click, double-tap, long-press, or right-click any element card.</p>
    <div class="coll-grid">${list.map((e) => elCard(e)).join('') || '<p class="muted">Nothing to look up yet.</p>'}</div>
  </div>`;
  document.body.appendChild(wrap);
  const close = () => wrap.remove();
  wrap.querySelector('.x').onclick = close;
  wrap.addEventListener('click', (e) => {
    if (e.target === wrap) close();
    const c = e.target.closest('.el');
    if (!c) return;
    close();
    openRecipe(+c.dataset.id, { api, toast, ico, artHtml, BASE });
  });
}

// ---------------- Boot ----------------
loadFluent(BASE).then(() => render());
// Warm up the player record in the background so Play opens instantly.
if (currentRoute() !== 'play') loadMe().catch(() => {});
import('./clash.js?v=27').then((m) => {
  window.__ccClashMod = m;
  m.startInbox({ api, state, view, toast, go, ico, artHtml, BASE, esc, sfx, askNickname, ensureMe });
}).catch(() => {});

function legendaryPop(el, inputs) {
  celebrate(el, inputs);
  shake(0.75);
  burst('Legendary');
}

async function linkMorse() {
  try {
    if (!state.morse?.signedIn) {
      toast('Sign in at Morse Chess on this site, then come back and tap Link. We never see your password.');
      window.open('/chess/', '_blank', 'noopener');
      return;
    }
    const d = await api('morse/link', {});
    state.morse = d;
    toast(d.linked ? 'Linked to ' + (d.morseName || 'your Morse account') + '. First Discoveries now earn coins!' : 'Could not link right now.');
  } catch (e) { toast(e.message); }
}

async function pageClash() {
  const ctx = { api, state, view, toast, go, ico, artHtml, BASE, esc, sfx, askNickname, ensureMe };
  try {
    const m = window.__ccClashMod || await import('./clash.js?v=27');
    window.__ccClashMod = m;
    cleanup = () => { try { m.stopMatch(); } catch {} };
    return m.pageClash(ctx);
  } catch (e) {
    view.innerHTML = `<div class="wrap"><div class="lb-empty"><b>Combo Clash is almost ready</b>${esc(e.message)}</div></div>`;
  }
}

window.__cc = { state, go };

initBg({ api, toast, state, BASE });
