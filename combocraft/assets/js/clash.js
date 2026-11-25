// Combo Clash — real-time PvP. Polled against a server-authoritative PHP match.
import { rarityClass } from './features.js?v=6';

let inboxTimer = 0;
let matchTimer = 0;
let shownInvite = new Set();
let ctx = null;
let attackMode = false;
let pick = [null, null];

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
    view.innerHTML = `<div class="wrap clash-wrap"><div class="clash-hero">
      <div class="ch-kicker">Combo Clash</div>
      <h1>Pick a display name first</h1>
      <p>Other players will see this when you challenge them, and on the “I beat @you” cards.</p>
      <button class="btn clash-lg" id="ccName">Choose a name</button>
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

  const last = inbox.lastScore;
  const party = inbox.party;
  view.innerHTML = `
    <div class="wrap clash-wrap lobby-has-replay">
      <div class="clash-replay" id="ccReplay" aria-hidden="true"></div>
      <div class="clash-hero clash-hero-front">
        <div class="ch-kicker">Combo Clash</div>
        <h1>Combo Clash</h1>
        <div class="clash-scoreboard" id="ccScoreboard">
          ${last ? `
            <div class="cs-you"><span class="av">${ico(last.you.avatar)}</span><b>${esc(last.you.name)}</b><em>${last.you.score}</em></div>
            <div class="cs-mid">${last.kind === 'party' ? 'Sessions' : 'VS'}</div>
            <div class="cs-opp"><em>${last.opp.score}</em><b>${esc(last.opp.name)}</b><span class="av">${ico(last.opp.avatar)}</span></div>
          ` : `<div class="cs-empty">Your last score will show up here after a match — with their username right across from yours.</div>`}
        </div>
        <div class="clash-actions">
          <button class="btn clash-lg" id="ccPartyUp">Party Up</button>
          <button class="btn clash-lg" id="ccReady">${party?.queued ? 'Searching…' : 'Ready'}</button>
          <button class="pill-btn clash-lg" id="ccOnline">Play Online (1v1)</button>
          <button class="pill-btn clash-lg" id="ccInvite">Challenge link</button>
        </div>
        <form class="clash-chal" id="ccChal">
          <input id="ccNick" maxlength="20" placeholder="Challenge a username" autocomplete="off" />
          <button class="btn" type="submit">Challenge</button>
        </form>
        <div id="ccQueue" class="clash-queue" ${party?.queued ? '' : 'hidden'}>${party?.queued ? '<div class="thinking">Searching for another party…</div><button class="mini-btn" id="ccCancelQ">Cancel</button>' : ''}</div>
      </div>
      <div class="clash-boards">
        ${boardBlock('Most wins', boards.wins.rows, (row) => `<b>${esc(row.name)}</b> <span>${row.wins} win${row.wins===1?'':'s'}</span>`)}
        ${boardBlock('Daily tournament', boards.daily.rows, (row) => `<b>${esc(row.name)}</b> <span>${row.points} pts · ${row.wins} wins</span>`, boards.daily.day ? 'Today · ' + boards.daily.day : 'Today')}
        ${boardBlock('Rarest winning combos', boards.rare.rows, (row) => `<b>${row.emoji ? ico(row.emoji) + ' ' : ''}${esc(row.name)}</b> <span>${esc(row.rarity)} · ${esc(row.recipe)} · ${esc(row.winner)}</span>`)}
      </div>
    </div>`;

  $('#ccOnline').onclick = () => joinQueue();
  $('#ccInvite').onclick = () => makeInvite();
  $('#ccPartyUp').onclick = () => openPartyUp(inbox);
  $('#ccReady').onclick = () => clickReady();
  const cq = $('#ccCancelQ'); if (cq) cq.onclick = () => cancelReady();
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

function boardBlock(title, rows, line, sub = '') {
  const { esc } = ctx;
  const body = (rows || []).length
    ? `<ol>${rows.map((r, i) => `<li><span class="rk">${r.rank || i + 1}</span>${line(r)}</li>`).join('')}</ol>`
    : `<div class="lb-empty">No battles yet. Be the first!</div>`;
  return `<section class="clash-board"><h2>${esc(title)}</h2>${sub ? `<div class="cb-sub">${esc(sub)}</div>` : ''}${body}</section>`;
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
  if (m.status === 'finished' || m.status === 'forfeit') return paintResults(m);
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
        <h2>Build your loadout</h2>
        <p>Pick 8–12 discoveries. Rarer cards hit harder in scoring.</p>
        <div class="lo-count"><b id="loN">${picked.size}</b> / 12 selected <span class="muted">(min 8)</span></div>
      </div>
      <div class="lo-grid" id="loGrid">
        ${(m.collection || []).map((e) => {
          const on = picked.has(e.id);
          return `<button type="button" class="el lo-el ${rarityClass(e)}${on ? ' on' : ''}" data-id="${e.id}" title="${esc(e.name)} · ${esc(e.rarity)}"><span class="i">${ico(e.emoji)}</span><span class="n">${esc(e.name)}</span><span class="rar-pill ${rarityClass(e)}">${esc(e.rarity)}</span></button>`;
        }).join('')}
      </div>
      <div class="clash-lo-act">
        <button class="btn clash-lg" id="loReady" ${picked.size < 8 || m.youReady ? 'disabled' : ''}>${m.youReady ? 'Waiting for ' + esc(m.opp.name) + '…' : 'I’m ready'}</button>
        <button class="pill-btn" id="loFF">Forfeit</button>
      </div>
    </div>`;
  if (!soft || !document.getElementById('loGrid')) view.innerHTML = html;
  else {
    // keep scroll; just refresh ready + counts
    const n = document.getElementById('loN'); if (n) n.textContent = picked.size;
    const r = document.getElementById('loReady');
    if (r) { r.disabled = picked.size < 8 || m.youReady; r.textContent = m.youReady ? 'Waiting for ' + m.opp.name + '…' : 'I’m ready'; }
  }
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

