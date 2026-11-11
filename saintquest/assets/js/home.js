// Home scene: the village picture as a "cover" background, hotspots that stay
// glued to the picture at any screen size, and the player's best saint as a
// real 3D model at a constant size in the lower middle. Holding W plays the
// walk up the path (a frame sequence) forward, S plays it backward, A/D step
// the saint left/right; nothing moves when no key is held. Reaching the end
// of the path (the cathedral doors) opens the church.
import { createSaint, THREE } from './saint3d.js';
import { $ } from './ui.js';
import { direction, isTouch, makeJoystick, clearKeys } from './walkinput.js';
import * as frames from './walkframes.js';

const W = 1280, H = 720;
const HERO_X = 0.484; // where the saint stands, as a fraction of the picture width
const FEET_Y = 0.72; // feet line, fraction of the picture height
const PPU = 92; // picture pixels per model unit (constant: the saint never changes size)
const SIDE_SPEED = 0.11; // picture widths per second for A/D
const ACCEL = 9; // ease in/out of the walk (1/s)
const PLAY_SPEED = 0.5; // the walk video plays at half speed (the whole path takes ~24 s)
export const VILLAGERS = [
  { deed: 'apples', name: 'Grandma Agnes', x: 0.215, y: 0.70 },
  { deed: 'lamb', name: 'Little Tobias', x: 0.275, y: 0.68 },
  { deed: 'chapel', name: 'Brother Peter', x: 0.655, y: 0.685 },
];

let stageRect = { left: 0, top: 0, scale: 1 };
let renderer = null, scene = null, camera = null, current = null, currentKey = null, clock = null, running = true, shadow = null, pivot = null;
let loadToken = 0;
let t = 0, vel = 0, sx = HERO_X, sideVel = 0;
let heading = 0, wantHeading = 0, idleFor = 0, walking = false, anim = 'Idle_A', doorFired = false, frozen = false;
let walkCtx = null, drawnT = -1, drawnN = -1;
const listeners = { layout: [], door: [], near: [] };
let nearVillager = null;

// Read-only view for the browser test.
window.__sqHome = {
  get key() { return currentKey; }, get loaded() { return !!current; },
  get pos() { return { x: sx, y: FEET_Y }; }, get t() { return t; }, get duration() { return frames.duration(); },
  get frame() { return Math.round(t * frames.fps()); }, get walking() { return walking; }, get anim() { return anim; },
  get scale() { return pivot ? pivot.scale.x / PPU : null; }, get heading() { return heading; },
  get near() { return nearVillager && nearVillager.deed; }, get framesLoaded() { return frames.progress(); }, get frameSet() { return frames.setName(); },
};

export function onLayout(fn) { listeners.layout.push(fn); }
export function onDoor(fn) { listeners.door.push(fn); }
export function onNear(fn) { listeners.near.push(fn); }

/** Sideways room: wide on the painted home picture (to reach the villagers), the path's width once walking. */
function sideRange() {
  const k = Math.min(1, t / 0.8);
  return [0.20 + (HERO_X - 0.11 - 0.20) * k, 0.72 + (HERO_X + 0.11 - 0.72) * k];
}

export function layout() {
  const vw = window.innerWidth, vh = window.innerHeight;
  const scale = Math.max(vw / W, vh / H);
  let left = vw / 2 - HERO_X * W * scale;
  if (vw / vh > 1.2) left = (vw - W * scale) / 2;
  left = Math.min(0, Math.max(vw - W * scale, left));
  const top = Math.min(0, Math.max(vh - H * scale, (vh - H * scale) * 0.55));
  stageRect = { left, top, scale };
  $('#stage').style.transform = `translate(${left}px, ${top}px) scale(${scale})`;
  resizeRenderer();
  const plate = $('#saint-plate');
  plate.style.left = (left + HERO_X * W * scale) + 'px';
  plate.style.top = Math.min(vh - 150, top + 0.745 * H * scale) + 'px';
  const sp = $('#signpost').getBoundingClientRect();
  $('#edge-next').hidden = !(sp.right > vw - 10 || sp.left < 0) || !current || t > 0.05;
  for (const fn of listeners.layout) fn(stageRect);
}

export function toScreen(fx, fy) {
  return { x: stageRect.left + fx * W * stageRect.scale, y: stageRect.top + fy * H * stageRect.scale };
}

function resizeRenderer() {
  if (!renderer) return;
  const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
  renderer.setPixelRatio(dpr);
  renderer.setSize(Math.max(1, Math.round(W * stageRect.scale)), Math.max(1, Math.round(H * stageRect.scale)), false);
}

