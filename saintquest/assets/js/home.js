// Home scene: the village picture as a "cover" background, hotspots that stay
// glued to the picture at any screen size, and the player's best saint as a
// real 3D model. WASD / arrows / joystick / tap walk the saint around the
// painted path and grass with perspective (smaller as it walks toward the
// cathedral); reaching the cathedral doors opens the church.
import { createSaint, THREE } from './saint3d.js';
import { $ } from './ui.js';
import { direction, isTouch, makeJoystick, clearKeys } from './walkinput.js';

const W = 1280, H = 720;
const HERO_X = 0.484; // where the saint starts, as a fraction of the picture width
const START_Y = 0.72; // feet, fraction of the picture height
const PPU = 92; // picture pixels per model unit at the start spot
const HORIZON = 0.2965; // perspective vanishing line (size → 0 there)
const DOOR = { x: 0.502, y: 0.372 }; // cathedral doors (feet line)
const ZOOM_MAX = 0.15;
// Walkable band: [feetY, minX, maxX] from the doors down to the front grass.
const BOUNDS = [
  [0.365, 0.490, 0.514], [0.40, 0.480, 0.540], [0.44, 0.500, 0.580], [0.48, 0.470, 0.590],
  [0.53, 0.430, 0.610], [0.60, 0.380, 0.640], [0.66, 0.280, 0.680], [0.72, 0.200, 0.720],
  [0.80, 0.180, 0.780], [0.86, 0.160, 0.800],
];
const Y_MIN = BOUNDS[0][0], Y_MAX = BOUNDS[BOUNDS.length - 1][0];
export const VILLAGERS = [
  { deed: 'apples', name: 'Grandma Agnes', x: 0.215, y: 0.70 },
  { deed: 'lamb', name: 'Little Tobias', x: 0.275, y: 0.68 },
  { deed: 'chapel', name: 'Brother Peter', x: 0.655, y: 0.685 },
];

let stageRect = { left: 0, top: 0, scale: 1 };
let base = { left: 0, top: 0, scale: 1 };
let renderer = null, scene = null, camera = null, current = null, currentKey = null, clock = null, running = true, shadow = null, pivot = null;
let loadToken = 0;
let zoom = 1;
const pos = { x: HERO_X, y: START_Y };
let heading = 0, wantHeading = 0, idleFor = 0, walking = false, anim = 'Idle_A', target = null, doorFired = false, frozen = false;
const listeners = { layout: [], door: [], near: [] };
let nearVillager = null;

// Read-only view for the browser test.
window.__sqHome = {
  get key() { return currentKey; }, get loaded() { return !!current; },
  get pos() { return { x: pos.x, y: pos.y }; }, get walking() { return walking; }, get anim() { return anim; },
  get zoom() { return zoom; }, get heading() { return heading; }, get near() { return nearVillager && nearVillager.deed; },
};

export function onLayout(fn) { listeners.layout.push(fn); }
export function onDoor(fn) { listeners.door.push(fn); }
export function onNear(fn) { listeners.near.push(fn); }
export const sizeAt = (fy) => Math.max(0.05, (fy - HORIZON) / (START_Y - HORIZON));

function rangeAt(fy) {
  if (fy <= BOUNDS[0][0]) return [BOUNDS[0][1], BOUNDS[0][2]];
  for (let i = 1; i < BOUNDS.length; i++) {
    const [y1, a1, b1] = BOUNDS[i];
    if (fy <= y1) {
      const [y0, a0, b0] = BOUNDS[i - 1];
      const t = (fy - y0) / (y1 - y0);
      return [a0 + (a1 - a0) * t, b0 + (b1 - b0) * t];
    }
  }
  const l = BOUNDS[BOUNDS.length - 1];
  return [l[1], l[2]];
}
function clampPos(p) {
  p.y = Math.min(Y_MAX, Math.max(Y_MIN, p.y));
  const [a, b] = rangeAt(p.y);
  p.x = Math.min(b, Math.max(a, p.x));
  return p;
}