function paintArena(m, now, soft) {
  const { view, esc, ico } = ctx;
  const leftMs = m.status === 'inter'
    ? Math.max(0, (m.interUntil || 0) - Date.now())
    : Math.max(0, (m.endsAt || 0) - Date.now());
  const secs = Math.ceil(leftMs / 1000);
  const pip = (n) => {
    const you = m.youWins >= n ? 'you' : '';
    const opp = m.oppWins >= n ? 'opp' : '';
    return `<i class="${you || opp || (m.round === n ? 'on' : '')}"></i>`;
  };
  const hand = [...(m.loadout || []).map((e) => ({ ...e, token: e.token || ('e:' + e.id) })), ...(m.youPlays || [])];
  const slot = (i) => {
    const c = pick[i];
    if (!c) return `<div class="cl-slot" data-s="${i}"><span>+</span></div>`;
    return `<div class="cl-slot filled ${rarityClass(c)}" data-s="${i}"><span class="i">${ico(c.emoji)}</span><span class="n">${esc(c.name)}</span></div>`;
  };
  const playCard = (p, mine) => `<div class="cl-play ${rarityClass(p)}${p.attack ? ' atk' : ''}${p.breakdown?.attackWorks ? ' hit' : ''}">
    <div class="cp-eq">${esc(p.a)} + ${esc(p.b)}</div>
    <div class="cp-name">${p.emoji ? ico(p.emoji) + ' ' : ''}<b>${esc(p.name)}</b></div>
    <div class="cp-pts">+${p.score}${p.attack ? (p.breakdown?.attackWorks ? ' · counter landed' : ' · counter missed') : ''}</div>
  </div>`;

  const arena = `
    <div class="wrap clash-wrap arena">
      <div class="cl-top">
        <div class="cl-score you"><span class="av">${ico(m.you.avatar)}</span><b>${esc(m.you.name)}</b><em>${m.youScore}</em></div>
        <div class="cl-timer">
          <div class="pips">${pip(1)}${pip(2)}${pip(3)}</div>
          <div class="time ${secs <= 10 && m.status === 'playing' ? 'low' : ''}">${m.status === 'inter' ? 'Next' : ''}${formatTime(secs)}</div>
          <div class="bo3">Best of 3 · ${m.youWins}–${m.oppWins}</div>
        </div>
        <div class="cl-score opp"><span class="av">${ico(m.opp.avatar)}</span><b>${esc(m.opp.name)}</b><em>${m.oppScore}</em></div>
      </div>
      <div class="cl-theme">${m.status === 'inter' && m.lastRound
        ? `<div class="inter-banner">Round ${m.lastRound.n} ${m.lastRound.winner ? (m.lastRound.winner.name === m.you.name ? 'you win' : m.lastRound.winner.name + ' wins') : 'is a tie'} · ${m.lastRound.you}–${m.lastRound.opp}</div>`
        : `Theme: <b>${esc(m.theme || '')}</b>`}</div>
      <div class="cl-sides">
        <div class="cl-col you">
          <h3>Your creations</h3>
          <div class="cl-feed" id="youFeed">${(m.youPlays || []).slice().reverse().map((p) => playCard(p, true)).join('') || '<div class="muted">Combine two cards to score.</div>'}</div>
        </div>
        <div class="cl-col opp">
          <h3>${esc(m.opp.name)}’s creations</h3>
          <div class="cl-feed">${(m.oppPlays || []).slice().reverse().map((p) => playCard(p, false)).join('') || '<div class="muted">Waiting…</div>'}</div>
        </div>
      </div>
      <div class="cl-work ${m.status !== 'playing' ? 'off' : ''}">
        <div class="cl-slots">${slot(0)}<span class="plus">+</span>${slot(1)}</div>
        <div class="cl-acts">
          <button class="btn" id="clGo" ${!pick[0]||!pick[1]||m.status!=='playing'?'disabled':''}>Combine</button>
          <button class="pill-btn ${attackMode?'on':''}" id="clAtk" ${!m.oppLatest?'disabled':''}>Attack</button>
          <button class="mini-btn" id="clClear">Clear</button>
          <button class="mini-btn" id="loFF">Forfeit</button>
        </div>
        ${m.oppLatest ? `<div class="cl-target">Opponent’s latest: ${m.oppLatest.emoji ? ico(m.oppLatest.emoji)+' ' : ''}<b>${esc(m.oppLatest.name)}</b> — attack to try a counter</div>` : ''}
      </div>
      <div class="cl-hand" id="clHand">
        ${hand.map((e) => `<button type="button" class="el lo-el ${rarityClass(e)}" data-tok="${esc(e.token)}" title="${esc(e.name)}"><span class="i">${ico(e.emoji)}</span><span class="n">${esc(e.name)}</span></button>`).join('')}
      </div>
    </div>`;

  const keepPick = soft && document.getElementById('clHand');
  const prevHand = keepPick ? document.getElementById('clHand').innerHTML : '';
  const prevFeed = keepPick ? (document.getElementById('youFeed')?.innerHTML || '') : '';
  view.innerHTML = arena;
  // If only scores/timer changed, we already replaced — that's ok; rebind always.
  bindArena(m, hand);
}

