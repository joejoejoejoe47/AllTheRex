// Daily challenge, achievements, hints, recipe book, speedrun, viral shares and Morse coins.
import { sfx, ico, artHtml, esc, confetti, svg, safeEmoji } from './fx.js?v=12';

export function applyMe(state, d) {
  state.daily = d.daily || state.daily || null;
  state.hints = d.hints || state.hints || { left: 3, used: 0, limit: 3 };
  state.progress = d.progress || state.progress || { unlocked: 0, world: 0, pct: 0 };
  state.badges = d.badges || state.badges || [];
  state.referral = d.referral || state.referral || null;
  state.morse = d.morse || state.morse || null;
}

export function rarityClass(el) {
  const r = (el?.rarity || 'Common').toLowerCase();
  return r === 'legendary' ? 'r-leg' : r === 'epic' ? 'r-epic' : r === 'rare' ? 'r-rare' : 'r-common';
}

export function playChrome(state) {
  const d = state.daily;
  const h = state.hints || { left: 3 };
  const p = state.progress || { unlocked: 0, world: 0, pct: 0 };
  const target = d?.target;
  const solved = d?.solved;
  return `
    <div class="daily-bar${solved ? ' solved' : ''}" id="dailyBar">
      <div class="db-main">
        <div class="db-kicker">Daily Challenge${d?.day ? ' · ' + d.day : ''}</div>
        <div class="db-target">${target ? `${ico(target.emoji)} <b>Make ${esc(target.name)}</b>` : 'Loading today’s target…'}
          ${solved ? '<span class="db-done">Solved</span>' : ''}</div>
      </div>
      <div class="db-meta">
        <span class="db-streak" title="Daily streak">🔥 ${d?.streak || 0}</span>
        <button class="mini-btn" id="hintBtn" ${h.left <= 0 ? 'disabled' : ''}>Hint · ${h.left}</button>
        <button class="mini-btn" id="dailyShare" ${solved ? '' : 'hidden'}>Share</button>
      </div>
    </div>
    <div class="world-bar" id="worldBar" title="How much of the known world you’ve unlocked">
      <div class="wb-track"><i style="width:${p.pct || 0}%"></i></div>
      <span>${p.unlocked || 0} / ${p.world || 0} unlocked</span>
      <a href="achievements" data-link class="wb-link">Achievements</a>
      <a href="speedrun" data-link class="wb-link">Speedrun</a>
      <a href="clash" data-link class="wb-link">Clash</a>
    </div>`;
}

export function bindPlayChrome({ api, toast, state, go, openRecipe }) {
  const hint = $('#hintBtn');
  if (hint) hint.onclick = async () => {
    try {
      const d = await api('hint', {});
      state.hints = d.hints || state.hints;
      hint.textContent = 'Hint · ' + (state.hints.left ?? 0);
      hint.disabled = (state.hints.left ?? 0) <= 0;
      toast(`Try ${d.a.name} + ${d.b.name} — ${d.tease || 'it makes something new!'}`);
      flashElements([d.a.id, d.b.id]);
      if (d.badges) showBadges(d.badges, toast);
    } catch (e) { toast(e.message); }
  };
  const share = $('#dailyShare');
  if (share) share.onclick = () => shareDaily(state, toast);
}

function flashElements(ids) {
  ids.forEach((id) => {
    const el = document.querySelector(`#strip .el[data-id="${id}"]`);
    if (el) {
      el.classList.add('hint-flash');
      el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      setTimeout(() => el.classList.remove('hint-flash'), 2200);
    }
  });
}

export function onCombineExtras(res, { state, toast, shake, burst }) {
  if (res.daily) state.daily = res.daily;
  if (res.progress) state.progress = res.progress;
  if (res.me) state.me = res.me;
  const rarity = res.result?.rarity || 'Common';
  shake(rarity === 'Legendary' ? 0.7 : rarity === 'Epic' ? 0.45 : 0.28);
  burst(rarity);
  if (res.badges?.length) {
    state.badges = mergeBadges(state.badges, res.badges);
    showBadges(res.badges, toast);
  }
  if (res.coins?.justGranted) {
    toast(`+${res.coins.justGranted} Morse coins for your First Discovery!`);
  }
  if (res.daily?.justSolved) {
    setTimeout(() => dailyWin(state, toast), 600);
  }
  const bar = document.querySelector('#worldBar span');
  if (bar && state.progress) {
    bar.textContent = `${state.progress.unlocked} / ${state.progress.world} unlocked`;
    const i = document.querySelector('#worldBar .wb-track i');
    if (i) i.style.width = (state.progress.pct || 0) + '%';
  }
  const db = document.querySelector('#dailyBar');
  if (db && res.daily?.justSolved) {
    db.classList.add('solved');
    const done = db.querySelector('.db-done') || document.createElement('span');
    done.className = 'db-done';
    done.textContent = 'Solved';
    db.querySelector('.db-target')?.appendChild(done);
    const sh = document.querySelector('#dailyShare');
    if (sh) sh.hidden = false;
  }
}

