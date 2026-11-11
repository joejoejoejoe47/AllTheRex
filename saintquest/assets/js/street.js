// People on the walk: 3D villagers (KayKit Adventurers, CC0) standing in the
// walk videos at fixed points of each path. They are drawn in the home 3D
// layer with a simple road perspective (far = small and near the horizon,
// close = saint-sized beside the path), talk with recorded voices when you
// walk up (their mouths move with the sound), and have a case you can fix.
// Rewards are decided by the server (/api/cases/do).
import { createSaint, THREE } from './saint3d.js';
import * as api from './api.js';
import * as audio from './audio.js';
import { LINES } from './lines.js';
import { $, el, toast, sparkle } from './ui.js';
import { state } from './state.js';

// ---------- the people ----------
// at = where they stand on the segment (video seconds), side -1 left / +1 right,
// lat = distance from the saint when you reach them (picture widths).
const NPCS = {
  rosie: { name: 'Rosie', seg: 'main', at: 2.7, side: -1, lat: 0.2, model: 'rogue', tex: 'rita.png', scale: 0.62, idle: 'Idle_B' },
  bram: { name: 'Bram', seg: 'main', at: 7.0, side: 1, lat: 0.15, model: 'barbarian', hide: ['Barbarian_BearHat'], scale: 0.95, idle: 'Idle_A', face: -1 },
  nell: { name: 'Nell', seg: 'main', at: 7.0, side: 1, lat: 0.27, model: 'rogue', tex: 'kateri.png', scale: 0.9, idle: 'Idle_A', face: 1 },
  martha: { name: 'Old Martha', seg: 'main', at: 9.7, side: -1, lat: 0.19, model: 'mage', tex: 'clare.png', hide: ['Mage_Hat'], scale: 0.9, idle: 'Idle_B' },
  greta: { name: 'Greta the baker', seg: 'market', at: 3.4, side: 1, lat: 0.18, model: 'mage', tex: 'patrick.png', scale: 0.95, idle: 'Idle_A' },
  simon: { name: 'Simon', seg: 'market', at: 5.2, side: -1, lat: 0.19, model: 'rogue-hooded', tex: 'benedict.png', hide: ['RogueHooded_Mask'], scale: 0.92, idle: 'Idle_B', slow: 0.5 },
  jakob: { name: 'Jakob', seg: 'market', at: 7.0, side: 1, lat: 0.19, model: 'rogue-hooded', tex: 'francis.png', hide: ['RogueHooded_Mask'], scale: 0.68, idle: 'Use_Item' },
  ezra: { name: 'Ezra', seg: 'market', at: 8.8, side: -1, lat: 0.19, model: 'mage', tex: 'nicholas.png', hide: ['Mage_Hat'], scale: 0.9, idle: 'Idle_B', slow: 0.35 },
  hugo: { name: 'Hugo the gardener', seg: 'park', at: 3.4, side: -1, lat: 0.19, model: 'ranger', tex: 'isidore.png', scale: 0.95, idle: 'Idle_A' },
  tom: { name: 'Tom', seg: 'park', at: 5.8, side: 1, lat: 0.14, model: 'ranger', scale: 0.6, idle: 'Hit_A', face: -1 },
  leo: { name: 'Leo', seg: 'park', at: 5.8, side: 1, lat: 0.25, model: 'knight', scale: 0.6, idle: 'Throw', face: 1 },
  mia: { name: 'Mia', seg: 'park', at: 8.2, side: -1, lat: 0.19, model: 'rogue', tex: 'rita.png', scale: 0.58, idle: 'Idle_B' },
};

