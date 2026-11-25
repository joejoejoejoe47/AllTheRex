// Combo Clash — real-time PvP. Polled against a server-authoritative PHP match.
import { rarityClass } from './features.js?v=5';

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
  const params = new URLSearchParams(location.search);
  const inviteId = params.get('invite') || params.get('c') || '';

  if (!state.me?.nickname) {
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

  const inbox = await api('clash/inbox').catch(() => ({ incoming: [], outgoing: [], match: null }));
  if (inbox.match) return go('clash/' + inbox.match.id, true);

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

  view.innerHTML = `
    <div class="wrap clash-wrap">
      <div class="clash-hero">
        <div class="ch-kicker">Best of 3 · 75-second rounds</div>
        <h1>Combo Clash</h1>
        <p>Build a loadout from your discoveries, then combine them live against another player. Same theme, AI-judged scores, and a Revenge button when you want one more round.</p>
        <div class="clash-actions">
          <button class="btn clash-lg" id="ccOnline">Play Online</button>
          <button class="pill-btn clash-lg" id="ccInvite">Challenge link</button>
        </div>
        <form class="clash-chal" id="ccChal">
          <input id="ccNick" maxlength="20" placeholder="Challenge a username" autocomplete="off" />
          <button class="btn" type="submit">Challenge</button>
        </form>
        <div id="ccQueue" class="clash-queue" hidden></div>
      </div>
      <div class="clash-boards">
        ${boardBlock('Most wins', boards.wins.rows, (row) => `<b>${esc(row.name)}</b> <span>${row.wins} win${row.wins===1?'':'s'}</span>`)}
        ${boardBlock('Daily tournament', boards.daily.rows, (row) => `<b>${esc(row.name)}</b> <span>${row.points} pts · ${row.wins} wins</span>`, boards.daily.day ? 'Today · ' + boards.daily.day : 'Today')}
        ${boardBlock('Rarest winning combos', boards.rare.rows, (row) => `<b>${row.emoji ? ico(row.emoji) + ' ' : ''}${esc(row.name)}</b> <span>${esc(row.rarity)} · ${esc(row.recipe)} · ${esc(row.winner)}</span>`)}
      </div>
    </div>`;

  $('#ccOnline').onclick = () => joinQueue();
  $('#ccInvite').onclick = () => makeInvite();
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
    else if (!pick[1] && pick[0].token !== card.token) pick[1] = card;
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
    if (d.match && !onClashMatch && shouldFollowMatch(d.match)) {
      ctx.go('clash/' + d.match.id);
      return;
    }
    for (const ch of d.incoming || []) {
      if (ch.status === 'pending' && ch.expiresAt > Date.now()) showInvitePopup(ch);
    }
    // drop popups that expired
    const live = new Set((d.incoming || []).filter((c) => c.status === 'pending' && c.expiresAt > Date.now()).map((c) => c.id));
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