export function layout() {
  const vw = window.innerWidth, vh = window.innerHeight;
  const scale = Math.max(vw / W, vh / H);
  let left = vw / 2 - HERO_X * W * scale;
  if (vw / vh > 1.2) left = (vw - W * scale) / 2;
  left = Math.min(0, Math.max(vw - W * scale, left));
  const top = Math.min(0, Math.max(vh - H * scale, (vh - H * scale) * 0.55));
  base = { left, top, scale };
  applyZoom(true);
  resizeRenderer();
  // Saint name plate stays where it always was (under the starting spot).
  const plate = $('#saint-plate');
  plate.style.left = (left + HERO_X * W * scale) + 'px';
  plate.style.top = Math.min(vh - 150, top + 0.745 * H * scale) + 'px';
  // If the signpost is cut off (tall phones), show an edge arrow instead.
  const sp = $('#signpost').getBoundingClientRect();
  $('#edge-next').hidden = !(sp.right > vw - 10 || sp.left < 0) || !current;
  for (const fn of listeners.layout) fn(stageRect);
}

/** Zoom gently toward the cathedral as the saint walks up the path. */
function applyZoom(force = false) {
  const p = Math.min(1, Math.max(0, (START_Y - pos.y) / (START_Y - DOOR.y)));
  const z = 1 + ZOOM_MAX * p * p * (3 - 2 * p);
  if (!force && Math.abs(z - zoom) < 0.0004) return;
  zoom = z;
  const s = base.scale * z;
  const left = base.left + DOOR.x * W * base.scale * (1 - z);
  const top = base.top + 0.33 * H * base.scale * (1 - z);
  stageRect = { left, top, scale: s };
  $('#stage').style.transform = `translate(${left}px, ${top}px) scale(${s})`;
}

export function toScreen(fx, fy) {
  return { x: stageRect.left + fx * W * stageRect.scale, y: stageRect.top + fy * H * stageRect.scale };
}
function toPicture(cx, cy) {
  return { x: (cx - stageRect.left) / (W * stageRect.scale), y: (cy - stageRect.top) / (H * stageRect.scale) };
}

function resizeRenderer() {
  if (!renderer) return;
  const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
  renderer.setPixelRatio(dpr);
  // Buffer matches the un-zoomed stage size; the gentle zoom is a CSS scale.
  renderer.setSize(Math.max(1, Math.round(W * base.scale)), Math.max(1, Math.round(H * base.scale)), false);
}

export function initHome() {
  const canvas = $('#saint-canvas');
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
    // Fixed camera: picture pixels, x right, y up (=-picture y).
    camera = new THREE.OrthographicCamera(0, W, 0, -H, -2000, 2000);
    camera.position.set(0, 0, 1000);
    scene.add(new THREE.HemisphereLight(0xfff3d6, 0x7c9a52, 1.5));
    const sun = new THREE.DirectionalLight(0xffe2a8, 2.4);
    sun.position.set(4, 5, 3);
    scene.add(sun);
    const rim = new THREE.DirectionalLight(0xfff0c0, 1.2);
    rim.position.set(-3, 3, -4);
    scene.add(rim);
    // Soft contact shadow under the feet.
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
    pivot.rotation.x = 0.07; // look very slightly down on the saint
    scene.add(pivot);
    clock = new THREE.Clock();
    renderer.setAnimationLoop(tick);
  }
  // Tap / click on the ground to walk there.
  $('#stage').addEventListener('click', (e) => {
    if (e.target.id !== 'bg' || frozen) return;
    const p = toPicture(e.clientX, e.clientY);
    if (p.y < Y_MIN - 0.03) { if (Math.abs(p.x - DOOR.x) < 0.05 && p.y > 0.28) target = { x: DOOR.x, y: Y_MIN - 0.01 }; return; }
    target = clampPos({ x: p.x, y: p.y });
  });
  $('#saint-hit').addEventListener('click', () => $('#saint-canvas').dispatchEvent(new Event('click')));
  if (isTouch()) document.body.append(makeJoystick('home-stick'));
  window.addEventListener('resize', layout);
  document.addEventListener('visibilitychange', () => { running = !document.hidden; });
  layout();
}

function setAnim(name, speed = 1) {
  if (!current) return;
  if (anim !== name) { current.play(name, { speed }); anim = name; }
}