// ---------- the cases ----------
export const CASES = [
  { key: 'lost_child', npcs: ['rosie'], title: 'Rosie is lost', intro: [['rosie', 'rosie_ask']], button: '💛 Help Rosie find her mama',
    act: [['saint', 'saint_rosie']], thanks: [['rosie', 'rosie_thanks']], leave: true, doneText: 'Rosie is home with her mama.' },
  { key: 'clue_bell', npcs: ['bram', 'nell'], clue: 1, title: 'Town talk: the silent bell', intro: [['bram', 'bram_a'], ['nell', 'nell_a']], button: '🔍 Ask about the lantern',
    act: [['saint', 'saint_bram']], thanks: [['bram', 'bram_b']], doneText: '“Ask Greta the baker in the market!”' },
  { key: 'widow', npcs: ['martha'], title: 'Old Martha is lonely', intro: [['martha', 'martha_ask']], button: '🪑 Sit and listen to Martha',
    act: [['saint', 'saint_martha']], thanks: [['martha', 'martha_thanks']], doneText: '“Bless you, child!”' },
  { key: 'clue_dove', npcs: ['greta'], clue: 2, title: 'Town talk: the singing dove', intro: [['greta', 'greta_a']], button: '🔍 Ask about the dove',
    act: [['saint', 'saint_greta']], thanks: [['greta', 'greta_b']], doneText: '“Ask old Hugo in the park!”' },
  { key: 'beggar', npcs: ['simon'], title: 'Simon is hungry', intro: [['simon', 'simon_ask']], button: '💛 Give 50 virtue points to the poor', cost: 50,
    act: [['saint', 'saint_simon']], thanks: [['simon', 'simon_thanks']], doneText: '“The Lord sees what you have done!”' },
  { key: 'thief', npcs: ['jakob'], title: 'Jakob wants to steal', intro: [['jakob', 'jakob_ask']], button: '🍎 Tell Jakob the truth',
    act: [['saint', 'saint_jakob']], thanks: [['jakob', 'jakob_thanks']], doneText: 'Jakob put the apple back.' },
  { key: 'sick_man', npcs: ['ezra'], title: 'Ezra is sick', intro: [['ezra', 'ezra_ask']], button: '🙏 Pray for Ezra',
    act: [['saint', 'saint_ezra']], thanks: [['ezra', 'ezra_thanks']], healed: true, doneText: 'Ezra is dancing!' },
  { key: 'clue_lantern', npcs: ['hugo'], clue: 3, title: 'Town talk: the lantern', intro: [['hugo', 'hugo_a']], button: '🔍 Ask about the lantern',
    act: [['saint', 'saint_hugo']], thanks: [['hugo', 'hugo_b']], doneText: '“Go inside the cathedral!”' },
  { key: 'kids_fight', npcs: ['tom', 'leo'], title: 'Two kids are fighting', intro: [['tom', 'tom_fight'], ['leo', 'leo_fight']], button: '✋ Step in: “Stop!”', fight: true,
    act: [['saint', 'saint_stop']], thanks: [['tom', 'tom_right'], ['leo', 'leo_share']], bye: [['tom', 'tom_thanks'], ['leo', 'leo_thanks']], leave: true, doneText: 'Tom and Leo are friends again.' },
  { key: 'lonely_kid', npcs: ['mia'], title: 'Mia has no one to play with', intro: [['mia', 'mia_ask']], button: '🤝 Invite Mia to play',
    act: [['saint', 'saint_mia']], thanks: [['mia', 'mia_thanks']], doneText: 'Mia is playing tag!' },
];
const caseOf = {};
for (const c of CASES) for (const n of c.npcs) caseOf[n] = c;

const HORIZON = 0.47; // picture-height fraction where far things meet
const depth = (d) => 1 + d * 0.25 + d * d * 0.05; // how people shrink with distance
const SEE = 7.5; // they appear this far ahead (video seconds)
const NEAR_IN = 1.35, NEAR_OUT = -0.3;

let G = null; // { scene, W, H, PPU, HERO_X, FEET_Y, toScreen }
const live = {}; // id -> { def, saint, pivot, shadow, mouth, x, gone, walkAway, anim }
let pos = { seg: 'main', t: 0, sx: 0.484, visible: true };
let near = null; // active case
let token = 0, busy = false, talker = null, saintMouth = null;
const hooks = { freeze: () => {}, cheer: () => {}, slideTo: () => {} };
const doneLocal = new Set(); // cases finished this visit (until /api/me refreshes)

export function setHooks(h) { Object.assign(hooks, h); }

let shadowTex = null;
function blob() {
  if (!shadowTex) {
    const g = document.createElement('canvas');
    g.width = g.height = 64;
    const x = g.getContext('2d');
    const grad = x.createRadialGradient(32, 32, 2, 32, 32, 31);
    grad.addColorStop(0, 'rgba(40,30,10,0.5)');
    grad.addColorStop(1, 'rgba(40,30,10,0)');
    x.fillStyle = grad;
    x.fillRect(0, 0, 64, 64);
    shadowTex = new THREE.CanvasTexture(g);
  }
  const m = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: shadowTex, transparent: true, depthWrite: false }));
  m.renderOrder = -1;
  return m;
}

