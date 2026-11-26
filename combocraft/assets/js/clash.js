// Combo Clash — real-time PvP. Polled against a server-authoritative PHP match.
import { rarityClass, nameClass, nameVars } from './features.js?v=43';

let inboxTimer = 0;
let matchTimer = 0;
let shownInvite = new Set();
let ctx = null;
let attackMode = false;
let burstMode = false;
let pick = [null, null];
let selectedMode = 'duel';
const EXTRA_MODE_LIVE = ['gift', 'pile', 'telephone', 'boss', 'shelf'];
const EXTRA_MODES = [
  { id: 'gift', tag: 'New', title: 'Poison Gift', blurb: 'Every combine makes two cards. Keep one. The other goes to your opponent, aimed at you.' },
  { id: 'pile', tag: 'New', title: 'The Pile', blurb: 'One shared card mutates by itself. Slap a card on it to steer. Theme hits score. Sludge hurts whoever touched it last.' },
  { id: 'telephone', tag: 'New', title: 'Telephone', blurb: 'Four seats, one card. You only see the last pass, add one ingredient, and send it on. The full chain is read out at the end.' },
  { id: 'boss', tag: 'New', title: 'World Boss', blurb: 'Both sides feed the same giant card. Whoever lands the killing combine keeps that boss in their book forever.' },
  { id: 'shelf', tag: 'New', title: 'Stolen Shelf', blurb: "You don't play your twelve cards. You get twenty seconds with a stranger's collection from yesterday." },
].filter((m) => EXTRA_MODE_LIVE.includes(m.id));
let boom = null;
function heatMeter(src) {
  const { esc } = ctx;
  const c = src && (src.closeness != null ? src.closeness : src.breakdown?.closeness);
  if (c == null) return '';
  const heat = (src.heat || src.breakdown?.heat || (c >= 0.90 ? 'On Fire' : c >= 0.70 ? 'Hot' : c >= 0.4 ? 'Warm' : 'Cold'));
  const pct = Math.max(0, Math.min(100, Math.round(Number(c) * 100)));
  const cls = String(heat).toLowerCase().replace(/\s+/g, '-');
  return `<div class="rl-heat ${cls}" title="How close that combo was to the target">
    <span class="rl-heat-lab">${esc(heat)}</span>
    <span class="rl-heat-track"><i style="width:${pct}%"></i></span>
  </div>`;
}
function lastHeat(plays) {
  const list = plays || [];
  for (let i = list.length - 1; i >= 0; i--) {
    const p = list[i];
    if (p && (p.closeness != null || p.breakdown?.closeness != null)) return p;
  }
  return null;
}

export function startInbox(c) {
  ctx = c;
  if (inboxTimer) return;
  pollInbox();
  inboxTimer = setInterval(pollInbox, 2000);
}

export async function pageClash(c) {
  ctx = c;
  startInbox(c);
  const { view, state, go, toast, askNickname, ensureMe, api, esc } = c;
  document.title = 'Combo Clash — ComboCraft';
  try { await ensureMe(); } catch {}
  const route = location.pathname.replace(new URL(c.BASE, location.href).pathname, '').replace(/^\/+|\/+$/g, '');
  const parts = route.split('/');
  const matchId = parts[1] && parts[1].startsWith('m') ? parts[1] : '';
  const partyMatchId = parts[1] && parts[1].startsWith('g') ? parts[1] : '';
  const params = new URLSearchParams(location.search);
  const inviteId = params.get('invite') || params.get('c') || '';

  let inbox = null;
  try { inbox = await api('clash/inbox'); } catch { inbox = { incoming: [], outgoing: [], match: null }; }
  if (inbox.nickname && state.me) state.me.nickname = inbox.nickname;
  ctx.state.clashInbox = inbox;

  if (inbox.needName || !state.me?.nickname) {
    view.innerHTML = `<div class="wrap"><div class="rl-panel rl-namepick">
      <div class="rl-kicker">Combo Clash</div>
      <h1 class="rl-title">Pick a display name first</h1>
      <p>Other players will see this when you challenge them, and on the “I beat @you” cards.</p>
      <button class="rl-play" id="ccName"><span>Choose a name</span></button>
    </div></div>`;
    $('#ccName').onclick = async () => { await askNickname(); if (state.me?.nickname) pageClash(ctx); };
    return;
  }

  if (inviteId) {
    try {
      const d = await api('clash/invite/open', { id: inviteId });
      if (d.self) toast('This is your invite link. Send it to a friend!');
      else if (d.challenge) showInvitePopup(d.challenge, true);
    } catch (e) { toast(e.message); }
    history.replaceState({}, '', ctx.BASE + 'clash');
  }

  if (matchId) return showMatch(matchId);
  if (partyMatchId) return showPartyMatch(partyMatchId);

  if (inbox.match) return go('clash/' + inbox.match.id, true);
  if (inbox.partyMatch) return go('clash/' + inbox.partyMatch.id, true);

  await renderLobby(inbox);
}

async function renderLobby(inbox) {
  const { view, api, toast, go, esc, ico, state } = ctx;
  let boards = { wins: { rows: [] }, rare: { rows: [] }, daily: { rows: [], day: '' } };
  try {
    const [w, r, d] = await Promise.all([
      api('clash/board?kind=wins'),
      api('clash/board?kind=rare'),
      api('clash/board?kind=daily'),
    ]);
    boards = { wins: w, rare: r, daily: d };
  } catch {}

  const party = inbox.party;
  const me = state.me || {};
  const rank = inbox.rank || me.rank || { tier: 'Bronze', tierShort: 'B', mmr: 600, level: 1, title: 'Rookie', xpInto: 0, xpNext: 100 };
  const xpPct = Math.max(0, Math.min(100, Math.round(((rank.xpInto || 0) / (rank.xpNext || 100)) * 100)));
  const modes = [
    { id: 'duel', tag: 'Ranked', title: '1v1 Duel', blurb: 'Best of 3. Build a loadout and outscore a rival.' },
    { id: 'p2', tag: 'Party', title: '2v2 Party', blurb: 'Team up, hit the target, and advance together.' },
    { id: 'p3', tag: 'Party', title: '3v3 Party', blurb: 'Bigger parties, three 90-second sessions.' },
    { id: 'ai', tag: 'Practice', title: 'vs AI', blurb: '1v1 or Party vs AI. Easy, Medium or Hard — starts instantly.' },
    { id: 'priv', tag: 'Invite', title: 'Private Match', blurb: 'Send a challenge link. They have 5 seconds to accept.' },
    ...EXTRA_MODES,
  ];
  const row = (rows, line) => (rows || []).length
    ? `<ol>${rows.slice(0, 6).map((r, i) => `<li><span><i class="rk">${r.rank || i + 1}</i> ${line(r)}</span><span>${r._right || ''}</span></li>`).join('')}</ol>`
    : `<div class="rl-empty">${icoBox()} No battles yet. <button type="button" class="linkish" data-first>Be the first!</button></div>`;

  view.innerHTML = `
    <div class="rl-lobby lobby-has-replay">
      <div class="clash-replay" id="ccReplay" aria-hidden="true"></div>
      <div class="rl-kicker">Online arena</div>
      <div class="rl-hero-row">
        <div>
          <h1 class="rl-title">Combo Clash</h1>
          <div class="rl-panel rl-banner">
            <div class="rl-av">${ico(me.avatar || '🐱')}</div>
            <div class="rl-who">
              <div class="name">${esc(me.nickname || 'You')}</div>
              <div class="meta">${esc(rank.title || 'Rookie')} · Level ${rank.level || 1}</div>
              <div class="rl-xp"><i style="width:${xpPct}%"></i></div>
              <div class="rl-xp-lab">${rank.xpInto || 0} / ${rank.xpNext || 100} XP to Level ${(rank.level || 1) + 1}</div>
            </div>
            <div class="rl-rank">
              <div class="rl-badge">${esc(rank.tierShort || 'B')}</div>
              <b>${esc(rank.tier || 'Bronze')}</b>
              <em>${rank.mmr ?? 600} MMR</em>
            </div>
          </div>
        </div>
        <div class="play-col">
          <button class="rl-play" id="ccPlay"><span>PLAY</span></button>
        </div>
      </div>
      <div class="rl-modes">
        ${modes.map((m) => `<button type="button" class="rl-panel rl-mode${selectedMode === m.id ? ' on' : ''}" data-mode="${m.id}"><div class="tag">${m.tag}</div><h3>${m.title}</h3><p>${m.blurb}</p></button>`).join('')}
      </div>
      <form class="rl-chal" id="ccChal">
        <input id="ccNick" maxlength="20" placeholder="Challenge a username" autocomplete="off" />
        <button class="btn" type="submit">Challenge</button>
      </form>
      <div id="ccQueue" class="clash-queue" ${party?.queued ? '' : 'hidden'}>${party?.queued ? '<div class="thinking">Searching for a match…</div><button class="mini-btn" id="ccCancelQ">Cancel</button>' : ''}</div>
      <div class="rl-boards">
        <section class="rl-panel rl-board"><h2>Most wins</h2>${row(boards.wins.rows, (r) => `${esc(r.name)}` ).replace(/<span><\/span>/, '')}</section>
      </div>
    </div>`;

  // boards properly
  const boardHtml = (title, rows, rightFn, nameFn) => {
    const body = (rows || []).length
      ? `<ol>${rows.slice(0, 6).map((r, i) => `<li><span><i class="rk">${r.rank || i + 1}</i> ${nameFn(r)}</span><span>${rightFn(r)}</span></li>`).join('')}</ol>`
      : `<div class="rl-empty">${icoBox()} No battles yet. <button type="button" class="linkish" data-first>Be the first!</button></div>`;
    return `<section class="rl-panel rl-board"><h2>${esc(title)}</h2>${body}</section>`;
  };
  const boardsEl = view.querySelector('.rl-boards');
  boardsEl.innerHTML = [
    boardHtml('Most wins', boards.wins.rows, (r) => `${r.wins} win${r.wins === 1 ? '' : 's'}`, (r) => esc(r.name)),
    boardHtml('Daily tournament', boards.daily.rows, (r) => `${r.points} pts`, (r) => esc(r.name)),
    boardHtml('Rarest winning combos', boards.rare.rows, (r) => esc(r.winner || ''), (r) => `${r.emoji ? ico(r.emoji) + ' ' : ''}${esc(r.name)}`),
  ].join('');

  view.querySelectorAll('[data-mode]').forEach((b) => {
    b.onclick = () => { selectedMode = b.dataset.mode; renderLobby(inbox); };
  });
  const play = () => {
    if (selectedMode === 'duel') return joinQueue();
    if (selectedMode === 'ai') return openAiPicker();
    if (selectedMode === 'priv') return makeInvite();
    if (EXTRA_MODE_LIVE.includes(selectedMode)) return openAiPicker(false, selectedMode);
    // party modes
    if (!party) return openPartyUp(inbox);
    return clickReady();
  };
  $('#ccPlay').onclick = play;
  const cq = $('#ccCancelQ'); if (cq) cq.onclick = () => cancelReady();
  document.querySelectorAll('[data-first]').forEach((b) => { b.onclick = () => openAiPicker(); });
  ensurePartyBar(party);
  startReplayBg();
  if (party?.needsName) promptPartyName();
  if (party?.queued) watchPartyQueue();
  $('#ccChal').onsubmit = async (e) => {
    e.preventDefault();
    const nick = $('#ccNick').value.trim();
    if (!nick) return;
    try {
      const d = await api('clash/challenge', { nickname: nick });
      if (d.match) return go('clash/' + d.match.id);
      toast('Challenge sent to ' + nick + ' — they have 5 seconds to accept.');
      startOutgoingWatch(d.challenge);
    } catch (err) { toast(err.message); }
  };
}

function boardBlock(title, icon, rows, line, sub = '') {
  const { esc } = ctx;
  const body = (rows || []).length
    ? `<ol>${rows.map((r, i) => `<li><span class="rk">${r.rank || i + 1}</span>${line(r)}</li>`).join('')}</ol>`
    : `<div class="lb-empty">${icoBox()}<div>No battles yet.</div><button type="button" class="linkish" data-first>Be the first!</button></div>`;
  return `<section class="clash-board"><h2>${icon}<span>${esc(title)}</span></h2>${sub ? `<div class="cb-sub">${esc(sub)}</div>` : ''}${body}</section>`;
}