function step(dt) {
  let d = frozen ? { x: 0, y: 0 } : direction();
  if ((d.x || d.y) && target) target = null;
  if (!d.x && !d.y && target && !frozen) {
    const dx = (target.x - pos.x) * (W / H), dy = target.y - pos.y;
    const l = Math.hypot(dx, dy);
    if (l < 0.006) target = null; else d = { x: dx / l, y: dy / l };
  }
  const moving = !!(d.x || d.y);
  if (moving) {
    const s = sizeAt(pos.y);
    const before = { x: pos.x, y: pos.y };
    pos.x += d.x * 0.105 * s * dt;
    pos.y += d.y * 0.19 * s * dt;
    clampPos(pos);
    if (target && Math.hypot(pos.x - before.x, pos.y - before.y) < 1e-5) target = null;
    wantHeading = Math.atan2(d.x, d.y);
    idleFor = 0;
    if (!doorFired && pos.y <= DOOR.y && Math.abs(pos.x - DOOR.x) < 0.03) {
      doorFired = true;
      target = null;
      for (const fn of listeners.door) fn();
    }
  } else {
    idleFor += dt;
    if (idleFor > 2.5) wantHeading = 0; // turn back to face the viewer
  }
  if (moving !== walking) {
    walking = moving;
    setAnim(moving ? 'Walking_A' : 'Idle_A', moving ? 1.25 : 1);
  }
  let diff = wantHeading - heading;
  diff = Math.atan2(Math.sin(diff), Math.cos(diff));
  heading += diff * Math.min(1, dt * 10);
  if (walking || idleFor < 0.1) applyZoom();
  // Who is close enough to help?
  let near = null;
  for (const v of VILLAGERS) {
    if (Math.hypot((v.x - pos.x) * (W / H), v.y - pos.y) < 0.11) { near = v; break; }
  }
  if (near !== nearVillager) { nearVillager = near; for (const fn of listeners.near) fn(near); }
}

function tick() {
  if (!running || !renderer) return;
  const dt = Math.min(0.05, clock.getDelta());
  if (current) {
    step(dt);
    current.update(dt);
    const s = sizeAt(pos.y);
    let pop = 1;
    if (current.object.userData.pop !== undefined) {
      current.object.userData.pop = Math.min(1, current.object.userData.pop + dt * 3);
      const p = current.object.userData.pop;
      pop = p < 1 ? 0.6 + 0.4 * (1 - Math.pow(1 - p, 3)) + Math.sin(p * Math.PI) * 0.12 : 1;
    }
    const k = PPU * s * pop;
    pivot.position.set(pos.x * W, -pos.y * H, 0);
    pivot.scale.setScalar(k);
    const sway = !walking && Math.abs(heading) < 0.05 ? Math.sin(performance.now() / 2000) * 0.12 : 0;
    current.object.rotation.y = heading + sway;
    shadow.position.set(pos.x * W, -pos.y * H + 2 * s, -500);
    shadow.scale.set(150 * s, 42 * s, 1);
    // Clickable box that follows the saint (opens its page).
    const hit = $('#saint-hit');
    const hw = 110 * s, hh = 230 * s;
    hit.style.left = (pos.x * W - hw / 2) + 'px';
    hit.style.top = (pos.y * H - hh) + 'px';
    hit.style.width = hw + 'px';
    hit.style.height = hh + 'px';
    const plate = $('#saint-plate');
    plate.classList.toggle('dim', pos.y > 0.69 && Math.abs(pos.x - HERO_X) < 0.13 && (pos.y - START_Y) > -0.01 && Math.hypot(pos.x - HERO_X, pos.y - START_Y) > 0.02);
  }
  renderer.render(scene, camera);
}

export function pause(p) { running = !p; if (!p && clock) clock.getDelta(); }

/** Stop walking input (e.g. during the church transition). */
export function freeze(f) { frozen = f; target = null; if (f) clearKeys(); }

/** Put the saint back outside the church doors, facing the viewer. */
export function placeAtDoor() {
  pos.x = 0.52; pos.y = 0.43;
  clampPos(pos);
  heading = wantHeading = 0;
  idleFor = 0;
  doorFired = false;
  target = null;
  applyZoom(true);
}

export function resetPosition() { pos.x = HERO_X; pos.y = START_Y; heading = wantHeading = 0; doorFired = false; target = null; applyZoom(true); }

/** Screen rect of the cathedral doors (for the door-opening transition). */
export function doorRect() {
  const a = toScreen(DOOR.x - 0.016, 0.305), b = toScreen(DOOR.x + 0.016, DOOR.y);
  return { left: a.x, top: a.y, width: b.x - a.x, height: b.y - a.y };
}
export function saintScreen() { return toScreen(pos.x, pos.y); }
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