function bindArena(m, hand) {
  const { api, toast, sfx } = ctx;
  const byTok = Object.fromEntries(hand.map((e) => [e.token, e]));
  const handEl = document.getElementById('clHand');
  if (handEl) handEl.onclick = (e) => {
    const b = e.target.closest('[data-tok]');
    if (!b || m.status !== 'playing') return;
    const card = byTok[b.dataset.tok];
    if (!card) return;
    if (!pick[0]) pick[0] = card;
    else if (!pick[1]) pick[1] = card;
    else { pick = [card, null]; }
    paintArena(m, m.now, false);
  };
  const go = document.getElementById('clGo');
  if (go) go.onclick = () => doPlay(m, false);
  const atk = document.getElementById('clAtk');
  if (atk) atk.onclick = () => {
    if (!pick[0] || !pick[1]) { attackMode = true; toast('Pick two cards, then Attack.'); return; }
    doPlay(m, true);
  };
  const clr = document.getElementById('clClear');
  if (clr) clr.onclick = () => { pick = [null, null]; paintArena(m, m.now, false); };
  const ff = document.getElementById('loFF');
  if (ff) ff.onclick = () => doForfeit(m.id);
}

async function doPlay(m, attack) {
  if (!pick[0] || !pick[1]) return;
  const { api, toast, sfx } = ctx;
  try {
    const d = await api('clash/play', { matchId: m.id, a: pick[0].token, b: pick[1].token, attack });
    if (ctx.sfx) sfx(d.play?.breakdown?.attackWorks ? 'fanfare' : 'success');
    pick = [d.play ? { token: d.play.token, name: d.play.name, emoji: d.play.emoji, rarity: d.play.rarity } : null, null];
    // leave the new result in slot 0 so chains are easy
    pick = [pick[0], null];
    attackMode = false;
    paintMatch(d.match, d.now);
  } catch (e) { toast(e.message); }
}

