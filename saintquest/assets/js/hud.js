// Game HUD: the quest tracker (The Silent Bell, step by step), a minimap of
// the paths with you, the people you can help and the next objective, and
// big "Clue found" / "Objective" banners.
import { el } from './ui.js';
import { state } from './state.js';
import * as audio from './audio.js';

let map = null, mctx = null, tracker = null, list = null, pos = { seg: 'main', t: 0, vel: 0 };
let geo = null; // { junction, dur: { main, market, park } }
let markerFn = () => [];
let lastObjective = null;
const DPR = Math.min(2, window.devicePixelRatio || 1);

export function setMarkers(fn) { markerFn = fn; }
export function setGeometry(g) { geo = g; }

/** The steps of the Holy Mystery and which one is next. */
export function questSteps() {
  const cs = (state.me && state.me.cases) || { clues: [], kindDone: 0, mysterySolved: false, mysteryReady: false };
  const kind = Math.min(3, cs.kindDone || 0);
  const steps = [
    { key: 'clue_bell', text: 'Hear the town talk on the Saints’ Path', where: { seg: 'main', at: 8.0 }, done: cs.clues.includes('clue_bell') },
    { key: 'clue_dove', text: 'Ask Greta the baker in Market Street', where: { seg: 'market', at: 3.4 }, done: cs.clues.includes('clue_dove') },
    { key: 'clue_lantern', text: 'Follow the dove: find Hugo in Park Square', where: { seg: 'park', at: 3.4 }, done: cs.clues.includes('clue_lantern') },
    { key: 'kind', text: `Help 3 people in need (${kind}/3)`, where: null, done: kind >= 3 },
    { key: 'lantern', text: 'Light Saint Clare’s lantern in the cathedral', where: { seg: 'main', at: 'end' }, done: !!cs.mysterySolved },
  ];
  const next = cs.mysterySolved ? null : steps.find((s) => !s.done);
  return { steps, next, solved: !!cs.mysterySolved };
}

function ensure() {
  if (map) return;
  map = el('canvas#minimap.minimap', { width: 150 * DPR, height: 150 * DPR, 'aria-label': 'Map of the paths', role: 'img' });
  mctx = map.getContext('2d');
  list = el('ol.qt-list');
  tracker = el('section#quest-tracker.quest-tracker', { 'aria-label': 'Quest' }, el('header', {}, el('span', { text: '📜' }), el('b#qt-title', { text: 'The Silent Bell' }), el('small', { text: 'Mystery' })), list);
  document.body.append(map, tracker);
}

/** Re-render the tracker (after /api/me changes). */
export function render() {
  ensure();
  const q = questSteps();
  const cs = state.me && state.me.cases;
  if (!cs) { tracker.hidden = true; return; }
  tracker.hidden = false;
  tracker.classList.toggle('solved', q.solved);
  if (q.solved) {
    const left = 3 - ((state.me.deedsToday || []).length);
    list.replaceChildren(
      el('li.done', { text: 'Saint Clare’s lantern shines — the bell rings again!' }),
      el('li', { class: left > 0 ? 'next' : 'done', text: left > 0 ? `Today: help the villagers (${3 - left}/3)` : 'Today’s good deeds are done' }),
      el('li', { text: `Helped on the paths today: ${(cs.today || []).length}` }));
  } else {
    list.replaceChildren(...q.steps.map((s) => el('li', { class: s.done ? 'done' : s === q.next ? 'next' : '', text: s.text })));
  }
  const key = q.next ? q.next.key : 'solved';
  if (lastObjective !== null && key !== lastObjective && q.next) {
    flash('New objective', q.next.text, '🎯');
    audio.effect('sfx/objective.mp3');
  }
  lastObjective = key;
}

const flashQ = [];
let flashing = false;
/** A big centred game banner (Clue found!, New objective…); queued so none hides another. */
export function flash(kicker, title, icon = '✨', sub = '') {
  flashQ.push([kicker, title, icon, sub]);
  if (!flashing) nextFlash();
}
function nextFlash() {
  const item = flashQ.shift();
  if (!item) { flashing = false; return; }
  flashing = true;
  const [kicker, title, icon, sub] = item;
  let b = document.getElementById('game-flash');
  if (!b) { b = el('div#game-flash.game-flash', { role: 'status' }); document.body.append(b); }
  b.replaceChildren(el('span.gf-icon', { text: icon }), el('small', { text: kicker }), el('b', { text: title }), sub ? el('span.gf-sub', { text: sub }) : '');
  b.hidden = false;
  b.classList.remove('out', 'in');
  void b.offsetWidth;
  b.classList.add('in');
  setTimeout(() => { b.classList.add('out'); setTimeout(() => { b.hidden = true; nextFlash(); }, 480); }, 2600);
}

// ---------- minimap ----------
// Main path runs bottom (home) to top (cathedral); Market Street branches
// left at the crossroads and Park Square right.
const C = 75, BOT = 136, TOP = 26;
function mapXY(seg, t) {
  const J = geo ? geo.junction : 5;
  const D = geo ? geo.dur : { main: 13, market: 10, park: 10 };
  const jy = BOT - (J / D.main) * (BOT - TOP);
  if (seg === 'main') { const tt = t === 'end' ? D.main : t; return [C, BOT - (tt / D.main) * (BOT - TOP)]; }
  const k = Math.min(1, (t === 'end' ? D[seg] : t) / D[seg]);
  const dir = seg === 'market' ? -1 : 1;
  return [C + dir * k * 62, jy - k * 22];
}