/** A small mouth on the face that opens with the voice level. */
export function attachMouth(object) {
  object.updateMatrixWorld(true);
  let head = null;
  object.traverse((o) => { if (!head && o.isBone && o.name === 'head') head = o; });
  if (!head) return null;
  const box = new THREE.Box3().setFromObject(object);
  const hp = head.getWorldPosition(new THREE.Vector3());
  const y = hp.y + (box.max.y - hp.y) * 0.19;
  let z = box.max.z * 0.85;
  try {
    const rc = new THREE.Raycaster(new THREE.Vector3(0, y, 20), new THREE.Vector3(0, 0, -1));
    const hits = rc.intersectObject(object, true).filter((h) => h.object.visible && h.object.isMesh);
    if (hits.length) z = hits[0].point.z + 0.015;
  } catch { /* keep the estimate */ }
  const mouth = new THREE.Mesh(new THREE.CircleGeometry(1, 24), new THREE.MeshBasicMaterial({ color: 0x4a1414 }));
  const ws = head.getWorldScale(new THREE.Vector3());
  const local = head.worldToLocal(new THREE.Vector3(0, y, z));
  mouth.position.copy(local);
  const hq = head.getWorldQuaternion(new THREE.Quaternion()).invert();
  mouth.quaternion.copy(hq);
  mouth.userData.base = { x: 0.1 / ws.x, y: 0.085 / ws.y, z: 1 / (ws.z || 1) };
  mouth.scale.set(mouth.userData.base.x, mouth.userData.base.y * 0.12, mouth.userData.base.z);
  head.add(mouth);
  return mouth;
}

function setMouth(mouth, open) {
  if (!mouth) return;
  const b = mouth.userData.base;
  mouth.scale.set(b.x * (0.85 + open * 0.25), b.y * (0.12 + open * 1.1), b.z);
}

export function init(g) { G = g; }
export function setSaintMouth(m) { saintMouth = m; }

function play(n, name, opts = {}) {
  if (!n.saint || n.anim === name) return;
  n.anim = name;
  n.saint.play(name, opts);
}

async function spawn(id) {
  const d = NPCS[id];
  const n = { id, def: d, saint: null, pivot: new THREE.Group(), shadow: blob(), mouth: null, extra: 0, gone: false, anim: null, loading: true };
  live[id] = n;
  n.pivot.visible = false;
  n.shadow.visible = false;
  G.scene.add(n.pivot);
  G.scene.add(n.shadow);
  try {
    n.saint = await createSaint({ model: d.model, tex: d.tex, hide: d.hide }, { halo: false });
    n.mouth = attachMouth(n.saint.object);
    n.pivot.add(n.saint.object);
    n.anim = null;
    play(n, d.idle, { speed: d.slow || 1 });
  } catch (e) { console.warn('npc', id, e); }
  n.loading = false;
}

function isDone(c) {
  if (doneLocal.has(c.key)) return true;
  const cs = state.me && state.me.cases;
  if (!cs) return false;
  return c.clue ? cs.clues.includes(c.key) : cs.today.includes(c.key);
}