function mergeBadges(all, added) {
  const map = new Map((all || []).map((b) => [b.id, b]));
  for (const b of added) map.set(b.id, { ...map.get(b.id), ...b, unlocked: true });
  return [...map.values()];
}

export function showBadges(badges, toast) {
  badges.forEach((b, i) => {
    setTimeout(() => toast(`${b.icon || '🏅'} ${b.title} — ${b.detail}`, 3200), i * 700);
  });
}

export async function openRecipe(id, { api, toast, ico, artHtml, BASE }) {
  let d;
  try { d = await api('recipe/' + id); } catch (e) { toast(e.message); return; }
  const el = d.element;
  const from = d.yours || d.madeFrom;
  const fromHtml = from
    ? `<div class="rb-row">${ico(from[0].emoji)} <b>${esc(from[0].name)}</b> <span class="op">+</span> ${ico(from[1].emoji)} <b>${from[1].name}</b></div>`
    : '<p class="muted">A starting element — it was never crafted.</p>';
  const makes = (d.makes || []).map((m) => `
    <button class="rb-make" data-id="${m.owned ? m.result.id : ''}">
      <span>with ${ico(m.with.emoji)} ${esc(m.with.name)}</span>
      <span class="op">=</span>
      <span>${m.owned ? `${ico(m.result.emoji)} ${esc(m.result.name)}` : '❓ ???'}</span>
    </button>`).join('') || '<p class="muted">Nothing known yet. Try combining it!</p>';
  const wrap = document.createElement('div');
  wrap.className = 'overlay';
  wrap.innerHTML = `<div class="modal book">
    <button class="x" aria-label="Close">${svg.close}</button>
    <div class="rb-hero ${rarityClass(el)}">${artHtml(el, BASE)}</div>
    <h3>${esc(el.name)}</h3>
    <div class="rar-pill ${rarityClass(el)}">${esc(el.rarity || 'Common')}</div>
    ${el.tagline ? `<p class="muted">${esc(el.tagline)}</p>` : ''}
    <h4>How it was made</h4>${fromHtml}
    ${d.yours && d.madeFrom && (d.yours[0].id !== d.madeFrom[0].id || d.yours[1].id !== d.madeFrom[1].id) ? `<p class="muted">First ever: ${esc(d.madeFrom[0].name)} + ${esc(d.madeFrom[1].name)}</p>` : ''}
    <h4>What it has made</h4>
    <div class="rb-makes">${makes}</div>
  </div>`;
  document.body.appendChild(wrap);
  const close = () => wrap.remove();
  wrap.querySelector('.x').onclick = close;
  wrap.addEventListener('click', (e) => { if (e.target === wrap) close(); });
  wrap.querySelectorAll('.rb-make[data-id]').forEach((b) => {
    b.onclick = () => { if (b.dataset.id) { close(); openRecipe(+b.dataset.id, { api, toast, ico, artHtml, BASE }); } };
  });
}

export async function pageAchievements({ api, state, view, toast }) {
  document.title = 'Achievements — ComboCraft';
  view.innerHTML = '<div class="wrap"><div class="thinking" style="justify-content:center;margin-top:60px">Loading <span class="dots"><i></i><i></i><i></i></span></div></div>';
  let d;
  try { d = await api('achievements'); } catch (e) { view.innerHTML = `<div class="wrap"><div class="lb-empty"><b>Couldn't load achievements</b>${esc(e.message)}</div></div>`; return; }
  state.badges = d.badges;
  state.progress = d.progress;
  const p = d.progress || {};
  const cards = d.badges.map((b) => `
    <div class="ach ${b.unlocked ? 'on' : ''}">
      <div class="ach-ico">${b.icon}</div>
      <div><b>${esc(b.title)}</b><span>${esc(b.detail)}</span></div>
    </div>`).join('');
  view.innerHTML = `<div class="wrap achs">
    <h1>Achievements</h1>
    <div class="world-bar lg"><div class="wb-track"><i style="width:${p.pct || 0}%"></i></div>
      <span>${p.unlocked || 0} / ${p.world || 0} of the known world unlocked</span></div>
    <div class="ach-grid">${cards}</div>
  </div>`;
}