export function update(p) {
  pos = p;
}

function draw() {
  requestAnimationFrame(draw);
  if (!map || !geo || !state.me) return;
  const hidden = document.body.classList.contains('in-church') || document.body.classList.contains('cutscene');
  map.hidden = tracker.hidden = hidden || !state.me.cases;
  if (map.hidden) return;
  const x = mctx;
  x.setTransform(DPR, 0, 0, DPR, 0, 0);
  x.clearRect(0, 0, 150, 150);
  // parchment disc
  x.save();
  x.beginPath(); x.arc(75, 75, 72, 0, Math.PI * 2); x.closePath();
  const g = x.createRadialGradient(75, 70, 10, 75, 75, 74);
  g.addColorStop(0, '#fff7dc'); g.addColorStop(1, '#efd9a0');
  x.fillStyle = g; x.fill();
  x.clip();
  // grass patches
  x.fillStyle = 'rgba(120,170,80,.25)';
  for (const [a, b, r] of [[30, 40, 26], [120, 45, 24], [35, 120, 28], [118, 118, 26]]) { x.beginPath(); x.arc(a, b, r, 0, 7); x.fill(); }
  // paths
  x.lineCap = 'round'; x.lineJoin = 'round';
  const path = (seg) => { x.beginPath(); for (let i = 0; i <= 20; i++) { const [px, py] = mapXY(seg, (i / 20) * (seg === 'main' ? geo.dur.main : geo.dur[seg])); if (i) x.lineTo(px, py); else x.moveTo(px, py); } };
  for (const [w, c] of [[9, '#b08850'], [5, '#f1d9a3']]) { x.lineWidth = w; x.strokeStyle = c; for (const s of ['main', 'market', 'park']) { path(s); x.stroke(); } }
  // places
  x.font = '15px serif'; x.textAlign = 'center'; x.textBaseline = 'middle';
  x.fillText('⛪', C, TOP - 9 + 6);
  const [mx, my] = mapXY('market', 'end'); x.fillText('🍎', mx - 4, my - 9);
  const [px2, py2] = mapXY('park', 'end'); x.fillText('⛲', px2 + 4, py2 - 9);
  x.fillText('🏠', C, BOT + 5);
  // people
  const q = questSteps();
  for (const m of markerFn()) {
    const [a, b] = mapXY(m.seg, m.at);
    const ox = m.seg === 'main' ? m.side * 7 : 0, oy = m.seg === 'main' ? 0 : m.side * -6;
    x.beginPath(); x.arc(a + ox, b + oy, m.done ? 3 : 5, 0, 7);
    x.fillStyle = m.done ? 'rgba(90,80,60,.45)' : m.kind === 'clue' ? '#3f7fd6' : m.kind === 'chat' ? '#9a8a6a' : '#f2b01e';
    x.fill();
    if (!m.done && m.kind !== 'chat') {
      x.fillStyle = '#fff'; x.font = 'bold 8px sans-serif';
      x.fillText(m.kind === 'clue' ? '?' : '!', a + ox, b + oy + 0.5);
    }
  }
  // objective ring
  if (q.next && q.next.where) {
    const [a, b] = mapXY(q.next.where.seg, q.next.where.at);
    const r = 8 + 3 * Math.sin(performance.now() / 220);
    x.lineWidth = 2.5; x.strokeStyle = '#e0a400'; x.beginPath(); x.arc(a, b, r, 0, 7); x.stroke();
  }
  // you
  const [ux, uy] = mapXY(pos.seg, Math.max(0, pos.t));
  let ang = pos.seg === 'market' ? -Math.PI / 2 - 0.35 : pos.seg === 'park' ? Math.PI / 2 + 0.35 : 0;
  if (pos.vel < -0.02) ang += Math.PI;
  x.save(); x.translate(ux, uy); x.rotate(ang);
  x.beginPath(); x.moveTo(0, -8); x.lineTo(6, 6); x.lineTo(0, 3); x.lineTo(-6, 6); x.closePath();
  x.fillStyle = '#d23b2a'; x.strokeStyle = '#fff'; x.lineWidth = 2; x.stroke(); x.fill();
  x.restore();
  x.restore();
  // rim + compass N
  x.lineWidth = 4; x.strokeStyle = '#a8741a'; x.beginPath(); x.arc(75, 75, 71, 0, 7); x.stroke();
  x.fillStyle = '#a8741a'; x.beginPath(); x.arc(75, 6, 8, 0, 7); x.fill();
  x.fillStyle = '#fff'; x.font = 'bold 10px sans-serif'; x.fillText('N', 75, 6.5);
}

export function start() { ensure(); render(); requestAnimationFrame(draw); }

window.__sqHud = { get next() { const q = questSteps(); return q.next ? q.next.key : null; }, get steps() { return questSteps().steps.map((s) => s.key + (s.done ? ':done' : '')); } };