/** Called every frame by the home scene. */
export function update(dt, p) {
  if (!G) return;
  pos = p;
  const level = audio.level();
  let best = null, bestD = 1e9;
  for (const id in NPCS) {
    const d = NPCS[id];
    const c = caseOf[id];
    const dist = d.at - p.t;
    const here = p.visible && state.me && d.seg === p.seg && dist < SEE && dist > -0.9 && !(c.leave && isDone(c) && !live[id]?.walkAway);
    let n = live[id];
    if (here && !n) { spawn(id); n = live[id]; }
    if (!n) continue;
    if (!here || n.gone || !n.saint) { n.pivot.visible = n.shadow.visible = false; continue; }
    if (c.leave && isDone(c) && !n.walkAway) { n.pivot.visible = n.shadow.visible = false; continue; }
    n.saint.update(dt);
    const z = depth(Math.max(0, dist));
    let lat = d.lat + n.extra;
    if (dist < 0) lat += -dist * 0.5;
    const fx = G.HERO_X + d.side * lat / z;
    const fy = HORIZON + (G.FEET_Y - HORIZON) / z + (dist < 0 ? -dist * 0.05 : 0);
    const s = G.PPU * d.scale / z;
    n.pivot.visible = n.shadow.visible = true;
    n.pivot.position.set(fx * G.W, -fy * G.H, -dist * 220);
    n.pivot.scale.setScalar(s);
    n.pivot.rotation.x = 0.07;
    let face = d.face !== undefined && c.fight && !n.stopped ? d.face * Math.PI / 2 : -d.side * 0.55;
    if (n.walkAway) face = d.side * Math.PI / 2;
    n.pivot.rotation.y += (face - n.pivot.rotation.y) * Math.min(1, dt * 6);
    n.shadow.position.set(fx * G.W, -fy * G.H + 2, -1500);
    n.shadow.scale.set(150 * d.scale / z, 40 * d.scale / z, 1);
    if (c.fight && !n.stopped && !isDone(c)) {
      n.fightT = (n.fightT || 0) + dt;
      n.pivot.position.x += Math.sin(n.fightT * 9 + (d.face > 0 ? 0 : 2)) * 4 / z;
    }
    if (n.walkAway) {
      n.extra += dt * 0.12;
      if (n.extra > 0.6) { n.gone = true; n.walkAway = false; }
    }
    setMouth(n.mouth, talker === id ? Math.max(level, audio.isSpeaking() ? 0 : fakeLevel()) : 0);
    // nearest case on this path
    if (dist < NEAR_IN && dist > NEAR_OUT && Math.abs(dist - 0.5) < bestD && !n.walkAway && !n.gone) { bestD = Math.abs(dist - 0.5); best = c; }
  }
  setMouth(saintMouth, talker === 'saint' ? Math.max(level, audio.isSpeaking() ? 0 : fakeLevel()) : 0);
  if (busy) { placeUi(); return; }
  if (best !== near) setNear(best);
  placeUi();
}

function fakeLevel() { return talkingCaption ? 0.35 + 0.35 * Math.sin(performance.now() / 70) : 0; }

// ---------- prompt, captions and the case flow ----------
let promptEl = null, capEl = null, talkingCaption = false;
function ensureUi() {
  if (promptEl) return;
  promptEl = el('button#case-btn.case-btn', { type: 'button', hidden: true, on: { click: () => act() } });
  capEl = el('div#say-bubble.say-bubble', { hidden: true, role: 'status', 'aria-live': 'polite' });
  document.body.append(promptEl, capEl);
}

function screenOf(who) {
  if (who === 'saint') { const p = G.toScreen(pos.sx, G.FEET_Y); return { x: p.x, y: p.y - 215 * scaleNow() }; }
  const n = live[who];
  if (!n || !n.pivot.visible) return null;
  const sc = n.pivot.scale.x / G.PPU;
  const p = G.toScreen(n.pivot.position.x / G.W, -n.pivot.position.y / G.H);
  return { x: p.x, y: p.y - 240 * sc * scaleNow() };
}
const scaleNow = () => (G.stageScale ? G.stageScale() : 1);

function placeUi() {
  if (!promptEl) return;
  if (!capEl.hidden && capEl.dataset.who) {
    const p = screenOf(capEl.dataset.who);
    if (p) { capEl.style.left = p.x + 'px'; capEl.style.top = p.y + 'px'; }
  }
  if (!promptEl.hidden && near) {
    const p = screenOf(near.npcs[0]);
    if (p) { promptEl.style.left = Math.max(120, Math.min(innerWidth - 120, p.x)) + 'px'; promptEl.style.top = Math.max(70, p.y - 60) + 'px'; }
  }
}

function caption(who, text) {
  ensureUi();
  if (!who) { capEl.hidden = true; capEl.dataset.who = ''; talkingCaption = false; return; }
  const name = who === 'saint' ? 'You' : NPCS[who].name;
  capEl.replaceChildren(el('b', { text: name }), el('span', { text: text }));
  capEl.dataset.who = who;
  capEl.classList.toggle('mine', who === 'saint');
  capEl.hidden = false;
  talkingCaption = true;
}

async function speak(who, id, my) {
  if (my !== token) return false;
  const line = LINES[id];
  talker = who;
  caption(who, line.text);
  const n = live[who];
  if (n && n.saint && !NPCS[who].fight && caseOf[who] && !caseOf[who].fight) play(n, 'Interact', { speed: 0.6 });
  await audio.say(id, Math.max(1400, line.dur * 1000));
  if (my !== token) return false;
  talker = null;
  caption(null);
  if (n && n.saint && n.anim === 'Interact') play(n, NPCS[who].idle, { speed: NPCS[who].slow || 1 });
  await new Promise((r) => setTimeout(r, 220));
  return my === token;
}