export function initHome() {
  const canvas = $('#saint-canvas');
  walkCtx = $('#walk-canvas').getContext('2d');
  try {
    renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'low-power' });
  } catch (e) {
    console.warn('WebGL unavailable', e);
    renderer = null;
  }
  if (renderer) {
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    scene = new THREE.Scene();
    // Fixed camera in picture pixels: x right, y up (= -picture y).
    camera = new THREE.OrthographicCamera(0, W, 0, -H, -2000, 2000);
    camera.position.set(0, 0, 1000);
    scene.add(new THREE.HemisphereLight(0xfff3d6, 0x7c9a52, 1.5));
    const sun = new THREE.DirectionalLight(0xffe2a8, 2.4);
    sun.position.set(4, 5, 3);
    scene.add(sun);
    const rim = new THREE.DirectionalLight(0xfff0c0, 1.2);
    rim.position.set(-3, 3, -4);
    scene.add(rim);
    const g = document.createElement('canvas');
    g.width = g.height = 128;
    const x = g.getContext('2d');
    const grad = x.createRadialGradient(64, 64, 4, 64, 64, 62);
    grad.addColorStop(0, 'rgba(60,40,10,0.5)');
    grad.addColorStop(1, 'rgba(60,40,10,0)');
    x.fillStyle = grad;
    x.fillRect(0, 0, 128, 128);
    shadow = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(g), transparent: true, depthWrite: false, depthTest: false }));
    shadow.renderOrder = -1;
    scene.add(shadow);
    pivot = new THREE.Group();
    pivot.rotation.x = 0.07;
    scene.add(pivot);
    clock = new THREE.Clock();
    renderer.setAnimationLoop(tick);
  } else {
    clock = { getDelta: (() => { let l = performance.now(); return () => { const n = performance.now(); const d = (n - l) / 1000; l = n; return d; }; })() };
    const loop = () => { tick(); requestAnimationFrame(loop); };
    requestAnimationFrame(loop);
  }
  $('#saint-hit').addEventListener('click', () => $('#saint-canvas').dispatchEvent(new Event('click')));
  if (isTouch()) document.body.append(makeJoystick('home-stick'));
  frames.onProgress((p) => {
    const pill = $('#walk-load');
    if (p >= 1) { pill.hidden = true; drawnN = -1; return; }
    pill.hidden = false;
    pill.textContent = `Loading the path… ${Math.round(p * 100)}%`;
    drawnN = -1;
  });
  window.addEventListener('resize', layout);
  document.addEventListener('visibilitychange', () => { running = !document.hidden; });
  layout();
}

/** Start downloading the walk frames (after login). */
export function preloadWalk() { frames.startLoading().catch((e) => console.warn('walk frames', e)); }

function setAnim(name, speed = 1) {
  if (!current) return;
  if (anim !== name) { current.play(name, { speed }); anim = name; }
}

function step(dt) {
  const d = frozen ? { x: 0, y: 0 } : direction();
  const end = frames.duration();
  // Forward/back through the walk video, with a short ease in/out.
  const want = -d.y * PLAY_SPEED; // W forward, S back
  vel += (want - vel) * Math.min(1, dt * ACCEL);
  if (!want && Math.abs(vel) < 0.015) vel = 0;
  t = Math.min(end, Math.max(0, t + vel * dt));
  if ((t === 0 && vel < 0) || (t === end && vel > 0)) vel = 0;
  // Sideways steps.
  sideVel += (d.x - sideVel) * Math.min(1, dt * ACCEL);
  if (!d.x && Math.abs(sideVel) < 0.03) sideVel = 0;
  const [a, b] = sideRange();
  sx = Math.min(b, Math.max(a, sx + sideVel * SIDE_SPEED * dt));
  const moving = !!(d.x || d.y) || vel !== 0 || sideVel !== 0;
  if (d.x || d.y) { wantHeading = Math.atan2(d.x, d.y); idleFor = 0; }
  else if (!moving) { idleFor += dt; if (idleFor > 2.5) wantHeading = 0; }
  if (moving !== walking) {
    walking = moving;
    setAnim(moving ? 'Walking_A' : 'Idle_A', moving ? 0.8 : 1); // stride paced to the half-speed video
  }
  let diff = wantHeading - heading;
  diff = Math.atan2(Math.sin(diff), Math.cos(diff));
  heading += diff * Math.min(1, dt * 10);
  if (t < end - 0.25) doorFired = false;
  if (!doorFired && t >= end - 0.001 && d.y < 0) {
    doorFired = true;
    vel = 0;
    for (const fn of listeners.door) fn();
  }
  let near = null;
  if (t < 0.05) for (const v of VILLAGERS) if (Math.hypot((v.x - sx) * (W / H), v.y - FEET_Y) < 0.11) { near = v; break; }
  if (near !== nearVillager) { nearVillager = near; for (const fn of listeners.near) fn(near); }
}