function paintResults(m) {
  stopMatchPoll();
  pick = [null, null];
  const { view, esc, ico, BASE, toast, go, api, state } = ctx;
  const won = m.winnerId === m.you.id;
  const share = m.share || {};
  const best = share.best || m.youLatest;
  const headline = share.headline || (won
    ? `I beat @${m.opp.name} with ${best?.a || '?'} + ${best?.b || '?'} = ${best?.name || 'this'}`
    : `${m.opp.name} beat me in Combo Clash`);
  const og = location.origin + BASE + 'og/clash/' + m.id + '.png';
  const url = location.origin + BASE + 'clash/' + m.id;
  view.innerHTML = `
    <div class="wrap clash-wrap results">
      <div class="cl-res-kicker">${m.status === 'forfeit' ? (won ? 'They left the match' : 'You left the match') : 'Best of 3'}</div>
      <h1>${won ? 'You win!' : 'Defeat'}</h1>
      <div class="cl-final">${m.youWins}–${m.oppWins} vs ${esc(m.opp.name)}</div>
      <div class="share-card cl-share ${best ? rarityClass(best) : ''}">
        <div class="sc-brand">Combo Clash</div>
        <div class="sc-head">${esc(headline)}</div>
        ${best ? `<div class="sc-art">${best.emoji ? ico(best.emoji) : ''} <b>${esc(best.name)}</b></div>
        <div class="sc-eq">${esc(best.a || '')} + ${esc(best.b || '')}</div>
        <div class="rar-pill ${rarityClass(best)}">${esc(best.rarity || '')}</div>` : ''}
      </div>
      <div class="cl-acts">
        <button class="btn clash-lg" id="clCopy">Copy share text</button>
        <button class="pill-btn clash-lg" id="clShare">Share</button>
        <button class="pill-btn clash-lg" id="clRevenge">Revenge</button>
        <button class="pill-btn clash-lg" id="clRemix" ${best?.a ? '' : 'disabled'}>Remix the winner’s creation</button>
        <button class="mini-btn" id="clLobby">Back to Clash</button>
      </div>
    </div>`;
  $('#clCopy').onclick = async () => {
    const text = headline + ' ' + url;
    try { await navigator.clipboard.writeText(text); toast('Copied!'); } catch { toast(text); }
  };
  $('#clShare').onclick = async () => {
    const text = headline + ' ' + url;
    try {
      if (navigator.share) await navigator.share({ title: 'Combo Clash', text: headline, url });
      else { await navigator.clipboard.writeText(text); toast('Copied!'); }
    } catch {}
  };
  $('#clRevenge').onclick = async () => {
    try {
      const d = await api('clash/revenge', { matchId: m.id });
      if (d.match) go('clash/' + d.match.id);
      else toast('Revenge challenge sent — they have 5 seconds to accept.');
    } catch (e) { toast(e.message); }
  };
  $('#clRemix').onclick = () => remixWinner(best);
  $('#clLobby').onclick = () => { go('clash'); };
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
    for (const ch of d.incoming || []) {
      if (ch.status === 'pending' && ch.expiresAt > Date.now()) showInvitePopup(ch);
    }
    for (const ch of d.partyInvites || []) {
      if (ch.status === 'pending' && ch.expiresAt > Date.now()) showPartyInvite(ch);
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
  const modal = document.createElement('div');
  modal.className = 'modal on cc-party-modal';
  modal.innerHTML = `<div class="card" style="max-width:420px">
    <button class="x" type="button" aria-label="Close">×</button>
    <h2>Party Up</h2>
    <p class="muted">Invite people who played with you. When someone accepts, the host names the party.</p>
    <form class="clash-chal" id="puForm"><input id="puNick" maxlength="20" placeholder="Username" autocomplete="off" /><button class="btn" type="submit">Invite</button></form>
    <div class="pu-list">${people.length ? people.map((p) => `<button type="button" class="pu-row" data-nick="${esc(p.name)}"><span class="av">${ico(p.avatar)}</span><b>${esc(p.name)}</b><span>Invite</span></button>`).join('') : '<div class="lb-empty">Play a 1v1 or a party match first — then your recent opponents and teammates will show up here.</div>'}</div>
  </div>`;
  document.body.appendChild(modal);
  const close = () => modal.remove();
  modal.querySelector('.x').onclick = close;
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
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
  if (namePrompted || document.getElementById('ccPartyName')) return;
  namePrompted = true;
  const { esc, api, toast } = ctx;
  const modal = document.createElement('div');
  modal.className = 'modal on';
  modal.id = 'ccPartyName';
  modal.innerHTML = `<div class="card" style="max-width:420px">
    <h2>Name your party</h2>
    <p class="muted">Someone accepted! This name shows up as @partyname VS @partyname at the start of a match.</p>
    <form class="clash-chal" id="pnForm"><input id="pnName" maxlength="20" placeholder="Party name" autocomplete="off" /><button class="btn" type="submit">Save</button></form>
  </div>`;
  document.body.appendChild(modal);
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

function ensurePartyBar(party) {
  let bar = document.getElementById('ccPartyBar');
  if (!party) {
    if (bar) bar.remove();
    closeChat();
    stopVoice();
    const cb = document.getElementById('ccChatBtn');
    if (cb) cb.hidden = true;
    document.body.classList.remove('has-party-bar');
    return;
  }
  const { esc, ico, api, toast } = ctx;
  const html = `
    <div class="pb-members">${(party.members || []).map((m) => `
      <span class="pb-mem${m.you ? ' you' : ''}${m.host ? ' host' : ''}" title="${esc(m.name)}${m.host ? ' · host' : ''}">
        <span class="av">${ico(m.avatar)}</span><b>${esc(m.name)}</b>
        ${party.youHost && !m.you ? `<button type="button" class="pb-boot" data-id="${m.id}" title="Boot">×</button>` : ''}
      </span>`).join('')}</div>
    <div class="pb-name">${party.name ? '@' + esc(party.name) : 'Unnamed party'}${party.queued ? ' · searching' : ''}</div>
    <button type="button" class="mini-btn" id="pbLeave">Leave Party</button>`;
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'ccPartyBar';
    bar.className = 'cc-party-bar';
    document.body.appendChild(bar);
  }
  bar.innerHTML = html;
  document.body.classList.add('has-party-bar');
  bar.querySelector('#pbLeave').onclick = async () => {
    try { await api('clash/party/leave', {}); ensurePartyBar(null); toast('You left the party.'); } catch (e) { toast(e.message); }
  };
  bar.querySelectorAll('.pb-boot').forEach((b) => b.onclick = async () => {
    try {
      const d = await api('clash/party/boot', { playerId: b.dataset.id });
      ensurePartyBar(d.party);
    } catch (e) { toast(e.message); }
  });
}

function showPartyInvite(ch) {
  if (!ch?.id || document.querySelector('.cc-invite[data-id="' + ch.id + '"]')) return;
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

async function startReplayBg() {
  const stage = document.getElementById('ccReplay');
  if (!stage) return;
  let list = [];
  try { const d = await ctx.api('clash/replays'); list = d.replays || []; } catch {}
  if (!list.length) {
    stage.innerHTML = `<div class="rp-ghost">Recent match replays will play back here.</div>`;
    return;
  }
  let i = 0, j = 0;
  const paint = () => {
    const r = list[i % list.length];
    const plays = r.plays || [];
    const pl = plays[j] || null;
    const aName = r.a?.name || r.aName || 'Player';
    const bName = r.b?.name || r.bName || 'Rival';
    stage.innerHTML = `<div class="rp-lab">Replay</div>
      <div class="rp-vs">${ctx.esc(aName)} vs ${ctx.esc(bName)}</div>
      ${pl ? `<div class="rp-play ${pl.side || ''} ${pl.hit ? 'hit' : ''}">${pl.emoji ? ctx.ico(pl.emoji) + ' ' : ''}<b>${ctx.esc(pl.name)}</b><div class="rp-eq">${ctx.esc(pl.a || '')} + ${ctx.esc(pl.b || '')}</div></div>` : '<div class="rp-play">A packed Combo Clash</div>'}`;
    j++;
    if (j >= Math.max(1, plays.length)) { j = 0; i++; }
  };
  paint();
  if (replayTimer) clearInterval(replayTimer);
  replayTimer = setInterval(paint, 1600);
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
    view.innerHTML = `<div class="wrap clash-wrap party-intro">
      <div class="pi-kicker">Party vs Party</div>
      <h1>${esc(m.headline)}</h1>
      <div class="pi-sides">
        <div>${(m.youMembers || []).map((p) => `<div class="avline"><span class="av">${ico(p.avatar)}</span>${esc(p.name)}</div>`).join('')}</div>
        <div class="pi-vs">VS</div>
        <div>${(m.oppMembers || []).map((p) => `<div class="avline"><span class="av">${ico(p.avatar)}</span>${esc(p.name)}</div>`).join('')}</div>
      </div>
      <div class="muted">3 sessions · 90 seconds each · match the thing at the top</div>
    </div>`;
    return;
  }
  if (m.status === 'finished') {
    stopPartyPoll();
    view.innerHTML = `<div class="wrap clash-wrap results">
      <div class="cl-res-kicker">Party Clash</div>
      <h1>${m.youWon ? 'Your party wins!' : 'Defeat'}</h1>
      <div class="cl-final">${esc(m.headline)}</div>
      <div class="cl-final">Sessions ${m.youSession}–${m.oppSession}</div>
      <div class="cl-acts">
        <button class="btn clash-lg" id="pmReady">Ready</button>
        <button class="pill-btn clash-lg" id="pmLobby">Exit to menu</button>
      </div>
    </div>`;
    $('#pmReady').onclick = () => clickReady();
    $('#pmLobby').onclick = async () => { try { await api('clash/party/exit', { matchId: m.id }); } catch {} go('clash'); };
    return;
  }
  const leftMs = Math.max(0, (m.endsAt || 0) - Date.now());
  const secs = Math.ceil(leftMs / 1000);
  const target = m.target || {};
  const spectate = m.spectating;
  const slot = (i) => {
    const c = partyPick[i];
    if (!c) return `<div class="cl-slot" data-s="${i}"><span>+</span></div>`;
    return `<div class="cl-slot filled ${rarityClass(c)}"><span class="i">${ico(c.emoji)}</span><span class="n">${esc(c.name)}</span></div>`;
  };
  const card = (p) => `<div class="cl-play ${p.hit ? 'hit' : ''} ${rarityClass(p)}">
    <div class="cp-eq">${esc(p.player?.name || '')} · ${esc(p.a)} + ${esc(p.b)}</div>
    <div class="cp-name">${p.emoji ? ico(p.emoji) + ' ' : ''}<b>${esc(p.name)}</b>${p.hit ? ' · MATCH!' : ''}</div>
  </div>`;
  view.innerHTML = `
    <div class="wrap clash-wrap arena party-arena${spectate ? ' spectating' : ''}">
      <div class="cl-theme party-target">the thing · Session ${Math.min(3, (m.youAlive ? m.youSession + 1 : m.youSession) || 1)} / 3<br>
        <b>${target.emoji ? ico(target.emoji) + ' ' : ''}${esc(target.name || '???')}</b>
      </div>
      <div class="cl-top">
        <div class="cl-score you"><b>@${esc(m.youName)}</b> <em>${m.youSession}</em></div>
        <div class="cl-timer"><div class="time ${secs <= 15 && !spectate ? 'low' : ''}">${formatTime(secs)}</div><div class="bo3">${esc(m.headline)}</div></div>
        <div class="cl-score opp"><b>@${esc(m.oppName)}</b> <em>${m.oppSession}</em></div>
      </div>
      ${spectate ? `<div class="inter-banner">Your team is defeated this session — spectating teammates and the other party. You can leave the party or exit to the menu.</div>` : ''}
      <div class="cl-sides party-3">
        <div class="cl-col"><h3>You</h3><div class="cl-feed">${(m.youPlays || []).slice().reverse().map(card).join('') || '<div class="muted">Combine toward the thing.</div>'}</div></div>
        <div class="cl-col"><h3>Teammates</h3><div class="cl-feed">${(m.matePlays || []).slice().reverse().map(card).join('') || '<div class="muted">Waiting on your party…</div>'}</div></div>
        <div class="cl-col"><h3>@${esc(m.oppName)}</h3><div class="cl-feed">${(m.oppPlays || []).slice().reverse().map(card).join('') || '<div class="muted">…</div>'}</div></div>
      </div>
      ${spectate ? `<div class="cl-acts"><button class="pill-btn" id="pmLeave">Leave Party</button><button class="mini-btn" id="pmExit">Exit to menu</button></div>` : `
      <div class="cl-work ${m.status !== 'playing' ? 'off' : ''}">
        <div class="cl-slots">${slot(0)}<span class="plus">+</span>${slot(1)}</div>
        <div class="cl-acts">
          <button class="btn" id="pmGo" ${!partyPick[0]||!partyPick[1]?'disabled':''}>Combine</button>
          <button class="mini-btn" id="pmClear">Clear</button>
        </div>
      </div>
      <div class="cl-hand" id="pmHand">
        ${(m.hand || []).slice(-24).map((e) => `<button type="button" class="el lo-el ${rarityClass(e)}" data-tok="${esc(e.token)}"><span class="i">${ico(e.emoji)}</span><span class="n">${esc(e.name)}</span></button>`).join('')}
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
    const clr = document.getElementById('pmClear');
    if (clr) clr.onclick = () => { partyPick = [null, null]; paintPartyMatch(m); };
  } else {
    const lv = document.getElementById('pmLeave');
    if (lv) lv.onclick = async () => { try { await api('clash/party/leave', {}); } catch {} ensurePartyBar(null); go('clash'); };
    const ex = document.getElementById('pmExit');
    if (ex) ex.onclick = async () => { try { await api('clash/party/exit', { matchId: m.id }); } catch {} go('clash'); };
  }
}

async function doPartyPlay(m) {
  if (!partyPick[0] || !partyPick[1]) return;
  try {
    const d = await ctx.api('clash/party/play', { matchId: m.id, a: partyPick[0].token, b: partyPick[1].token });
    if (ctx.sfx) ctx.sfx(d.play?.hit ? 'fanfare' : 'success');
    if (d.play?.hit) ctx.toast('Your team matched the thing! Next session…');
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
  const { createVoice } = await import('./voice.js?v=6');
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