function icoSvg(inner, cls = '') {
  return `<svg class="cl-ico ${cls}" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;
}
function icoInfo() {
  return icoSvg('<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><circle cx="12" cy="8" r=".8" fill="currentColor" stroke="none"/>', 'cl-ico-info');
}
function icoPeople() {
  return icoSvg('<path d="M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1"/><circle cx="9" cy="7" r="3"/><path d="M22 19v-1a4 4 0 0 0-3-3.87"/><path d="M16 3.13a3 3 0 0 1 0 5.74"/>');
}
function icoLink() {
  return icoSvg('<path d="M10 13a5 5 0 0 0 7.07 0l1.41-1.41a5 5 0 0 0-7.07-7.07L10 5.93"/><path d="M14 11a5 5 0 0 0-7.07 0L5.5 12.4a5 5 0 0 0 7.07 7.07L14 18.07"/>');
}
function icoBot() {
  return icoSvg('<rect x="5" y="9" width="14" height="10" rx="2"/><path d="M12 9V6"/><circle cx="9" cy="13" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="13" r="1" fill="currentColor" stroke="none"/><path d="M8 19v2"/><path d="M16 19v2"/>');
}
function icoTrophy() {
  return icoSvg('<path d="M8 5h8v5a4 4 0 0 1-8 0V5z"/><path d="M8 6H5a3 3 0 0 0 3 5"/><path d="M16 6h3a3 3 0 0 1-3 5"/><path d="M12 14v3"/><path d="M8 20h8"/>', 'cl-ico-purple');
}
function icoCal() {
  return icoSvg('<rect x="4" y="6" width="16" height="14" rx="2"/><path d="M8 4v4"/><path d="M16 4v4"/><path d="M4 10h16"/>', 'cl-ico-blue');
}
function icoGem() {
  return icoSvg('<path d="M6 9l6 11 6-11-4-4H10L6 9z"/><path d="M6 9h12"/><path d="M10 5l2 4 2-4"/>', 'cl-ico-purple');
}
function icoBox() {
  return icoSvg('<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.5 8.5 12 13l8.5-4.5"/><path d="M12 13v8"/>', 'cl-ico-box');
}

function clashOverlay(inner, extraClass = '') {
  document.querySelectorAll('.overlay.cc-overlay').forEach((el) => el.remove());
  const o = document.createElement('div');
  o.className = 'overlay cc-overlay';
  o.innerHTML = `<div class="modal ${extraClass}" role="dialog">${inner}</div>`;
  document.body.appendChild(o);
  const box = o.querySelector('.modal');
  const close = () => o.remove();
  o.addEventListener('click', (e) => { if (e.target === o || e.target.closest('button.x')) close(); });
  return { o, box, close };
}

async function openAiPicker(preferParty = false, mode = '') {
  const { api, toast, go } = ctx;
  const party = ctx.state.clashInbox?.party;
  const inParty = !!(party && (party.members || []).length);
  const extra = EXTRA_MODES.find((m) => m.id === mode);
  // Party bar / explicit Party vs AI → party. Otherwise party members default to Party vs AI; solo players get 1v1.
  let kind = extra ? 'duel' : (preferParty || inParty ? 'party' : 'duel');
  const { box, close } = clashOverlay(`<button class="x" type="button" aria-label="Close">×</button>
    <h3>${extra ? esc(extra.title) + ' vs AI' : 'Challenge AI'}</h3>
    ${extra ? `<p class="ai-copy">${esc(extra.blurb)} Pick a difficulty — it starts now.</p>` : `<div class="ai-kinds" role="tablist">
      <button type="button" data-kind="duel" class="${kind === 'duel' ? 'on' : ''}">1v1 vs AI</button>
      <button type="button" data-kind="party" class="${kind === 'party' ? 'on' : ''}">Party vs AI</button>
    </div>
    <p class="ai-copy">${kind === 'party'
      ? 'Your whole party plays the 3×90s team-target match against a same-sized bot team. No waiting — it starts now.'
      : 'Play a best-of-3 against a bot. Easy is slower, Hard chains and attacks.'}</p>`}
    <div class="ai-diffs">
      <button type="button" data-lvl="easy"><b>Easy</b><span>Slower mixes, a gentle start</span></button>
      <button type="button" data-lvl="medium"><b>Medium</b><span>A fair, timed fight</span></button>
      <button type="button" data-lvl="hard"><b>Hard</b><span>Fast chains and counter-attacks</span></button>
    </div>`, 'cc-ai-modal');
  const copy = box.querySelector('.ai-copy');
  const setKind = (next) => {
    kind = next;
    box.querySelectorAll('[data-kind]').forEach((b) => b.classList.toggle('on', b.dataset.kind === kind));
    if (copy) {
      copy.textContent = kind === 'party'
        ? 'Your whole party plays the 3×90s team-target match against a same-sized bot team. No waiting — it starts now.'
        : 'Play a best-of-3 against a bot. Easy is slower, Hard chains and attacks.';
    }
  };
  box.querySelectorAll('[data-kind]').forEach((b) => { b.onclick = () => setKind(b.dataset.kind); });
  box.querySelectorAll('[data-lvl]').forEach((btn) => {
    btn.onclick = async () => {
      box.querySelectorAll('[data-lvl]').forEach((b) => { b.disabled = true; });
      try {
        const path = (!extra && kind === 'party') ? 'clash/party/ai' : 'clash/ai';
        const d = await api(path, { level: btn.dataset.lvl, mode: extra ? extra.id : undefined });
        close();
        if (d.party) {
          ctx.state.clashInbox = { ...(ctx.state.clashInbox || {}), party: d.party };
          ensurePartyBar(d.party);
        }
        if (d.match) go('clash/' + d.match.id);
        else toast('Could not start the bot match. Try again.');
      } catch (e) {
        box.querySelectorAll('[data-lvl]').forEach((b) => { b.disabled = false; });
        toast(e.message);
      }
    };
  });
}

async function joinQueue() {
  const { api, toast, go } = ctx;
  const box = $('#ccQueue');
  if (box) { box.hidden = false; box.innerHTML = `<div class="thinking">Looking for a player…</div><button class="mini-btn" id="ccCancelQ">Cancel</button>`; $('#ccCancelQ').onclick = cancelQueue; }
  try {
    const d = await api('clash/queue', {});
    if (d.match) return go('clash/' + d.match.id);
    watchQueue();
  } catch (e) { toast(e.message); if (box) box.hidden = true; }
}

async function cancelQueue() {
  try { await ctx.api('clash/queue/cancel', {}); } catch {}
  const box = $('#ccQueue');
  if (box) box.hidden = true;
  if (window.__ccQ) { clearInterval(window.__ccQ); window.__ccQ = 0; }
}

function watchQueue() {
  if (window.__ccQ) clearInterval(window.__ccQ);
  window.__ccQ = setInterval(async () => {
    try {
      const d = await ctx.api('clash/inbox');
      if (d.match) { clearInterval(window.__ccQ); window.__ccQ = 0; ctx.go('clash/' + d.match.id); }
    } catch {}
  }, 1000);
}

function startOutgoingWatch(ch) {
  const box = $('#ccQueue');
  if (!box || !ch) return;
  box.hidden = false;
  const tick = () => {
    const left = Math.max(0, Math.ceil((ch.expiresAt - Date.now()) / 1000));
    box.innerHTML = `<div class="thinking">Waiting for ${escName(ch.to?.name)} to accept… <b>${left}s</b></div><button class="mini-btn" id="ccCancelQ">Cancel</button>`;
    const b = $('#ccCancelQ'); if (b) b.onclick = cancelQueue;
    if (left <= 0) { box.innerHTML = `<div>Invite expired. Try again, or send a challenge link.</div>`; }
  };
  tick();
  const t = setInterval(async () => {
    tick();
    try {
      const d = await ctx.api('clash/inbox');
      if (d.match) { clearInterval(t); ctx.go('clash/' + d.match.id); return; }
      const still = (d.outgoing || []).find((x) => x.id === ch.id);
      if (!still || still.status !== 'pending') {
        clearInterval(t);
        if (!d.match) box.innerHTML = `<div>They didn’t accept in time.</div>`;
      } else ch = still;
    } catch {}
  }, 400);
}

async function makeInvite() {
  const { api, toast, BASE } = ctx;
  try {
    const d = await api('clash/invite', {});
    const url = location.origin + BASE + 'clash?invite=' + encodeURIComponent(d.code);
    try { await navigator.clipboard.writeText(url); toast('Invite link copied! They’ll get a countdown to accept.'); }
    catch { toast(url); }
    const box = $('#ccQueue');
    if (box) { box.hidden = false; box.innerHTML = `<div>Share this link: <code>${url}</code></div>`; }
  } catch (e) { toast(e.message); }
}

async function showMatch(id) {
  const { view, api, toast, go } = ctx;
  stopMatchPoll();
  view.innerHTML = `<div class="wrap clash-wrap"><div class="thinking" style="justify-content:center;margin-top:60px">Loading the match…</div></div>`;
  try {
    const d = await api('clash/match?id=' + encodeURIComponent(id));
    paintMatch(d.match, d.now);
    matchTimer = setInterval(async () => {
      try {
        const n = await api('clash/match?id=' + encodeURIComponent(id));
        paintMatch(n.match, n.now, true);
      } catch (e) {
        if (e.status === 404) { stopMatchPoll(); toast('Match not found.'); go('clash'); }
      }
    }, 1000);
  } catch (e) {
    toast(e.message);
    go('clash', true);
  }
}

export function stopMatch() {
  stopMatchPoll();
  stopPartyPoll();
}

function stopMatchPoll() {
  if (matchTimer) { clearInterval(matchTimer); matchTimer = 0; }
}

function paintMatch(m, now, soft = false) {
  const { view } = ctx;
  if (!m) return;
  if (m.status === 'loadout') return paintLoadout(m, soft);
  if (m.status === 'finished' || m.status === 'forfeit') return paintResults(m, soft);
  return paintArena(m, now || m.now, soft);
}

function paintLoadout(m, soft) {
  const { view, esc, ico, api, toast } = ctx;
  const picked = new Set(m.loadoutIds || []);
  const html = `
    <div class="wrap clash-wrap">
      <div class="clash-vs">
        <div class="cv-you"><span class="av">${ico(m.you.avatar)}</span><b>${esc(m.you.name)}</b>${m.youReady ? ' · ready' : ''}</div>
        <div class="cv-mid">VS · Best of 3</div>
        <div class="cv-opp"><span class="av">${ico(m.opp.avatar)}</span><b>${esc(m.opp.name)}</b>${m.oppReady ? ' · ready' : (m.oppLoadoutCount ? ' · picking' : ' · joining')}</div>
      </div>
      <div class="clash-lo-head">
        <h2>${m.mode === 'shelf' ? "Yesterday's shelf" : (m.modeTitle ? esc(m.modeTitle) + ' — build your loadout' : 'Build your loadout')}</h2>
        <p>${m.mode === 'shelf'
          ? "These are not your cards. You have " + (m.shelf?.peekLeft ?? 20) + " seconds to figure out a deck you have never seen. Pick 8–12, or they will be picked for you."
          : 'Pick 8–12 discoveries. Rarer cards hit harder in scoring.'}</p>
        ${m.mode === 'shelf' && m.shelf ? `<div class="lo-peek" id="loPeek">${m.shelf.peekLeft || 0}s left</div>` : ''}
        <div class="lo-count"><b id="loN">${picked.size}</b> / 12 selected <span class="muted">(min 8)</span></div>
      </div>
      <div class="lo-tools" id="loTools">
        <label class="search lo-search"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><input id="loSearch" type="search" placeholder="Search" autocomplete="off" enterkeyhint="search"></label>
        <div class="lo-rars" role="group" aria-label="Filter by rarity">
          <button type="button" class="lo-rar" data-rar="Common">Common</button>
          <button type="button" class="lo-rar" data-rar="Rare">Rare</button>
          <button type="button" class="lo-rar" data-rar="Epic">Epic</button>
          <button type="button" class="lo-rar" data-rar="Legendary">Legendary</button>
        </div>
      </div>
      <div class="lo-grid" id="loGrid">
        ${(m.collection || []).map((e) => {
          const on = picked.has(e.id);
          return `<button type="button" class="el lo-el ${rarityClass(e)}${nameClass(e.name)}${on ? ' on' : ''}" data-id="${e.id}" data-name="${esc(e.name)}" data-rar="${esc(e.rarity)}" style="${nameVars(e.name)}" title="${esc(e.name)} · ${esc(e.rarity)}"><span class="i">${ico(e.emoji)}</span><span class="n">${esc(e.name)}</span><span class="rar-pill ${rarityClass(e)}">${esc(e.rarity)}</span></button>`;
        }).join('')}
      </div>
      <p class="lo-empty" id="loEmpty" hidden>No discoveries match.</p>
      <div class="clash-lo-act">
        <button class="btn clash-lg" id="loReady" ${picked.size < 8 || m.youReady ? 'disabled' : ''}>${m.youReady ? 'Waiting for ' + esc(m.opp.name) + '…' : 'I’m ready'}</button>
        <button class="pill-btn" id="loFF">Forfeit</button>
      </div>
    </div>`;
  const hadGrid = !!document.getElementById('loGrid');
  if (!soft || !hadGrid) view.innerHTML = html;
  else {
    // keep scroll and the search box; just refresh ready + counts
    const n = document.getElementById('loN'); if (n) n.textContent = picked.size;
    const r = document.getElementById('loReady');
    if (r) { r.disabled = picked.size < 8 || m.youReady; r.textContent = m.youReady ? 'Waiting for ' + m.opp.name + '…' : 'I’m ready'; }
  }
  const applyLoFilter = () => {
    const q = (document.getElementById('loSearch')?.value || '').trim().toLowerCase();
    const rar = [...document.querySelectorAll('.lo-rar.on')].map((b) => b.dataset.rar);
    let shown = 0;
    document.querySelectorAll('#loGrid .lo-el').forEach((b) => {
      const name = (b.dataset.name || b.querySelector('.n')?.textContent || '').toLowerCase();
      const r = b.dataset.rar || (b.querySelector('.rar-pill')?.textContent || '').trim();
      const ok = (!q || name.includes(q)) && (!rar.length || rar.includes(r));
      b.hidden = !ok;
      if (ok) shown++;
    });
    const empty = document.getElementById('loEmpty');
    if (empty) empty.hidden = shown > 0;
  };
  const tools = document.getElementById('loTools');
  if (tools && !tools.dataset.bound) {
    tools.dataset.bound = '1';
    document.getElementById('loSearch')?.addEventListener('input', applyLoFilter);
    tools.querySelectorAll('.lo-rar').forEach((b) => {
      b.addEventListener('click', () => { b.classList.toggle('on'); applyLoFilter(); });
    });
  }
  applyLoFilter();
  const grid = document.getElementById('loGrid');
  if (grid && !grid.dataset.bound) {
    grid.dataset.bound = '1';
    grid.addEventListener('click', async (e) => {
      const b = e.target.closest('.lo-el');
      if (!b || m.youReady) return;
      const id = +b.dataset.id;
      if (picked.has(id)) picked.delete(id); else {
        if (picked.size >= 12) { ctx.toast('12 is the max.'); return; }
        picked.add(id);
      }
      b.classList.toggle('on', picked.has(id));
      const n = document.getElementById('loN'); if (n) n.textContent = picked.size;
      const r = document.getElementById('loReady'); if (r) r.disabled = picked.size < 8;
      try { await ctx.api('clash/loadout', { matchId: m.id, ids: [...picked] }); } catch (err) { ctx.toast(err.message); }
    });
  }
  const ready = document.getElementById('loReady');
  if (ready && !ready.dataset.bound) {
    ready.dataset.bound = '1';
    ready.onclick = async () => {
      try {
        const d = await ctx.api('clash/ready', { matchId: m.id });
        paintMatch(d.match, d.now);
      } catch (err) { ctx.toast(err.message); }
    };
  }
  const ff = document.getElementById('loFF');
  if (ff && !ff.dataset.bound) {
    ff.dataset.bound = '1';
    ff.onclick = () => doForfeit(m.id);
  }
}

function heatMeterExact(src) {
  const { esc } = ctx;
  if (!src) return '';
  const c = src.closeness != null ? src.closeness : src.breakdown?.closeness;
  const pct = c == null ? 0 : Math.max(0, Math.min(100, Math.round(Number(c) * 100)));
  return `<div class="rl-ex-heat" style="--pct:${pct}%">
    <div class="rl-ex-heat-track"><i class="rl-ex-heat-ptr" aria-hidden="true"></i></div>
    <div class="rl-ex-heat-labs"><span>Cold</span><span>Warm</span><span>Hot</span><span>On Fire</span></div>
  </div>`;
}


function oneSlotMode(m) {
  return m && (m.mode === 'pile' || m.mode === 'telephone' || m.mode === 'boss');
}
function modeSteps(m) {
  if (m.mode === 'gift') return `<span class="rl-ex-chip"><i>1</i> Combine two cards</span><span class="rl-ex-chip"><i>2</i> Keep one</span><span class="rl-ex-chip"><i>3</i> The other goes to them</span>`;
  if (m.mode === 'pile') return `<span class="rl-ex-chip"><i>1</i> Watch the pile</span><span class="rl-ex-chip"><i>2</i> Slap a card on it</span><span class="rl-ex-chip"><i>3</i> Theme scores. Sludge hurts.</span>`;
  if (m.mode === 'telephone') return `<span class="rl-ex-chip"><i>1</i> See the last card</span><span class="rl-ex-chip"><i>2</i> Add one ingredient</span><span class="rl-ex-chip"><i>3</i> Pass it on</span>`;
  if (m.mode === 'boss') return `<span class="rl-ex-chip"><i>1</i> Feed the boss</span><span class="rl-ex-chip"><i>2</i> It mutates</span><span class="rl-ex-chip"><i>3</i> Land the killing hit, keep it forever</span>`;
  return `<span class="rl-ex-chip"><i>1</i> Pick 2 cards</span><span class="rl-ex-chip"><i>2</i> Combine</span><span class="rl-ex-chip"><i>3</i> Closer to the theme = more points</span>`;
}
function modeBoard(m, slot, inKick) {
  const canTwo = !!pick[0] && !!pick[1] && m.status === 'playing' && !inKick && !m.pendingGift;
  const canOne = !!pick[0] && m.status === 'playing' && !inKick;
  if (m.mode === 'pile') {
    const pile = m.pile || {};
    return `<div class="rl-ex-board rl-mode-pile">
      <div class="rl-pile-card">${pile.emoji ? ctx.ico(pile.emoji) : '🫠'}<b>${ctx.esc(pile.name || 'The Pile')}</b><span>${m.pileMine ? 'You touched it last' : (m.pilePulseIn != null ? 'Mutates in ' + m.pilePulseIn + 's' : '')}</span></div>
      <div class="rl-plus">+</div>
      <div class="rl-slots">${slot(0)}</div>
      <button class="rl-go" id="clGo" ${!canOne?'disabled':''}><span>Slap</span></button>
    </div>`;
  }
  if (m.mode === 'telephone') {
    const t = m.telephone || {};
    const cur = t.current || {};
    const seats = (t.seats || []).map((s) => `<li class="${s.n === t.turn ? 'on' : ''}${s.you ? ' you' : ''}">${s.n}. ${ctx.esc(s.label)}</li>`).join('');
    return `<div class="rl-ex-board rl-mode-tel">
      <ol class="rl-tel-seats">${seats}</ol>
      <div class="rl-tel-card">${cur.emoji ? ctx.ico(cur.emoji) : '📝'}<b>${ctx.esc(cur.name || '…')}</b><span>${t.yourTurn ? 'Your turn — add one ingredient' : 'Waiting for the next seat'}</span></div>
      <div class="rl-plus">+</div>
      <div class="rl-slots">${slot(0)}</div>
      <button class="rl-go" id="clGo" ${!canOne || !t.yourTurn?'disabled':''}><span>Pass</span></button>
    </div>`;
  }
  if (m.mode === 'boss') {
    const b = m.boss || {};
    const pct = b.maxHp ? Math.max(0, Math.min(100, Math.round((b.hp / b.maxHp) * 100))) : 0;
    return `<div class="rl-ex-board rl-mode-boss">
      <div class="rl-boss-card">
        <div class="i">${b.emoji ? ctx.ico(b.emoji) : '🏢'}</div>
        <b>${ctx.esc(b.name || 'World Boss')}</b>
        <div class="rl-boss-hp"><i style="width:${pct}%"></i><span>${b.hp ?? 0} / ${b.maxHp ?? 0}</span></div>
        <em>${ctx.esc(b.tagline || '')}</em>
      </div>
      <div class="rl-plus">+</div>
      <div class="rl-slots">${slot(0)}</div>
      <button class="rl-go" id="clGo" ${!canOne?'disabled':''}><span>Feed</span></button>
    </div>`;
  }
  return `<div class="rl-ex-board">
    <div class="rl-slots">${slot(0)}<div class="rl-plus">+</div>${slot(1)}</div>
    <button class="rl-go" id="clGo" ${!canTwo?'disabled':''}><span>Combine</span></button>
  </div>`;
}
function modeExtras(m, ico, esc) {
  if (m.mode === 'gift' && m.pendingGift && m.pendingGift.a && m.pendingGift.b) {
    const card = (c, which) => `<button type="button" class="rl-gift-pick" data-gift="${which}">
      <span class="i">${c.emoji ? ico(c.emoji) : ''}</span>
      <b>${esc(c.name)}</b>
      <em>${esc(c.tagline || '')}</em>
      <span class="pts">+${c.score ?? 0}${c.heat ? ' · ' + esc(c.heat) : ''}</span>
      <strong>Keep this</strong>
    </button>`;
    return `<div class="rl-gift-overlay">
      <h2>A combine, and a poison gift</h2>
      <p>Keep one. The other goes into their hand, aimed at you.</p>
      <div class="rl-gift-row">${card(m.pendingGift.a, 'a')}${card(m.pendingGift.b, 'b')}</div>
    </div>`;
  }
  if (m.mode === 'telephone' && m.telephone?.revealed && (m.telephone.chain || []).length) {
    return `<ol class="rl-tel-chain">${m.telephone.chain.map((c) => `<li><span>${c.n}. ${esc(c.label)}</span> added <b>${c.ingredientEmoji ? ico(c.ingredientEmoji) + ' ' : ''}${esc(c.ingredient)}</b> → ${c.result?.emoji ? ico(c.result.emoji) : ''} <b>${esc(c.result?.name || '')}</b></li>`).join('')}</ol>`;
  }
  return '';
}
async function chooseGift(m, keep) {
  const { api, toast } = ctx;
  try {
    const d = await api('clash/gift', { matchId: m.id, keep });
    try { ctx.sfx?.(d.kept && d.given ? 'whoosh' : 'pop'); if (d.play?.score) ctx.sfx?.('points'); } catch {}
    pick = d.play ? [{ token: d.play.token, name: d.play.name, emoji: d.play.emoji, rarity: d.play.rarity }, null] : [null, null];
    paintMatch(d.match, d.now);
  } catch (e) { toast(e.message); }
}

function paintArena(m, now, soft) {
  const { view, esc, ico } = ctx;
  const kickLeft = m.kickoffUntil ? Math.max(0, m.kickoffUntil - Date.now()) : 0;
  const inKick = kickLeft > 0 && m.status === 'playing';
  const leftMs = m.status === 'inter'
    ? Math.max(0, (m.interUntil || 0) - Date.now())
    : Math.max(0, (m.endsAt || 0) - Date.now() - (inKick ? kickLeft : 0));
  const secs = Math.ceil(leftMs / 1000);
  const mm = Math.floor(Math.max(0, secs) / 60);
  const ss = String(Math.max(0, secs) % 60).padStart(2, '0');
  const clock = m.status === 'inter' ? 'NEXT' : `${mm}:${ss}`;
  if (m.status === 'playing' && !inKick && secs >= 1 && secs <= 3) {
    if (paintArena._tick !== secs) {
      paintArena._tick = secs;
      try { ctx.sfx?.('tick'); } catch {}
    }
  } else if (secs > 3) {
    paintArena._tick = 0;
  }
  const youPts = m.status === 'inter' && m.lastRound ? (m.lastRound.you ?? m.youScore ?? 0) : (m.youScore || 0);
  const oppPts = m.status === 'inter' && m.lastRound ? (m.lastRound.opp ?? m.oppScore ?? 0) : (m.oppScore || 0);
  const oppLabel = m.isAi ? 'BOT' : (m.opp?.name || 'RIVAL');
  const themeRaw = (m.status === 'inter' && m.lastRound?.theme) ? m.lastRound.theme : (m.theme || '');
  const themeText = String(themeRaw || '…').toUpperCase();
  const seen = new Set();
  const hand = [];
  const pushCard = (e) => {
    if (!e) return;
    const token = e.token || (e.id != null ? ('e:' + e.id) : '');
    if (!token || seen.has(token)) return;
    seen.add(token);
    hand.push({ ...e, token });
  };
  (m.loadout || []).forEach((e) => pushCard({ ...e, token: e.token || ('e:' + e.id) }));
  (m.gifts || []).forEach((e) => pushCard({ ...e, token: e.token, gift: true }));
  (m.youPlays || []).forEach((e) => pushCard({ ...e, token: e.token || ('p:' + (e.id || e.name)) }));
  const slot = (i) => {
    const c = pick[i];
    if (!c) return `<button type="button" class="rl-slot" data-s="${i}" aria-label="Card slot ${i + 1}"></button>`;
    return `<button type="button" class="rl-slot filled ${rarityClass(c)}" data-s="${i}" aria-label="${esc(c.name)}"><div class="i">${ico(c.emoji)}</div><div class="nm">${esc(c.name)}</div></button>`;
  };
  const boost = m.boost || 0;
  const boostMax = m.boostMax || 100;
  const canAtk = boost >= (m.boostAttack || 30) && m.oppLatest;
  const canBurst = boost >= (m.boostBurst || 50);
  const kickN = inKick ? Math.ceil(kickLeft / 1000) : 0;
  const kickWord = kickN >= 3 ? '3' : kickN === 2 ? '2' : kickN === 1 ? '1' : 'GO';
  const latest = (m.youPlays || []).length ? (m.youPlays || [])[(m.youPlays || []).length - 1] : null;
  const playCard = (p, opp) => `<div class="rl-playc${opp ? ' opp' : ''}">
    <div class="eq">${esc(p.a)} + ${esc(p.b)}</div>
    <div class="nm">${p.emoji ? ico(p.emoji) + ' ' : ''}${esc(p.name)}</div>
    <div class="pts">+${p.score}${p.breakdown?.heat ? ' · ' + p.breakdown.heat : ''}${p.attack ? (p.breakdown?.attackWorks ? ' · counter' : ' · missed') : ''}</div>
  </div>`;
  const result = latest ? `<div class="rl-ex-result">
      <div class="rl-ex-result-top">
        <span class="rl-ex-ico">${latest.emoji ? ico(latest.emoji) : ''}</span>
        <b class="rl-ex-name">${esc(latest.name)}</b>
        ${latest.rarity ? `<span class="rl-ex-rar ${rarityClass(latest)}">${esc(String(latest.rarity).toUpperCase())}</span>` : ''}
        <span class="rl-ex-pts">+${latest.score ?? 0}</span>
      </div>
      ${heatMeterExact(latest)}
    </div>` : '';

  const arena = `
    <div class="rl-arena rl-exact">
      <div class="rl-exact-col">
        <h1 class="rl-ex-theme">Theme: ${esc(themeText)}</h1>
        <div class="rl-ex-steps" aria-label="How to play">
          ${modeSteps(m)}
        </div>
        <div class="rl-scoreboard">
          <div class="rl-sb-blue"><div class="rl-sb-stack"><span class="rl-sb-lab">YOU</span><span class="rl-sb-pts">${youPts}</span></div></div>
          <div class="rl-sb-mid"><div class="rl-sb-time">${clock}</div></div>
          <div class="rl-sb-orange"><div class="rl-sb-stack"><span class="rl-sb-lab">${esc(oppLabel)}</span><span class="rl-sb-pts">${oppPts}</span></div></div>
        </div>
        ${modeBoard(m, slot, inKick)}
        ${result}
        ${modeExtras(m, ico, esc)}
      </div>
      <div class="rl-panel rl-hand" id="clHand">
        ${hand.map((e) => {
          const on = pick.some((p) => p && p.token === e.token);
          return `<button type="button" class="el lo-el rl-el ${rarityClass(e)}${nameClass(e.name)}${on ? ' picked' : ''}" data-tok="${esc(e.token)}" style="${nameVars(e.name)}" title="${esc(e.name)}"><span class="i">${ico(e.emoji)}</span><span class="n">${esc(e.name)}</span></button>`;
        }).join('')}
      </div>
      <details class="rl-ex-more">
        <summary>Attack, Burst &amp; Boost</summary>
        <div class="rl-ex-more-body">
          <div class="rl-acts">
            <button class="rl-atk" id="clAtk" ${!canAtk||m.status!=='playing'||inKick?'disabled':''}><span>Attack</span></button>
            <button class="rl-burst${burstMode?' on':''}" id="clBurst" ${!canBurst?'disabled':''}>Burst ×2</button>
          </div>
          <div class="rl-boost">
            <div class="lab"><span>BOOST</span><span>${boost} / ${boostMax}</span></div>
            <div class="bar"><i style="width:${Math.max(0, Math.min(100, boost))}%"></i></div>
          </div>
          <div class="rl-ex-logs">
            <div class="rl-col blue"><h3>Your creations</h3>${(m.youPlays || []).slice().reverse().map((p) => playCard(p, false)).join('') || '<div class="muted">Combine two cards to score.</div>'}</div>
            <div class="rl-col orange"><h3>${esc(m.opp.name)}’s creations</h3>${(m.oppPlays || []).slice().reverse().map((p) => playCard(p, true)).join('') || '<div class="muted">Waiting for their first combo…</div>'}</div>
          </div>
          <div class="rl-quick" id="clQuick">
            ${['What a combo!','Nice one!','Close one!','Calculated.','Wow!'].map((q) => `<button type="button" data-q="${esc(q)}">${esc(q)}</button>`).join('')}
          </div>
        </div>
      </details>
      ${boom ? `<div class="rl-boom${boom.kind==='DEMOLISHED!'?' demolished':''}${boom.heat ? ' heat-' + String(boom.heat).toLowerCase().replace(/\s+/g,'-') : ''}"><div class="word">${boom.kind}</div><div class="sub">${esc(boom.sub)}</div></div>` : ''}
      ${inKick ? `<div class="rl-kick"><b>${kickWord}</b></div>` : ''}
    </div>`;
  view.innerHTML = arena;
  bindArena(m, hand);
}

function bindArena(m, hand) {
  const { api, toast } = ctx;
  const byTok = Object.fromEntries(hand.map((e) => [e.token, e]));
  document.querySelectorAll('.rl-slot[data-s]').forEach((el) => {
    el.onclick = () => {
      pick[+el.dataset.s] = null;
      paintArena(m, m.now, false);
    };
  });
  const handEl = document.getElementById('clHand');
  if (handEl) handEl.onclick = (e) => {
    const b = e.target.closest('[data-tok]');
    if (!b || m.status !== 'playing') return;
    const card = byTok[b.dataset.tok];
    if (!card) return;
    const one = oneSlotMode(m);
    if (one) pick = [card, null];
    else if (!pick[0]) pick[0] = card;
    else if (!pick[1]) pick[1] = card;
    else { pick = [card, null]; }
    paintArena(m, m.now, false);
  };
  const go = document.getElementById('clGo');
  if (go) go.onclick = () => doPlay(m, false);
  document.querySelectorAll('[data-gift]').forEach((b) => {
    b.onclick = () => chooseGift(m, b.dataset.gift);
  });
  const atk = document.getElementById('clAtk');
  if (atk) atk.onclick = () => {
    if (!pick[0] || !pick[1]) { attackMode = true; toast('Pick two cards, then Attack.'); return; }
    doPlay(m, true);
  };
  const burst = document.getElementById('clBurst');
  if (burst) burst.onclick = () => { burstMode = !burstMode; paintArena(m, m.now, false); };
  const qk = document.getElementById('clQuick');
  if (qk) qk.onclick = async (e) => {
    const b = e.target.closest('[data-q]');
    if (!b) return;
    try { await api('clash/quick', { matchId: m.id, phrase: b.dataset.q }); toast(b.dataset.q); }
    catch (err) { toast(err.message); }
  };
}

async function doPlay(m, attack) {
  const one = oneSlotMode(m);
  if (!pick[0] || (!one && !pick[1])) return;
  const { api, toast, sfx } = ctx;
  try {
    const d = await api('clash/play', { matchId: m.id, a: pick[0].token, b: one ? (pick[0].token) : pick[1].token, attack, burst: burstMode });
    if (d.pendingGift) {
      try { ctx.sfx?.('pop'); } catch {}
      pick = [null, null];
      paintMatch(d.match, d.now);
      return;
    }
    if (ctx.sfx) {
      const pl = d.play || {};
      const heat = pl.breakdown?.heat || pl.heat;
      const exact = pl.breakdown?.exact || pl.exact;
      if (exact) ctx.sfx('exact');
      else if (heat === 'On Fire') ctx.sfx('heat-fire');
      else if (heat === 'Hot') ctx.sfx('heat-hot');
      else if (heat === 'Warm') ctx.sfx('heat-warm');
      else ctx.sfx('pop');
      if (pl.score) ctx.sfx('points');
    }
    const pl = d.play;
    if (pl) {
      const heat = pl.breakdown?.heat || pl.heat;
      const kind = pl.breakdown?.attackWorks ? 'DEMOLISHED!' : (pl.breakdown?.exact ? 'ON TARGET!' : (heat === 'On Fire' ? 'ON FIRE!' : (heat === 'Hot' ? 'HOT!' : (heat === 'Warm' ? 'WARM!' : 'COMBO!'))));
      boom = { kind, heat, sub: `${pl.a || pl.inputs?.[0] || ''} + ${pl.b || pl.inputs?.[1] || ''} = ${pl.name} · +${pl.score}` };
      setTimeout(() => { if (boom && boom.sub.endsWith('+' + pl.score)) { boom = null; } }, 1800);
    }
    pick = [pl ? { token: pl.token, name: pl.name, emoji: pl.emoji, rarity: pl.rarity } : null, null];
    burstMode = false;
    attackMode = false;
    paintMatch(d.match, d.now);
  } catch (e) { toast(e.message); }
}

function rematchBox(rm) {
  if (!rm || rm.status === 'idle' || (rm.need || 0) === 0) return '';
  const left = rm.expiresAt ? Math.max(0, Math.ceil((rm.expiresAt - Date.now()) / 1000)) : 0;
  const people = (rm.players || []).map((p) => `<li><span class="av" style="width:28px;height:28px;border-radius:50%;display:inline-grid;place-items:center;background:#ffe9a8">${ctx.ico(p.avatar)}</span>${ctx.esc(p.name)}${p.you ? ' (you)' : ''}${p.ai ? ' · bot' : ''} <span class="${p.voted ? 'yes' : 'wait'}">${p.voted ? '✓' : '…'}</span></li>`).join('');
  const msg = rm.message ? `<div class="msg">${ctx.esc(rm.message)}</div>` : '';
  const clock = rm.status === 'open' && left ? `<span class="rl-clock">${left}s</span>` : '';
  return `<div class="rl-panel rl-vote" id="rlVote"><h3>Rematch ${rm.yes}/${rm.need}${clock}</h3>
    <div class="count">${rm.status === 'cancelled' ? 'Vote cancelled' : 'Everyone has to vote yes'}</div>
    <ul>${people}</ul>${msg}</div>`;
}

async function voteRematch(matchId) {
  const { api, toast, go } = ctx;
  try {
    const d = await api('clash/rematch', { matchId });
    const rm = d.rematch || {};
    if (rm.newMatchId) return go('clash/' + rm.newMatchId);
    if (d.match && d.match.id && d.match.id !== matchId && !['finished', 'forfeit'].includes(d.match.status)) {
      return go('clash/' + d.match.id);
    }
    if (d.match) paintMatch(d.match, d.now || d.match.now);
  } catch (e) { toast(e.message); }
}

function paintResults(m, soft = false) {
  pick = [null, null];
  const { view, esc, ico, BASE, toast, go, api } = ctx;
  const rm = m.rematch || {};
  if (rm.newMatchId) { go('clash/' + rm.newMatchId); return; }
  const party = m.kind === 'party';
  const draw = !!(m.draw || (!party && !m.winnerId && m.status === 'finished'));
  const won = !draw && (party ? !!m.youWon : m.winnerId === m.you.id);
  const share = m.share || {};
  const best = share.best || m.youLatest || null;
  const youName = party ? (m.youName || 'Party') : (m.you?.name || 'You');
  const oppName = party ? (m.oppName || 'Party') : (m.opp?.name || 'Rival');
  const scoreLine = party ? `${m.youSession || 0}-${m.oppSession || 0}` : `${m.youWins ?? 0}-${m.oppWins ?? 0}`;
  let headline = share.headline || '';
  if (!headline) {
    const withLine = best?.name ? `${best.a || '?'} + ${best.b || '?'} = ${best.name}` : '';
    if (draw) headline = `${youName} vs ${oppName} · ${scoreLine} · Draw`;
    else if (won) headline = `${youName} beats ${oppName} · ${scoreLine} · I beat @${oppName}${withLine ? ' with ' + withLine : ''}`;
    else headline = `${oppName} beats ${youName} · ${scoreLine}${withLine ? ' · ' + withLine : ''}`;
  }
  const url = (typeof location !== 'undefined' ? location.origin : '') + (BASE || '/combocraft/') + 'clash';
  const stats = (m.stats || []).slice();
  if (!stats.length && !party && m.you && m.opp) {
    stats.push(
      { playerId: m.you.id, name: m.you.name, avatar: m.you.avatar, score: 0, combos: 0, chains: 0, attacks: 0, mvp: won },
      { playerId: m.opp.id, name: m.opp.name, avatar: m.opp.avatar, score: 0, combos: 0, chains: 0, attacks: 0, mvp: false },
    );
  }
  stats.sort((a, b) => {
    if (party && a.side && b.side && a.side !== b.side) return a.side < b.side ? -1 : 1;
    return (b.score || 0) - (a.score || 0);
  });
  const mvp = stats.find((s) => s.mvp) || stats[0];
  const rc = m.rankChange;
  const after = rc?.after || (m.you && m.you.rank) || {};
  const before = rc?.before || after;
  const xpGain = rc?.xpGained || 0;
  const word = draw ? 'DRAW' : (won ? 'VICTORY' : 'DEFEAT');
  const vicClass = draw ? ' draw' : (won ? '' : ' defeat');

  if (!soft && paintResults._played !== m.id) {
    paintResults._played = m.id;
    try { ctx.sfx?.(draw ? 'pop' : (won ? 'victory' : 'defeat')); } catch {}
  }

  if (soft && document.getElementById('rlResults') && document.getElementById('clRematch')) {
    const btn = document.getElementById('clRematch');
    if (btn) {
      btn.disabled = !!rm.youVoted && rm.status === 'open';
      const lab = rm.status === 'open' ? `REMATCH ${rm.yes}/${rm.need}` : 'REMATCH';
      const span = btn.querySelector('span');
      if (span) span.textContent = lab;
    }
    const box = document.getElementById('rlVote');
    const fresh = rematchBox(rm) || '<div id="rlVote" hidden></div>';
    if (box) box.outerHTML = fresh;
    else document.getElementById('rlResults')?.insertAdjacentHTML('beforeend', fresh);
    return;
  }

  const rows = stats.map((s) => `<tr class="${(s.playerId && m.you && s.playerId === m.you.id) || s.you ? 'me' : ''} ${s.side ? 'side-' + s.side : ''}">
    <td><div class="rl-p"><span class="av">${ico(s.avatar)}</span> ${esc(s.name)}${s.team ? `<span class="rl-team">@${esc(s.team)}</span>` : ''}</div></td>
    <td class="n">${s.score || 0}</td><td class="n">${s.combos || 0}</td><td class="n">${s.chains || 0}</td><td class="n">${s.attacks || 0}</td>
    <td class="rl-mvp">${s.mvp ? '★ MVP' : ''}</td>
  </tr>`).join('') || `<tr><td colspan="6" class="muted">No plays this match.</td></tr>`;

  const voteLabel = rm.status === 'open' ? `REMATCH ${rm.yes}/${rm.need}` : 'REMATCH';
  view.innerHTML = `
    <div class="rl-results" id="rlResults">
      <div class="rl-kicker" style="text-align:center">COMBO CLASH</div>
      <div class="rl-vic${vicClass}"><div class="word">${word}</div>
        <div class="rl-subh">${esc(headline)}</div>
        ${m.mode === 'telephone' && m.telephone?.chain?.length ? `<ol class="rl-tel-chain end">${m.telephone.chain.map((c) => `<li><span>${c.n}. ${esc(c.label)}</span> added <b>${c.ingredientEmoji ? ico(c.ingredientEmoji) + ' ' : ''}${esc(c.ingredient)}</b> → ${c.result?.emoji ? ico(c.result.emoji) : ''} <b>${esc(c.result?.name || '')}</b></li>`).join('')}</ol>` : ''}
        ${m.mode === 'boss' && m.boss && (m.boss.hp === 0 || m.bossKilledByYou) ? `<p class="rl-boss-won">${m.boss?.emoji ? ico(m.boss.emoji) : ''} ${m.bossKilledByYou ? 'You kept ' + esc(m.boss?.name || 'the boss') + ' in your book forever.' : esc(m.opp?.name || 'Your rival') + ' kept ' + esc(m.boss?.name || 'the boss') + ' in their book.'}</p>` : ''}
        ${m.modeTitle ? `<div class="rl-mode-tag">${esc(m.modeTitle)}</div>` : ''}
      </div>
      <div class="rl-res-grid">
        <div class="rl-panel rl-table">
          <h2>Scoreboard</h2>
          <table><thead><tr><th>Player</th><th class="n">Score</th><th class="n">Combos</th><th class="n">Chains</th><th class="n">Attacks</th><th></th></tr></thead>
          <tbody>${rows}</tbody></table>
        </div>
        <div class="rl-side">
          <div class="rl-panel rl-mvp-card">
            <div class="big">${ico((mvp && mvp.avatar) || (party ? '⭐' : m.you?.avatar))}</div>
            <h3>${esc((mvp && mvp.name) || youName)}</h3>
            <p>Match MVP${best?.name ? ' · ' + esc(best.name) : ''}</p>
          </div>
          <div class="rl-panel rl-xp-card">
            <h3>XP Gained</h3>
            <div class="rl-xp"><i style="width:${Math.round(((after.xpInto || 0) / (after.xpNext || 100)) * 100)}%"></i></div>
            <div class="rl-gain">+${xpGain} XP</div>
            <div style="font-size:13px;font-weight:700;color:var(--rl-muted);margin-top:2px">Level ${after.level || 1} · ${after.xpInto || 0} / ${after.xpNext || 100} to Level ${(after.level || 1) + 1}</div>
          </div>
          <div class="rl-panel rl-rank-card">
            <h3>Rank</h3>
            <div class="rankrow" style="display:flex;align-items:center;gap:12px">
              <div class="rl-badge" style="width:58px;height:58px;font-size:18px">${esc(after.tierShort || 'B')}</div>
              <div>
                <div style="font-weight:800;font-size:18px">${esc(after.tier || 'Bronze')}</div>
                <div style="font-weight:700;color:var(--rl-muted)">${before.mmr ?? after.mmr ?? 600} → ${after.mmr ?? 600} MMR · ${rc ? ((rc.mmrDelta||0) >= 0 ? '+' : '') + (rc.mmrDelta || 0) : '+0'}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="rl-res-acts">
        <button class="btn-rl rl-rev" id="clRematch" ${rm.youVoted && rm.status==='open' ? 'disabled' : ''}><span>${voteLabel}</span></button>
        <button class="btn-rl rl-share" id="clShare"><span>Share</span></button>
        <button class="btn-rl rl-back" id="clLobby"><span>Back to Clash</span></button>
      </div>
      ${rematchBox(rm)}
    </div>`;
  const rmBtn = document.getElementById('clRematch');
  if (rmBtn) rmBtn.onclick = () => voteRematch(m.id);
  const sh = document.getElementById('clShare');
  if (sh) sh.onclick = async () => {
    const text = headline + ' ' + url;
    try {
      if (navigator.share) await navigator.share({ title: 'Combo Clash', text: headline, url });
      else { await navigator.clipboard.writeText(text); toast('Copied!'); }
    } catch {}
  };
  const lobby = document.getElementById('clLobby');
  if (lobby) lobby.onclick = async () => {
    if (rm.status === 'open') { try { await api('clash/rematch', { matchId: m.id, cancel: true }); } catch {} }
    if (party) { try { await api('clash/party/exit', { matchId: m.id }); } catch {} }
    go('clash');
  };
  ensurePartyBar(ctx.state.clashInbox?.party);
}

function remixWinner(best) {
  const { go, toast, state } = ctx;
  if (!best) return;
  const col = state.elements || state.collection || [];
  const find = (n) => (col || []).find((e) => (e.name || '').toLowerCase() === String(n).toLowerCase());
  // stash names so Play can offer a hint; always go to the sandbox
  sessionStorage.setItem('cc_remix', JSON.stringify({ a: best.a, b: best.b, name: best.name }));
  toast(`Try ${best.a} + ${best.b} in the sandbox to remix ${best.name}.`);
  go('play');
}

async function doForfeit(id) {
  if (!confirm('Forfeit this match?')) return;
  try {
    const d = await ctx.api('clash/forfeit', { matchId: id });
    paintMatch(d.match, d.now);
  } catch (e) { ctx.toast(e.message); }
}

async function pollInbox() {
  if (!ctx?.api) return;
  try {
    if (!ctx.state?.me) return;
    const d = await ctx.api('clash/inbox');
    ctx.state.clashInbox = d;
    const route = location.pathname;
    const onClashMatch = /clash\/m/.test(route);
    if (d.nickname && ctx.state.me) ctx.state.me.nickname = d.nickname;
    ensurePartyBar(d.party);
    if (d.party?.needsName) promptPartyName();
    const onPartyMatch = /clash\/g/.test(route);
    if (d.match && !onClashMatch && shouldFollowMatch(d.match)) {
      ctx.go('clash/' + d.match.id);
      return;
    }
    if (d.partyMatch && !onPartyMatch && !onClashMatch) {
      ctx.go('clash/' + d.partyMatch.id);
      return;
    }
    mountPlayActivity(d);
    const onPlay = !!document.getElementById('playActivity');
    for (const ch of d.incoming || []) {
      if (ch.status === 'pending' && ch.expiresAt > Date.now() && !onPlay) showInvitePopup(ch);
    }
    for (const ch of d.partyInvites || []) {
      if (ch.status === 'pending' && ch.expiresAt > Date.now() && !onPlay) showPartyInvite(ch);
    }
    const live = new Set([
      ...(d.incoming || []).filter((c) => c.status === 'pending' && c.expiresAt > Date.now()).map((c) => c.id),
      ...(d.partyInvites || []).filter((c) => c.status === 'pending' && c.expiresAt > Date.now()).map((c) => c.id),
    ]);
    document.querySelectorAll('.cc-invite[data-id]').forEach((el) => {
      if (!live.has(el.dataset.id)) dismissInvite(el.dataset.id, false);
    });
  } catch {}
}

function shouldFollowMatch(m) {
  // Don't yank someone mid-play sandbox unless they just accepted or queued.
  return m.status === 'loadout' || m.status === 'playing' || m.status === 'inter';
}

function showInvitePopup(ch, fromLink = false) {
  if (!ch?.id) return;
  if (document.getElementById('playActivity')) { mountPlayActivity(); return; }
  if (document.querySelector('.cc-invite[data-id="' + ch.id + '"]')) return;
  shownInvite.add(ch.id);
  const host = inviteHost();
  const { esc, ico } = ctx;
  const name = ch.from?.name || 'Someone';
  const el = document.createElement('div');
  el.className = 'cc-invite';
  el.dataset.id = ch.id;
  el.innerHTML = `
    <div class="cci-card">
      <div class="cci-ring" aria-hidden="true"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="16" class="bg"/><circle cx="18" cy="18" r="16" class="fg"/></svg><b class="cci-num">5</b></div>
      <div class="cci-body">
        <div class="cci-kicker">Combo Clash</div>
        <div class="cci-title"><span class="av">${ico(ch.from?.avatar || '⚡')}</span> ${esc(name)} challenges you!</div>
        <div class="cci-sub">Best of 3 · 75-second rounds. Accept before the timer runs out.</div>
        <div class="cci-acts">
          <button class="btn" data-a="yes">Accept</button>
          <button class="pill-btn" data-a="no">Nope</button>
        </div>
      </div>
    </div>`;
  host.appendChild(el);
  requestAnimationFrame(() => el.classList.add('on'));
  if (ctx.sfx) try { ctx.sfx('pop'); } catch {}
  const tick = () => {
    const left = Math.max(0, ch.expiresAt - Date.now());
    const secs = Math.ceil(left / 1000);
    const num = el.querySelector('.cci-num');
    if (num) num.textContent = String(secs);
    const fg = el.querySelector('.cci-ring .fg');
    const total = fromLink ? 8000 : 5000;
    if (fg) {
      const c = 2 * Math.PI * 16;
      fg.style.strokeDasharray = String(c);
      fg.style.strokeDashoffset = String(c * (1 - left / total));
    }
    if (left <= 0) {
      clearInterval(el._t);
      el.classList.add('expired');
      setTimeout(() => dismissInvite(ch.id, false), 600);
    }
  };
  tick();
  el._t = setInterval(tick, 100);
  el.querySelector('[data-a="yes"]').onclick = async () => {
    try {
      const d = await ctx.api('clash/respond', { id: ch.id, accept: true });
      dismissInvite(ch.id, true);
      if (d.match) ctx.go('clash/' + d.match.id);
    } catch (e) { ctx.toast(e.message); dismissInvite(ch.id, true); }
  };
  el.querySelector('[data-a="no"]').onclick = async () => {
    try { await ctx.api('clash/respond', { id: ch.id, accept: false }); } catch {}
    dismissInvite(ch.id, true);
  };
}

function dismissInvite(id, fast) {
  const el = document.querySelector('.cc-invite[data-id="' + id + '"]');
  if (!el) return;
  clearInterval(el._t);
  el.classList.remove('on');
  el.classList.add('off');
  setTimeout(() => el.remove(), fast ? 180 : 400);
}

function inviteHost() {
  let h = document.getElementById('ccInviteHost');
  if (!h) {
    h = document.createElement('div');
    h.id = 'ccInviteHost';
    document.body.appendChild(h);
  }
  return h;
}

let actTick = 0;
let actFeedKey = '';

export function mountPlayActivity(inbox) {
  const root = document.getElementById('playActivity');
  if (!root || !ctx) return;
  const d = inbox || ctx.state?.clashInbox || {};
  paintActivityLive(d);
  paintActivityFeed(d);
  if (!actTick) {
    actTick = setInterval(() => {
      if (!document.getElementById('playActivity')) { clearInterval(actTick); actTick = 0; return; }
      tickActivityClocks();
    }, 100);
  }
}

function liveInvites(d) {
  const now = Date.now();
  const incoming = (d.incoming || []).filter((c) => c.status === 'pending' && c.expiresAt > now).map((c) => ({ ...c, kind: c.kind || 'duel' }));
  const party = (d.partyInvites || []).filter((c) => c.status === 'pending' && c.expiresAt > now).map((c) => ({ ...c, kind: 'party' }));
  const outgoing = (d.outgoing || []).filter((c) => ['pending', 'waiting'].includes(c.status) && (!c.expiresAt || c.expiresAt > now));
  return { incoming, party, outgoing };
}

function paintActivityLive(d) {
  const box = document.getElementById('actLive');
  if (!box) return;
  const { esc, ico } = ctx;
  const { incoming, party, outgoing } = liveInvites(d);
  const bits = [];
  if (d.party?.queued) {
    bits.push(`<div class="act-card act-search" data-kind="search"><div class="act-kicker">Matchmaking</div><div class="act-title">Searching for a party…</div><div class="act-sub">Ready is on. We’ll sit you against another partied-up team.</div><button class="mini-btn" data-act="cancel-party">Cancel</button></div>`);
  }
  for (const ch of incoming) {
    const name = ch.from?.name || 'Someone';
    bits.push(inviteCardHtml({
      id: ch.id, kind: 'duel', kicker: 'Combo Clash',
      title: `${ico(ch.from?.avatar || '⚡')} ${esc(name)} challenges you!`,
      sub: 'Best of 3 · 75-second rounds. Accept before the timer runs out.',
      expiresAt: ch.expiresAt, total: 5000, incoming: true,
    }));
  }
  for (const ch of party) {
    const name = ch.from?.name || 'Someone';
    bits.push(inviteCardHtml({
      id: ch.id, kind: 'party', kicker: 'Party Up',
      title: `${ico(ch.from?.avatar || '⚡')} ${esc(name)} invited you to a party!`,
      sub: (ch.partyName ? 'Party @' + esc(ch.partyName) + '. ' : '') + 'Accept before the timer runs out.',
      expiresAt: ch.expiresAt, total: 12000, incoming: true,
    }));
  }
  for (const ch of outgoing) {
    const name = ch.to?.name || 'them';
    const wait = ch.status === 'waiting' ? 'Your challenge link is open. Share it with a friend!' : `Waiting for ${esc(name)} to accept…`;
    bits.push(inviteCardHtml({
      id: ch.id, kind: 'out', kicker: 'Challenge sent',
      title: wait,
      sub: ch.status === 'waiting' ? 'They’ll get a countdown to accept when they open it.' : 'They have a few seconds to accept.',
      expiresAt: ch.expiresAt, total: ch.status === 'waiting' ? 600000 : 5000, incoming: false,
    }));
  }
  if (d.party && !d.party.queued && (d.party.members || []).length) {
    const names = (d.party.members || []).map((m) => esc(m.name || 'Player')).join(', ');
    bits.push(`<div class="act-card act-party" data-kind="party-status"><div class="act-kicker">Your party</div><div class="act-title">@${esc(d.party.name || 'unnamed')}</div><div class="act-sub">${names || 'Just you so far.'}</div><button class="btn act-mini" data-act="ready">Ready</button></div>`);
  }
  const next = bits.join('') || '';
  const prevIds = [...box.querySelectorAll('[data-id], [data-kind]')].map((el) => el.dataset.id || el.dataset.kind).join('|');
  const nextIds = (incoming.map((c) => c.id).concat(party.map((c) => c.id), outgoing.map((c) => c.id), d.party?.queued ? ['search'] : [], (d.party && !d.party.queued && d.party.members) ? ['party-status'] : [])).join('|');
  if (prevIds !== nextIds) {
    const had = prevIds.length > 0;
    box.innerHTML = next;
    if (next && (!had || incoming.length || party.length)) {
      box.querySelectorAll('.act-card').forEach((el) => el.classList.add('land'));
      if (ctx.sfx && (incoming.length || party.length)) try { ctx.sfx('pop'); } catch {}
    }
    bindActivityLive(box, d);
  }
  tickActivityClocks();
}

function inviteCardHtml({ id, kind, kicker, title, sub, expiresAt, total, incoming }) {
  const left = Math.max(0, expiresAt - Date.now());
  const secs = Math.ceil(left / 1000);
  const acts = incoming
    ? `<div class="act-acts"><button class="btn" data-a="yes">Accept</button><button class="pill-btn" data-a="no">Decline</button></div>`
    : '';
  return `<div class="act-card act-invite" data-id="${id}" data-kind="${kind}" data-exp="${expiresAt}" data-total="${total}">
    <div class="act-top">
      <div class="cci-ring sm" aria-hidden="true"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="16" class="bg"/><circle cx="18" cy="18" r="16" class="fg"/></svg><b class="cci-num">${secs}</b></div>
      <div>
        <div class="act-kicker">${kicker}</div>
        <div class="act-title">${title}</div>
        <div class="act-sub">${sub}</div>
      </div>
    </div>
    ${acts}
  </div>`;
}

function tickActivityClocks() {
  document.querySelectorAll('#actLive .act-invite').forEach((el) => {
    const exp = +el.dataset.exp || 0;
    const total = +el.dataset.total || 5000;
    const left = Math.max(0, exp - Date.now());
    const num = el.querySelector('.cci-num');
    if (num) num.textContent = String(Math.ceil(left / 1000));
    const fg = el.querySelector('.cci-ring .fg');
    if (fg) {
      const c = 2 * Math.PI * 16;
      fg.style.strokeDasharray = String(c);
      fg.style.strokeDashoffset = String(c * (1 - (total ? left / total : 0)));
    }
    if (left <= 0) el.classList.add('expired');
  });
}

function bindActivityLive(box, d) {
  box.querySelectorAll('.act-invite[data-kind="duel"] [data-a]').forEach((btn) => {
    btn.onclick = async () => {
      const card = btn.closest('[data-id]');
      const yes = btn.dataset.a === 'yes';
      try {
        const r = await ctx.api('clash/respond', { id: card.dataset.id, accept: yes });
        if (yes && r.match) ctx.go('clash/' + r.match.id);
        else mountPlayActivity();
      } catch (e) { ctx.toast(e.message); }
    };
  });
  box.querySelectorAll('.act-invite[data-kind="party"] [data-a]').forEach((btn) => {
    btn.onclick = async () => {
      const card = btn.closest('[data-id]');
      const yes = btn.dataset.a === 'yes';
      try {
        const r = await ctx.api('clash/party/respond', { id: card.dataset.id, accept: yes });
        if (yes) {
          ctx.toast(r.needsName ? 'You’re in! The host will name the party.' : 'You’re in the party.');
          ctx.go('clash');
        } else mountPlayActivity();
      } catch (e) { ctx.toast(e.message); }
    };
  });
  const cancel = box.querySelector('[data-act="cancel-party"]');
  if (cancel) cancel.onclick = async () => {
    try { await ctx.api('clash/party/cancel', {}); mountPlayActivity(); } catch (e) { ctx.toast(e.message); }
  };
  const ready = box.querySelector('[data-act="ready"]');
  if (ready) ready.onclick = async () => {
    try {
      const r = await ctx.api('clash/party/ready', {});
      if (r.match) ctx.go('clash/' + r.match.id);
      else mountPlayActivity();
    } catch (e) { ctx.toast(e.message); }
  };
}

function paintActivityFeed(d) {
  const box = document.getElementById('actFeed');
  if (!box) return;
  const { esc, ico, go } = ctx;
  const last = d.lastScore;
  const people = d.recentPeople || [];
  const key = JSON.stringify({
    last: last && [last.kind, last.you?.name, last.you?.score, last.opp?.name, last.opp?.score, last.youWon],
    people: people.map((p) => p.id || p.name),
    need: d.needName,
  });
  if (key === actFeedKey && box.childElementCount) return;
  actFeedKey = key;
  const bits = [];
  if (last) {
    const verb = last.youWon ? 'You won' : 'You finished';
    bits.push(`<div class="act-card act-result land"><div class="act-kicker">${last.kind === 'party' ? 'Party match' : 'Last match'}</div>
      <div class="act-score"><span>${ico(last.you?.avatar)} <b>${esc(last.you?.name || 'You')}</b> <em>${last.you?.score ?? 0}</em></span>
      <i>VS</i>
      <span><em>${last.opp?.score ?? 0}</em> <b>${esc(last.opp?.name || 'Rival')}</b> ${ico(last.opp?.avatar)}</span></div>
      <div class="act-sub">${verb}${last.kind === 'party' ? ' · sessions' : ''}.</div>
      <button class="pill-btn act-mini" data-act="open-clash">Open Combo Clash</button></div>`);
  }
  if (people.length) {
    bits.push(`<div class="act-card act-people"><div class="act-kicker">Played with you</div>
      <ul class="act-list">${people.slice(0, 8).map((p) => `<li>
        <span class="av">${ico(p.avatar)}</span><b>${esc(p.name)}</b>
        <span class="grow"></span>
        <button class="mini-btn" data-chal="${esc(p.name)}">Challenge</button>
        <button class="mini-btn" data-party="${esc(p.name)}">Party Up</button>
      </li>`).join('')}</ul></div>`);
  }
  if (!bits.length) {
    bits.push(`<div class="act-empty">Party invites, challenges and match results will land here — nicely, with a countdown to accept.</div>`);
  }
  box.innerHTML = bits.join('');
  const open = box.querySelector('[data-act="open-clash"]');
  if (open) open.onclick = () => go('clash');
  box.querySelectorAll('[data-chal]').forEach((b) => b.onclick = async () => {
    try {
      const r = await ctx.api('clash/challenge', { nickname: b.dataset.chal });
      ctx.toast('Challenge sent — they have 5 seconds to accept.');
      if (r.match) go('clash/' + r.match.id);
      else mountPlayActivity();
    } catch (e) { ctx.toast(e.message); }
  });
  box.querySelectorAll('[data-party]').forEach((b) => b.onclick = async () => {
    try {
      await ctx.api('clash/party/invite', { nickname: b.dataset.party });
      ctx.toast('Party invite sent to ' + b.dataset.party + '.');
    } catch (e) { ctx.toast(e.message); }
  });
}

function formatTime(s) {
  const n = Math.max(0, s | 0);
  return String(Math.floor(n / 60)) + ':' + String(n % 60).padStart(2, '0');
}
function escName(n) { return ctx.esc(n || 'them'); }
function $(s, el = document) { return el.querySelector(s); }

let partyTimer = 0;
let voiceCtl = null;
let replayTimer = 0;
let partyPick = [null, null];
let namePrompted = false;

function stopPartyPoll() {
  if (partyTimer) { clearInterval(partyTimer); partyTimer = 0; }
}

async function clickReady() {
  const { api, toast, go } = ctx;
  const box = $('#ccQueue');
  if (box) { box.hidden = false; box.innerHTML = `<div class="thinking">Searching for another party…</div><button class="mini-btn" id="ccCancelQ">Cancel</button>`; const b = $('#ccCancelQ'); if (b) b.onclick = cancelReady; }
  try {
    const d = await api('clash/party/ready', {});
    if (d.match) return go('clash/' + d.match.id);
    watchPartyQueue();
  } catch (e) { toast(e.message); if (box) box.hidden = true; }
}

async function cancelReady() {
  try { await ctx.api('clash/party/cancel', {}); } catch {}
  const box = $('#ccQueue');
  if (box) box.hidden = true;
  if (window.__ccPQ) { clearInterval(window.__ccPQ); window.__ccPQ = 0; }
}

function watchPartyQueue() {
  if (window.__ccPQ) clearInterval(window.__ccPQ);
  window.__ccPQ = setInterval(async () => {
    try {
      const d = await ctx.api('clash/inbox');
      if (d.partyMatch) { clearInterval(window.__ccPQ); window.__ccPQ = 0; ctx.go('clash/' + d.partyMatch.id); }
    } catch {}
  }, 1000);
}

async function openPartyUp(inbox) {
  const { esc, ico, api, toast } = ctx;
  const people = inbox?.recentPeople || [];
  const { box: modal, close } = clashOverlay(`
    <button class="x" type="button" aria-label="Close">×</button>
    <h2>Party Up</h2>
    <p class="muted">Invite people who played with you. When someone accepts, the host names the party.</p>
    <form class="clash-chal" id="puForm"><input id="puNick" maxlength="20" placeholder="Username" autocomplete="off" /><button class="btn" type="submit">Invite</button></form>
    <div class="pu-list">${people.length ? people.map((p) => `<button type="button" class="pu-row" data-nick="${esc(p.name)}"><span class="av">${ico(p.avatar)}</span><b>${esc(p.name)}</b><span>Invite</span></button>`).join('') : '<div class="lb-empty">Play a 1v1 or a party match first — then your recent opponents and teammates will show up here.</div>'}</div>
  `, 'cc-party-modal');
  const send = async (nick) => {
    try {
      const d = await api('clash/party/invite', { nickname: nick });
      toast('Party invite sent to ' + nick + ' — they have a few seconds to accept.');
      ensurePartyBar(d.party);
      close();
    } catch (e) { toast(e.message); }
  };
  modal.querySelector('#puForm').onsubmit = (e) => { e.preventDefault(); const n = modal.querySelector('#puNick').value.trim(); if (n) send(n); };
  modal.querySelectorAll('.pu-row').forEach((b) => b.onclick = () => send(b.dataset.nick));
}

function promptPartyName() {
  if (document.querySelector('.party-arena, .party-intro, .clash-wrap.results, .clash-wrap.arena')) return;
  if (namePrompted || document.getElementById('ccPartyName')) return;
  namePrompted = true;
  const { esc, api, toast } = ctx;
  const { box: modal } = clashOverlay(`
    <h2>Name your party</h2>
    <p class="muted">Someone accepted! This name shows up as @partyname VS @partyname at the start of a match.</p>
    <form class="clash-chal" id="pnForm"><input id="pnName" maxlength="20" placeholder="Party name" autocomplete="off" /><button class="btn" type="submit">Save</button></form>
  `);
  modal.id = 'ccPartyName';
  setTimeout(() => modal.querySelector('#pnName')?.focus(), 50);
  modal.querySelector('#pnForm').onsubmit = async (e) => {
    e.preventDefault();
    const name = modal.querySelector('#pnName').value.trim();
    if (!name) return;
    try {
      const d = await api('clash/party/name', { name });
      modal.remove();
      namePrompted = false;
      ensurePartyBar(d.party);
      toast('Party named @' + name);
    } catch (err) { toast(err.message); }
  };
}

function syncPartyClearance() {
  const bar = document.getElementById('ccPartyBar');
  if (!bar) {
    document.documentElement.style.removeProperty('--party-clearance');
    return;
  }
  const top = bar.getBoundingClientRect().top;
  const px = Math.max(0, Math.round(window.innerHeight - top)) + 10;
  document.documentElement.style.setProperty('--party-clearance', px + 'px');
}
if (!window.__ccPartyClearance) {
  window.__ccPartyClearance = true;
  window.addEventListener('resize', syncPartyClearance);
}

function ensurePartyBar(party) {
  let bar = document.getElementById('ccPartyBar');
  if (!party) {
    if (bar) bar.remove();
    closeChat();
    stopVoice();
    const cb = document.getElementById('ccChatBtn');
    if (cb) cb.hidden = true;
    document.body.classList.remove('has-party-bar');
    document.documentElement.style.removeProperty('--party-clearance');
    return;
  }
  const { esc, ico, api, toast } = ctx;
  const slots = 4;
  const mems = party.members || [];
  const members = mems.map((m) => `
    <span class="slotp pb-mem${m.you ? ' you' : ''}${m.host ? ' host' : ''}">
      <span class="av2">${ico(m.avatar)}</span><b>${esc(m.name)}</b>
      ${party.queued ? '<span class="rdy">READY</span>' : ''}
      ${party.youHost && !m.you ? `<button type="button" class="pb-boot" data-id="${m.id}" title="Boot">×</button>` : ''}
    </span>`).join('');
  const empties = Array.from({ length: Math.max(0, slots - mems.length) }, () =>
    `<button type="button" class="slotp empty pb-invite">+ Invite</button>`).join('');
  const html = `
    <div class="lab">Party${party.name ? ' · @' + esc(party.name) : ''}</div>
    <div class="pb-members">${members}${empties}</div>
    <div class="pb-acts">
      <button type="button" class="mini-btn" id="pbChat">Chat</button>
      <button type="button" class="pb-ai" id="pbAi" ${party.queued ? 'disabled' : ''}><span>Party vs AI</span></button>
      <button type="button" class="pb-leave" id="pbLeave">Leave</button>
      <button type="button" class="pb-ready" id="pbReady"><span>${party.queued ? 'Searching…' : 'Ready'}</span></button>
    </div>`;
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'ccPartyBar';
    bar.className = 'cc-party-bar rl-party';
    document.body.appendChild(bar);
  }
  bar.innerHTML = html;
  document.body.classList.add('has-party-bar');
  syncPartyClearance();
  requestAnimationFrame(syncPartyClearance);
  const chatBtn = bar.querySelector('#pbChat');
  if (chatBtn) chatBtn.onclick = () => toggleChat();
  bar.querySelector('#pbLeave').onclick = async () => {
    try { await api('clash/party/leave', {}); ensurePartyBar(null); toast('You left the party.'); } catch (e) { toast(e.message); }
  };
  const ready = bar.querySelector('#pbReady');
  if (ready) ready.onclick = () => party.queued ? cancelReady() : clickReady();
  const aiBtn = bar.querySelector('#pbAi');
  if (aiBtn) aiBtn.onclick = () => openAiPicker(true);
  bar.querySelectorAll('.pb-invite').forEach((b) => { b.onclick = () => openPartyUp(ctx.state.clashInbox || { party, recentPeople: [] }); });
  bar.querySelectorAll('.pb-boot').forEach((b) => b.onclick = async () => {
    try {
      const d = await api('clash/party/boot', { playerId: b.dataset.id });
      ensurePartyBar(d.party);
    } catch (e) { toast(e.message); }
  });
}

function showPartyInvite(ch) {
  if (!ch?.id) return;
  if (document.getElementById('playActivity')) { mountPlayActivity(); return; }
  if (document.querySelector('.cc-invite[data-id="' + ch.id + '"]')) return;
  const { esc, ico } = ctx;
  const el = document.createElement('div');
  el.className = 'cc-invite';
  el.dataset.id = ch.id;
  el.innerHTML = `
    <div class="cci-card">
      <div class="cci-ring" aria-hidden="true"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="16" class="bg"/><circle cx="18" cy="18" r="16" class="fg"/></svg><b class="cci-num">12</b></div>
      <div class="cci-body">
        <div class="cci-kicker">Party Up</div>
        <div class="cci-title"><span class="av">${ico(ch.from?.avatar || '⚡')}</span> ${esc(ch.from?.name || 'Someone')} invited you to a party!</div>
        <div class="cci-sub">${ch.partyName ? 'Party @' + esc(ch.partyName) + '. ' : ''}Accept before the timer runs out, then the host names the party.</div>
        <div class="cci-acts">
          <button class="btn" data-a="yes">Accept</button>
          <button class="pill-btn" data-a="no">Nope</button>
        </div>
      </div>
    </div>`;
  inviteHost().appendChild(el);
  requestAnimationFrame(() => el.classList.add('on'));
  if (ctx.sfx) try { ctx.sfx('pop'); } catch {}
  const tick = () => {
    const left = Math.max(0, ch.expiresAt - Date.now());
    const num = el.querySelector('.cci-num');
    if (num) num.textContent = String(Math.ceil(left / 1000));
    const fg = el.querySelector('.cci-ring .fg');
    const total = 12000;
    if (fg) {
      const c = 2 * Math.PI * 16;
      fg.style.strokeDasharray = String(c);
      fg.style.strokeDashoffset = String(c * (1 - left / total));
    }
    if (left <= 0) {
      clearInterval(el._t);
      el.classList.add('expired');
      setTimeout(() => dismissInvite(ch.id, false), 600);
    }
  };
  tick();
  el._t = setInterval(tick, 100);
  el.querySelector('[data-a="yes"]').onclick = async () => {
    try {
      const d = await ctx.api('clash/party/respond', { id: ch.id, accept: true });
      dismissInvite(ch.id, true);
      ensurePartyBar(d.party);
      if (d.needsName) { /* host names it */ }
      ctx.go('clash');
    } catch (e) { ctx.toast(e.message); dismissInvite(ch.id, true); }
  };
  el.querySelector('[data-a="no"]').onclick = async () => {
    try { await ctx.api('clash/party/respond', { id: ch.id, accept: false }); } catch {}
    dismissInvite(ch.id, true);
  };
}

const DEMO_REPLAYS = [
  { aName: 'Player', bName: 'Rival', plays: [
    { a: 'Water', b: 'Fire', name: 'Steam', emoji: '☁️' },
    { a: 'Cat', b: 'Chaos', name: 'Zoomies Apocalypse', emoji: '🐈' },
    { a: 'Fire', b: 'Politician', name: 'Hot Take', emoji: '🎤' },
    { a: 'Water', b: 'Water', name: 'Ocean', emoji: '🌊' },
    { a: 'Fire', b: 'Pizza', name: 'Wood-Fired Pizza', emoji: '🪵' },
    { a: 'Meme', b: 'Meme', name: 'Meme Overload', emoji: '🤯' },
  ]},
];

async function startReplayBg() {
  const stage = document.getElementById('ccReplay');
  if (!stage) return;
  if (replayTimer) { clearInterval(replayTimer); replayTimer = 0; }
  let list = [];
  try { const d = await ctx.api('clash/replays'); list = d.replays || []; } catch {}
  if (!list.length) list = DEMO_REPLAYS;
  const { esc, ico } = ctx;
  stage.innerHTML = '<div class="rp-stage"></div>';
  const box = stage.querySelector('.rp-stage');
  let n = 0;
  const spawn = () => {
    if (!document.getElementById('ccReplay')) { clearInterval(replayTimer); replayTimer = 0; return; }
    const r = list[n % list.length];
    const plays = r.plays && r.plays.length ? r.plays : DEMO_REPLAYS[0].plays;
    const pl = plays[n % plays.length];
    const aName = r.a?.name || r.aName || 'Player';
    const bName = r.b?.name || r.bName || 'Rival';
    const el = document.createElement('div');
    el.className = 'rp-card';
    const lane = 12 + ((n * 23) % 76);
    el.style.left = lane + '%';
    el.style.animationDuration = (8 + (n % 5)) + 's';
    el.innerHTML = `<div class="rp-lab">Replay</div>
      <div class="rp-vs">${esc(aName)} vs ${esc(bName)}</div>
      <div class="rp-eq">${esc(pl.a || '')} + ${esc(pl.b || '')}</div>
      <div class="rp-res">${pl.emoji ? ico(pl.emoji) + ' ' : ''}<b>${esc(pl.name || 'Combo')}</b></div>`;
    box.appendChild(el);
    setTimeout(() => el.remove(), 11000);
    n++;
  };
  spawn();
  setTimeout(spawn, 400);
  setTimeout(spawn, 1200);
  replayTimer = setInterval(spawn, 2200);
}

async function showPartyMatch(id) {
  const { view, api, toast, go } = ctx;
  stopPartyPoll();
  view.innerHTML = `<div class="wrap clash-wrap"><div class="thinking" style="justify-content:center;margin-top:60px">Loading the party match…</div></div>`;
  try {
    const d = await api('clash/party/match?id=' + encodeURIComponent(id));
    paintPartyMatch(d.match, d.now);
    partyTimer = setInterval(async () => {
      try {
        const n = await api('clash/party/match?id=' + encodeURIComponent(id));
        paintPartyMatch(n.match, n.now);
      } catch (e) {
        if (e.status === 404) { stopPartyPoll(); toast('Match not found.'); go('clash'); }
      }
    }, 1000);
  } catch (e) {
    toast(e.message);
    go('clash', true);
  }
}

function paintPartyMatch(m) {
  if (!m) return;
  const { view, esc, ico, go, api, toast } = ctx;
  ensureChatButton(m);
  if (m.status === 'intro') {
    view.innerHTML = `<div class="rl-results">
      <div class="rl-kicker" style="text-align:center;margin-top:12px">Party vs Party</div>
      <h1 class="rl-title" style="text-align:center">${esc(m.headline)}</h1>
      <div class="rl-panel" style="max-width:720px;margin:18px auto;padding:22px;display:flex;justify-content:space-around;align-items:center;gap:16px;flex-wrap:wrap">
        <div>${(m.youMembers || []).map((p) => `<div class="rl-p"><span class="av">${ico(p.avatar)}</span>${esc(p.name)}</div>`).join('')}</div>
        <div class="rl-plus">VS</div>
        <div>${(m.oppMembers || []).map((p) => `<div class="rl-p"><span class="av">${ico(p.avatar)}</span>${esc(p.name)}</div>`).join('')}</div>
      </div>
      <div class="rl-theme">3 sessions · 90 seconds · match the thing at the top</div>
    </div>`;
    return;
  }
  if (m.status === 'finished') {
    paintResults(m, !!document.getElementById('rlResults'));
    return;
  }
  const leftMs = Math.max(0, (m.endsAt || 0) - Date.now());
  const secs = Math.ceil(leftMs / 1000);
  const mm = Math.floor(secs / 60);
  const ss = String(secs % 60).padStart(2, '0');
  const target = m.target || {};
  const spectate = m.spectating;
  const slot = (i) => {
    const c = partyPick[i];
    if (!c) return `<div class="rl-slot" data-s="${i}"><div>Drop an element</div></div>`;
    return `<div class="rl-slot filled ${rarityClass(c)}"><div class="i">${ico(c.emoji)}</div><div>${esc(c.name)}</div></div>`;
  };
  const card = (p, opp) => `<div class="rl-playc${opp ? ' opp' : ''}${p.hit ? ' hit' : ''}">
    <div class="eq">${esc(p.player?.name || '')} · ${esc(p.a)} + ${esc(p.b)}</div>
    <div class="nm">${p.emoji ? ico(p.emoji) + ' ' : ''}${esc(p.name)}${p.hit ? ' · MATCH!' : ''}</div>
    <div class="pts">${p.score ? '+' + p.score : ''}${p.heat ? ' · ' + p.heat : ''}</div>
  </div>`;
  const sess = Math.min(3, m.session || (m.youAlive ? m.youSession + 1 : (m.oppSession + 1)) || 1);
  view.innerHTML = `
    <div class="rl-arena party-arena${spectate ? ' spectating' : ''}">
      <div class="rl-scoreboard">
        <div class="rl-sb-blue"><span class="rl-you-tag">YOU</span><span class="rl-sb-name">@${esc(m.youName)}</span><span class="rl-sb-pts">${m.youSession}</span></div>
        <div class="rl-sb-mid"><div class="rl-sb-time">${mm}:${ss}</div><div class="rl-sb-bo">${esc(m.headline)}</div></div>
        <div class="rl-sb-orange"><span class="rl-sb-pts">${m.oppSession}</span><span class="rl-sb-name">@${esc(m.oppName)}</span></div>
      </div>
      <div class="rl-theme">the thing · Session ${sess} / 3<br><span class="tgt">${target.emoji ? ico(target.emoji) + ' ' : ''}${esc(target.name || '???')}</span></div>
      ${heatMeter(lastHeat(m.youPlays))}
      ${boom ? `<div class="rl-boom${boom.heat ? ' heat-' + String(boom.heat).toLowerCase().replace(/\s+/g,'-') : ''}"><div class="word">${boom.kind}</div><div class="sub">${esc(boom.sub)}</div></div>` : ''}
      ${spectate ? `<div class="rl-theme" style="margin-top:8px;background:#5a1a1a">Your team is out — spectating. You can leave the party or exit to the menu.</div>` : ''}
      <div class="rl-grid">
        <div class="rl-panel rl-col blue"><h3>You</h3>${(m.youPlays || []).slice().reverse().map((p) => card(p, false)).join('') || '<div class="muted">Combine toward the thing.</div>'}</div>
        ${spectate ? `
        <div class="rl-panel rl-col"><h3>Teammates</h3>${(m.matePlays || []).slice().reverse().map((p) => card(p, false)).join('') || '<div class="muted">Waiting on your party…</div>'}</div>
        <div class="rl-panel rl-col orange"><h3>@${esc(m.oppName)} · Live</h3>${(m.oppPlays || []).slice().reverse().map((p) => card(p, true)).join('') || '<div class="muted">Waiting…</div>'}</div>` : `
        <div class="rl-panel rl-mid">
          <div class="rl-slots">${slot(0)}<div class="rl-plus">+</div>${slot(1)}</div>
          <div class="rl-acts">
            <button class="rl-go" id="pmGo" ${!partyPick[0]||!partyPick[1]||m.status!=='playing'?'disabled':''}><span>Combine</span></button>
          </div>
        </div>
        <div class="rl-panel rl-col"><h3>Teammates</h3>${(m.matePlays || []).slice().reverse().map((p) => card(p, false)).join('') || '<div class="muted">Waiting on your party…</div>'}</div>`}
      </div>
      ${spectate ? '' : `<div class="rl-panel rl-col orange" style="margin:12px 28px 0"><h3>@${esc(m.oppName)} · Live</h3><div style="display:flex;gap:8px;flex-wrap:wrap">${(m.oppPlays || []).slice().reverse().map((p) => card(p, true)).join('') || '<div class="muted">Waiting for the other party…</div>'}</div></div>`}
      ${spectate ? `<div class="rl-res-acts"><button class="btn-rl rl-back" id="pmLeave"><span>Leave Party</span></button><button class="btn-rl rl-back" id="pmExit"><span>Exit to menu</span></button></div>` : `
      <div class="rl-panel rl-hand" id="pmHand">
        ${(m.hand || []).slice(-24).map((e) => `<button type="button" class="el lo-el rl-el ${rarityClass(e)}${nameClass(e.name)}" data-tok="${esc(e.token)}" style="${nameVars(e.name)}" title="${esc(e.name)}"><span class="i">${ico(e.emoji)}</span><span class="n">${esc(e.name)}</span></button>`).join('')}
      </div>`}
    </div>`;
  if (!spectate) {
    const byTok = Object.fromEntries((m.hand || []).map((e) => [e.token, e]));
    const hand = document.getElementById('pmHand');
    if (hand) hand.onclick = (e) => {
      const b = e.target.closest('[data-tok]');
      if (!b) return;
      const card = byTok[b.dataset.tok];
      if (!card) return;
      if (!partyPick[0]) partyPick[0] = card;
      else if (!partyPick[1]) partyPick[1] = card;
      else partyPick = [card, null];
      paintPartyMatch(m);
    };
    const goBtn = document.getElementById('pmGo');
    if (goBtn) goBtn.onclick = () => doPartyPlay(m);
  } else {
    const lv = document.getElementById('pmLeave');
    if (lv) lv.onclick = async () => { try { await api('clash/party/leave', {}); } catch {} ensurePartyBar(null); go('clash'); };
    const ex = document.getElementById('pmExit');
    if (ex) ex.onclick = async () => { try { await api('clash/party/exit', { matchId: m.id }); } catch {} go('clash'); };
  }
  ensurePartyBar(ctx.state.clashInbox?.party);
}

async function doPartyPlay(m) {
  if (!partyPick[0] || !partyPick[1]) return;
  try {
    const d = await ctx.api('clash/party/play', { matchId: m.id, a: partyPick[0].token, b: partyPick[1].token });
    if (ctx.sfx) {
      const pl = d.play || {};
      const heat = pl.heat || pl.breakdown?.heat;
      if (pl.hit || pl.exact) ctx.sfx('exact');
      else if (heat === 'On Fire') ctx.sfx('heat-fire');
      else if (heat === 'Hot') ctx.sfx('heat-hot');
      else if (heat === 'Warm') ctx.sfx('heat-warm');
      else ctx.sfx('pop');
      if (pl.score) ctx.sfx('points');
    }
    const pl = d.play;
    if (pl) {
      const heat = pl.heat;
      const kind = pl.hit || pl.exact ? 'ON TARGET!' : (heat === 'On Fire' ? 'ON FIRE!' : (heat === 'Hot' ? 'HOT!' : (heat === 'Warm' ? 'WARM!' : 'COMBO!')));
      boom = { kind, heat, sub: `${pl.a || ''} + ${pl.b || ''} = ${pl.name} · +${pl.score || 0}` };
      setTimeout(() => { boom = null; }, 1800);
      if (pl.hit) ctx.toast('Your team matched the thing! Next session…');
    }
    partyPick = [d.play ? { token: d.play.token, name: d.play.name, emoji: d.play.emoji, rarity: d.play.rarity } : null, null];
    paintPartyMatch(d.match);
  } catch (e) { ctx.toast(e.message); }
}

function ensureChatButton(m) {
  let btn = document.getElementById('ccChatBtn');
  if (!btn) {
    btn = document.createElement('button');
    btn.id = 'ccChatBtn';
    btn.className = 'cc-chat-btn';
    btn.type = 'button';
    btn.textContent = 'Chat';
    document.body.appendChild(btn);
    btn.onclick = () => toggleChat();
  }
  btn.hidden = false;
}

function toggleChat() {
  const pan = document.getElementById('ccChatPanel');
  if (pan) { closeChat(); return; }
  openChat();
}

function closeChat() {
  document.getElementById('ccChatPanel')?.remove();
}

async function openChat() {
  const blocked = localStorage.getItem('cc_block_chat') === '1';
  const pan = document.createElement('aside');
  pan.id = 'ccChatPanel';
  pan.className = 'cc-chat-panel' + (blocked ? ' blocked' : '');
  pan.innerHTML = `
    <div class="chp-top">
      <b>Team chat</b>
      <button type="button" class="mini-btn" id="chpClose">Close</button>
    </div>
    <div class="chp-tools">
      <button type="button" class="mini-btn" id="chpLive">Live</button>
      <button type="button" class="mini-btn" id="chpMuteMe">Mute myself</button>
      <button type="button" class="mini-btn" id="chpMuteAll">Mute everyone</button>
      <button type="button" class="mini-btn${blocked ? ' on' : ''}" id="chpBlock">Block Chat</button>
    </div>
    <div class="chp-blur" ${blocked ? '' : 'hidden'}>Chat is blocked. You cannot type or see messages.</div>
    <div class="chp-log" id="chpLog"></div>
    <form class="chp-form" id="chpForm"><input id="chpIn" maxlength="240" placeholder="Message your team" ${blocked ? 'disabled' : ''} /><button class="btn" ${blocked ? 'disabled' : ''}>Send</button></form>`;
  document.body.appendChild(pan);
  pan.querySelector('#chpClose').onclick = closeChat;
  pan.querySelector('#chpBlock').onclick = () => {
    const on = localStorage.getItem('cc_block_chat') === '1' ? '0' : '1';
    localStorage.setItem('cc_block_chat', on);
    closeChat();
    openChat();
  };
  pan.querySelector('#chpLive').onclick = () => toggleVoice(pan);
  pan.querySelector('#chpMuteMe').onclick = () => {
    if (!voiceCtl) return ctx.toast('Turn on Live first.');
    voiceCtl.setMuteSelf(!voiceCtl.muteSelf);
    pan.querySelector('#chpMuteMe').classList.toggle('on', voiceCtl.muteSelf);
  };
  pan.querySelector('#chpMuteAll').onclick = () => {
    if (!voiceCtl) return ctx.toast('Turn on Live first.');
    voiceCtl.setMuteAll(!voiceCtl.muteAll);
    pan.querySelector('#chpMuteAll').classList.toggle('on', voiceCtl.muteAll);
  };
  if (!blocked) {
    pan.querySelector('#chpForm').onsubmit = async (e) => {
      e.preventDefault();
      const inp = pan.querySelector('#chpIn');
      const body = inp.value.trim();
      if (!body) return;
      try { await ctx.api('clash/party/chat', { body }); inp.value = ''; await refreshChat(); } catch (err) { ctx.toast(err.message); }
    };
    await refreshChat();
    pan._t = setInterval(refreshChat, 2000);
  }
}

async function refreshChat() {
  const log = document.getElementById('chpLog');
  if (!log || localStorage.getItem('cc_block_chat') === '1') return;
  try {
    const d = await ctx.api('clash/party/chat');
    log.innerHTML = (d.messages || []).map((m) => `<div class="chp-msg${m.mine ? ' mine' : ''}"><b>${ctx.esc(m.from?.name || '')}</b> ${ctx.esc(m.body)}</div>`).join('') || '<div class="muted">No messages yet.</div>';
    log.scrollTop = log.scrollHeight;
  } catch {}
}

async function toggleVoice(pan) {
  if (voiceCtl) { stopVoice(); pan.querySelector('#chpLive').classList.remove('on'); ctx.toast('Live voice off.'); return; }
  const inbox = ctx.state.clashInbox || {};
  const room = inbox.party?.voiceRoom || inbox.partyMatch?.voiceRoom;
  const me = ctx.state.me;
  if (!room || !me?.id) { ctx.toast('Join a party to use live voice.'); return; }
  const { createVoice } = await import('./voice.js?v=43');
  const base = (document.querySelector('base')?.href) || ctx.BASE;
  voiceCtl = createVoice({ apiBase: base, room, selfId: me.id, name: me.nickname || 'Player' });
  try {
    await voiceCtl.start();
    pan.querySelector('#chpLive').classList.add('on');
    ctx.toast('Live voice is on — audio only, no camera.');
  } catch (e) { ctx.toast(e.message || 'Could not start the mic.'); stopVoice(); }
}

function stopVoice() {
  try { voiceCtl?.stop(); } catch {}
  voiceCtl = null;
}