function drawScene() {
  const n = Math.round(frames.progress() * 1000);
  if (t === drawnT && n === drawnN) return;
  const fade = Math.min(1, (t * frames.fps()) / 6); // cross-fade from the painted picture
  const vc = $('#walk-canvas');
  if (t <= 0) { vc.style.opacity = '0'; } else if (frames.draw(walkCtx, t)) { vc.style.opacity = String(fade); }
  const away = t > 0.02;
  document.body.classList.toggle('walked', away);
  if (away !== (drawnT > 0.02)) layout();
  drawnT = t;
  drawnN = n;
}

function tick() {
  if (!running) return;
  const dt = Math.min(0.1, clock.getDelta());
  step(dt);
  drawScene();
  if (!renderer) return;
  if (current) {
    current.update(dt);
    let pop = 1;
    if (current.object.userData.pop !== undefined) {
      current.object.userData.pop = Math.min(1, current.object.userData.pop + dt * 3);
      const p = current.object.userData.pop;
      pop = p < 1 ? 0.6 + 0.4 * (1 - Math.pow(1 - p, 3)) + Math.sin(p * Math.PI) * 0.12 : 1;
    }
    pivot.position.set(sx * W, -FEET_Y * H, 0);
    pivot.scale.setScalar(PPU * pop);
    const sway = !walking && Math.abs(heading) < 0.05 ? Math.sin(performance.now() / 2000) * 0.12 : 0;
    current.object.rotation.y = heading + sway;
    shadow.position.set(sx * W, -FEET_Y * H + 2, -500);
    shadow.scale.set(150, 42, 1);
    const hit = $('#saint-hit');
    hit.style.left = (sx * W - 55) + 'px';
    hit.style.top = (FEET_Y * H - 230) + 'px';
    hit.style.width = '110px';
    hit.style.height = '230px';
    $('#saint-plate').classList.toggle('dim', Math.abs(sx - HERO_X) > 0.02);
  }
  renderer.render(scene, camera);
}

export function pause(p) { running = !p; if (!p && clock) clock.getDelta(); }

/** Stop walking input (e.g. during the church transition). */
export function freeze(f) { frozen = f; if (f) { clearKeys(); vel = 0; sideVel = 0; } }

/** Back outside the cathedral doors (end of the path), facing the viewer. */
export function placeAtDoor() {
  t = Math.max(0, frames.duration() - 0.4);
  vel = sideVel = 0;
  sx = HERO_X;
  heading = wantHeading = 0;
  idleFor = 0;
  doorFired = false;
  drawnT = -1;
}

export function resetPosition() { t = 0; vel = sideVel = 0; sx = HERO_X; heading = wantHeading = 0; doorFired = false; drawnT = -1; }

/** Screen rect of the cathedral doors as framed at the end of the walk. */
export function doorRect() {
  const a = toScreen(0.333, 0.0), b = toScreen(0.667, 0.855);
  // The painted doors themselves (cut from the end frame) swing open.
  const url = frames.frameUrl(frames.duration());
  return { left: a.x, top: a.y, width: b.x - a.x, height: b.y - a.y, bg: url ? { url, w: W * stageRect.scale, h: H * stageRect.scale, x: stageRect.left - a.x, y: stageRect.top - a.y } : null };
}
export function saintScreen() { return toScreen(sx, FEET_Y); }
export function currentDef() { return current ? currentKey : null; }

/** Show a saint (catalog def). Returns when the model is on screen. */
export async function showSaint(def) {
  if (!renderer || !def) return;
  if (currentKey === def.key && current) return;
  const token = ++loadToken;
  const s = await createSaint(def);
  if (token !== loadToken) { s.dispose(); return; }
  if (current) { pivot.remove(current.object); current.dispose(); }
  current = s;
  currentKey = def.key;
  s.object.userData.pop = 0;
  pivot.add(s.object);
  anim = 'Idle_A';
  walking = false;
  s.play('Idle_A');
  layout();
}

export function cheer() {
  if (!current) return;
  const a = current.play('Interact', { once: true });
  anim = 'Interact';
  if (a) setTimeout(() => { if (current && !walking) { current.play('Idle_A'); anim = 'Idle_A'; } }, 1400);
}

export function hasWebGL() { return !!renderer; }