async function runLines(lines, my) {
  for (const [who, id] of lines || []) if (!(await speak(who, id, my))) return false;
  return true;
}

function setNear(c) {
  ensureUi();
  near = c;
  const my = ++token;
  audio.stopVoice();
  talker = null;
  caption(null);
  promptEl.hidden = true;
  window.__sqStreet.near = c ? c.key : null;
  if (!c) return;
  if (isDone(c)) {
    caption(c.npcs[0], c.doneText);
    talkingCaption = false;
    return;
  }
  (async () => {
    showPrompt(c);
    await runLines(c.intro, my);
  })();
}

function showPrompt(c) {
  promptEl.replaceChildren(el('kbd', { text: matchMedia('(pointer: coarse)').matches ? 'Tap' : 'E' }), document.createTextNode(c.button));
  promptEl.hidden = false;
  promptEl.disabled = false;
}

export const prompting = () => !!(promptEl && !promptEl.hidden && !promptEl.disabled);
export function pressPrompt() { if (!prompting()) return false; act(); return true; }

async function act() {
  const c = near;
  if (!c || busy || !state.me) return;
  if (c.cost && state.me.virtue < c.cost) { toast(`You need ${c.cost} virtue points to give. Help someone or win a Holy Battle first!`, true); return; }
  busy = true;
  promptEl.disabled = true;
  promptEl.hidden = true;
  const my = ++token;
  audio.stopVoice();
  caption(null);
  hooks.freeze(true);
  const n0 = live[c.npcs[0]];
  if (c.fight && n0) hooks.slideTo(G.HERO_X + NPCS[c.npcs[0]].side * 0.09); // step in front of the fight
  let result = null, err = null;
  const server = api.post('cases/do', { case: c.key }).then((r) => { result = r; }).catch((e) => { err = e; });
  if (c.cost) await server; // don't promise a gift the server refused
  if (!err) await runLines(c.act, my);
  await server;
  try {
    if (err) {
      toast(err.message, true);
      if (err.status === 409) doneLocal.add(c.key);
      return;
    }
    doneLocal.add(c.key);
    for (const id of c.npcs) {
      const n = live[id];
      if (!n || !n.saint) continue;
      n.stopped = true;
      play(n, c.healed ? 'Jump_Full_Short' : 'Idle_A', { once: !!c.healed });
    }
    await runLines(c.thanks, my);
    if (c.healed) for (const id of c.npcs) if (live[id]) { live[id].anim = null; play(live[id], 'Interact'); }
    if (c.leave) for (const id of c.npcs) { const n = live[id]; if (n) { n.walkAway = true; n.anim = null; play(n, c.fight ? 'Walking_A' : 'Running_A'); } }
    if (c.bye) await runLines(c.bye, my);
    hooks.cheer();
    const p = G.toScreen(pos.sx, G.FEET_Y - 0.2);
    sparkle(p.x, p.y);
    if (c.cost) toast(`💛 You gave ${c.cost} virtue points to the poor. Visit the Store later… something good is waiting!`, false, 6000);
    else if (c.clue) toast(`🔍 Clue found! ${c.doneText}  +${result.reward} virtue`, false, 6000);
    else toast(`💛 ${c.doneText} +${result.reward} virtue and +1 Good Deed`, false, 5000);
    if (state.refresh) await state.refresh(true);
  } finally {
    busy = false;
    hooks.freeze(false);
    if (my === token) { near = null; setNear(null); }
  }
}


/** Leaving home (church, battle…): stop talking. */
export function hush() { token++; audio.stopVoice(); caption(null); if (promptEl) promptEl.hidden = true; talker = null; near = null; }

export function preload() {
  audio.preloadVoices(['saint_stop', 'rosie_ask', 'simon_ask', 'tom_fight', 'leo_fight']);
}

window.__sqStreet = {
  near: null,
  get busy() { return busy; },
  get visible() { return Object.keys(live).filter((k) => live[k].pivot.visible); },
  get talker() { return talker; },
  npcScreen: (id) => screenOf(id),
  cases: CASES.map((c) => c.key),
};