export async function pageSpeedrun({ api, state, view, toast, sfx, shake, burst, onExtras, go }) {
  document.title = 'Speedrun — ComboCraft';
  view.innerHTML = '<div class="wrap"><div class="thinking" style="justify-content:center;margin-top:60px">Loading <span class="dots"><i></i><i></i><i></i></span></div></div>';
  const board = await api('speedrun/board');
  const targets = board.targets || [];
  const startHtml = `
    <div class="wrap speed">
      <h1>Speedrun</h1>
      <p class="lead">Start with the 12 base elements. Reach the target in as few combos as you can.</p>
      <div class="sr-targets">${targets.map((t) => `<button class="el ${rarityClass(t)}" data-tid="${t.id}" style="--c:#7b3fe4">${ico(t.emoji)}<span class="n">${esc(t.name)}</span></button>`).join('') || '<p>Targets will appear as seed elements are discovered.</p>'}</div>
      <h2>Leaderboard</h2>
      <div class="select" style="margin:8px 0 14px"><select id="srSel">${targets.map((t) => `<option value="${t.id}" ${t.id === board.targetId ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>
      <div id="srBoard">${renderSrRows(board.rows)}</div>
    </div>`;
  view.innerHTML = startHtml;
  const loadBoard = async (id) => {
    const b = await api('speedrun/board?target=' + id);
    $('#srBoard').innerHTML = renderSrRows(b.rows);
  };
  $('#srSel')?.addEventListener('change', (e) => loadBoard(e.target.value));
  view.onclick = async (e) => {
    const t = e.target.closest('[data-tid]');
    if (!t) return;
    try {
      const run = await api('speedrun/start', { targetId: +t.dataset.tid });
      state.run = run;
      renderRun({ api, state, view, toast, sfx, shake, burst, onExtras, go });
    } catch (er) { toast(er.message); }
  };
}

function renderSrRows(rows) {
  if (!rows?.length) return '<div class="lb-empty"><b>No times yet</b>Be the first to finish this target.</div>';
  return rows.map((r) => `<div class="lb-row"><div class="rk">${r.rank}.</div><div class="who"><b>${esc(r.nickname)}</b><span>${r.combos} combos · ${fmtMs(r.ms)}</span></div></div>`).join('');
}

function fmtMs(ms) {
  const s = Math.floor(ms / 1000);
  const m = Math.floor(s / 60);
  return m > 0 ? `${m}:${String(s % 60).padStart(2, '0')}` : `${s}.${String(Math.floor((ms % 1000) / 100))}s`;
}

function renderRun({ api, state, view, toast, sfx, shake, burst, onExtras, go }) {
  const run = state.run;
  const t = run.target;
  const tick = () => {
    const el = document.querySelector('#srTimer');
    if (!el || !state.run || state.run.done) return;
    el.textContent = fmtMs(Date.now() - (state.run._t0 || Date.now()));
    state.run._raf = requestAnimationFrame(tick);
  };
  state.run._t0 = Date.now() - (run.ms || 0);
  view.innerHTML = `<div class="wrap play speed-run">
    <div class="daily-bar">
      <div class="db-main"><div class="db-kicker">Speedrun</div>
        <div class="db-target">${t ? `${ico(t.emoji)} <b>Reach ${esc(t.name)}</b>` : ''}</div></div>
      <div class="db-meta"><span id="srCombos">${run.combos} combos</span><span id="srTimer">${fmtMs(run.ms || 0)}</span>
        <button class="mini-btn" id="srQuit">Quit</button></div>
    </div>
    <div class="strip-wrap"><div class="strip all" id="srStrip"></div></div>
    <section class="combine"><h2>Combine</h2>
      <div class="slots" id="srSlots">
        <div class="slot" data-slot="0"></div>
        <div class="plus">+</div>
        <div class="slot" data-slot="1"></div>
      </div>
      <div class="result-zone" id="srResult"></div>
    </section>
  </div>`;
  const paint = () => {
    const strip = document.querySelector('#srStrip');
    strip.innerHTML = (state.run.elements || []).map((el) =>
      `<button class="el ${rarityClass(el)}" data-id="${el.id}" style="--c:#7b3fe4">${ico(el.emoji)}<span class="n">${esc(el.name)}</span></button>`
    ).join('');
  };
  const slots = [null, null];
  const paintSlots = () => {
    document.querySelectorAll('#srSlots .slot').forEach((s, i) => {
      const el = slots[i];
      if (!el) { s.className = 'slot'; s.innerHTML = '<div class="dot"></div><div>Drop an element</div>'; }
      else { s.className = 'slot full'; s.innerHTML = `<div class="big">${ico(el.emoji)}</div><div class="nm">${esc(el.name)}</div>`; }
    });
  };
  paint(); paintSlots(); tick();
  document.querySelector('#srQuit').onclick = () => { cancelAnimationFrame(state.run?._raf); state.run = null; go('speedrun'); };
  document.querySelector('#srStrip').onclick = async (e) => {
    const b = e.target.closest('.el');
    if (!b || state.run.done) return;
    const el = (state.run.elements || []).find((x) => x.id === +b.dataset.id);
    if (!el) return;
    if (!slots[0]) slots[0] = el;
    else if (!slots[1]) slots[1] = el;
    else { slots[0] = el; slots[1] = null; }
    paintSlots();
    if (slots[0] && slots[1]) {
      try {
        const res = await api('speedrun/combine', { runId: state.run.id, a: slots[0].id, b: slots[1].id });
        if (res.pending) {
          toast('Asking the AI…');
          // resolve through the same relay the main game uses is handled server-side if cached; otherwise drop out
        }
        state.run = res.run || state.run;
        document.querySelector('#srCombos').textContent = (state.run.combos || 0) + ' combos';
        const zone = document.querySelector('#srResult');
        if (res.result) {
          zone.innerHTML = `<div class="result ${rarityClass(res.result)}"><div class="art">${artHtml(res.result, document.querySelector('base').href)}</div><div class="rn">${esc(res.result.name)}</div><div class="rar-pill ${rarityClass(res.result)}">${esc(res.result.rarity || '')}</div></div>`;
          onExtras?.(res);
          burst?.(res.result.rarity);
        }
        paint();
        if (res.run?.justFinished) {
          cancelAnimationFrame(state.run._raf);
          sfx('fanfare');
          toast(`Finished in ${res.run.combos} combos · ${fmtMs(res.run.ms)}!`);
          setTimeout(() => go('speedrun'), 1800);
        }
        slots[0] = slots[1] = null;
        paintSlots();
      } catch (er) { toast(er.message); slots[1] = null; paintSlots(); }
    }
  };
}

export async function shareDaily(state, toast) {
  const text = (state.daily?.share || 'ComboCraft Daily') + '\n' + (state.referral?.challengeUrl || location.href);
  try {
    if (navigator.share) { await navigator.share({ text, url: state.referral?.challengeUrl || location.href }); toast('Shared!'); return; }
  } catch {}
  try { await navigator.clipboard.writeText(text); toast('Result copied — paste it anywhere!'); }
  catch { prompt('Copy this:', text); }
}

export function dailyWin(state, toast) {
  const d = state.daily;
  if (!d) return;
  const wrap = document.createElement('div');
  wrap.className = 'overlay';
  wrap.innerHTML = `<div class="modal daily-win">
    <button class="x" aria-label="Close">${svg.close}</button>
    <div class="dw-kicker">Daily Challenge cleared</div>
    <h3>${d.target ? `${ico(d.target.emoji)} ${esc(d.target.name)}` : 'Nice!'}</h3>
    <p>${d.combos} combo${d.combos === 1 ? '' : 's'} · 🔥 ${d.streak} day streak</p>
    <pre class="dw-share">${esc(d.share || '')}</pre>
    <div class="cel-actions" style="margin-top:14px">
      <button class="act share" data-a="copy"><span class="bx">${svg.link}</span>Copy result</button>
      <button class="act share" data-a="friend"><span class="bx">${svg.arrowShare}</span>Challenge a friend</button>
    </div>
  </div>`;
  document.body.appendChild(wrap);
  const close = () => wrap.remove();
  wrap.querySelector('.x').onclick = close;
  wrap.addEventListener('click', (e) => {
    if (e.target === wrap) close();
    const a = e.target.closest('[data-a]');
    if (!a) return;
    if (a.dataset.a === 'copy') shareDaily(state, toast);
    if (a.dataset.a === 'friend') copyInvite(state, toast, true);
  });
}

export async function copyInvite(state, toast, challenge = false) {
  const url = challenge ? (state.referral?.challengeUrl || state.referral?.url) : state.referral?.url;
  const text = challenge
    ? `Can you beat me at today's ComboCraft Daily?\n${url}`
    : `Come invent something on ComboCraft:\n${url}`;
  try { await navigator.clipboard.writeText(text); toast(challenge ? 'Challenge link copied!' : 'Invite link copied!'); }
  catch { prompt('Copy this:', text); }
}

function $(s, r = document) { return r.querySelector(s); }
