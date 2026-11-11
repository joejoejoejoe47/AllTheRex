// Home scene: the mockup village as a "cover" background, hotspots that stay
// glued to the picture at any screen size, and the player's best saint as a
// real 3D model standing on the path, facing the viewer.
import { createSaint, THREE } from './saint3d.js';
import { $ } from './ui.js';

const W = 1280, H = 720;
const HERO_X = 0.484; // where the saint stands, as a fraction of the picture width
let stageRect = { left: 0, top: 0, scale: 1 };
let renderer = null, scene = null, camera = null, current = null, currentKey = null, clock = null, running = true, shadow = null;
let loadToken = 0;
const listeners = { layout: [] };

// Read-only view for the browser test.
window.__sqHome = { get key() { return currentKey; }, get loaded() { return !!current; } };

export function onLayout(fn) { listeners.layout.push(fn); }

export function layout() {
  const vw = window.innerWidth, vh = window.innerHeight;
  const scale = Math.max(vw / W, vh / H);
  let left = vw / 2 - HERO_X * W * scale;
  if (vw / vh > 1.2) left = (vw - W * scale) / 2;
  left = Math.min(0, Math.max(vw - W * scale, left));
  const top = Math.min(0, Math.max(vh - H * scale, (vh - H * scale) * 0.55));
  const stage = $('#stage');
  stage.style.transform = `translate(${left}px, ${top}px) scale(${scale})`;
  stageRect = { left, top, scale };
  resizeRenderer();
  // Saint name plate under the saint's feet.
  const plate = $('#saint-plate');
  plate.style.left = (left + HERO_X * W * scale) + 'px';
  const feet = top + 0.745 * H * scale;
  plate.style.top = Math.min(vh - 150, feet) + 'px';
  // If the signpost is cut off (tall phones), show an edge arrow instead.
  const sp = $('#signpost').getBoundingClientRect();
  $('#edge-next').hidden = !(sp.right > vw - 10 || sp.left < 0) || !current;
  for (const fn of listeners.layout) fn(stageRect);
}

export function toScreen(fx, fy) {
  return { x: stageRect.left + fx * W * stageRect.scale, y: stageRect.top + fy * H * stageRect.scale };
}

function resizeRenderer() {
  if (!renderer) return;
  const c = renderer.domElement;
  const r = c.getBoundingClientRect();
  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  renderer.setPixelRatio(dpr);
  renderer.setSize(Math.max(1, Math.round(r.width)), Math.max(1, Math.round(r.height)), false);
  camera.aspect = r.width / Math.max(1, r.height);
  camera.updateProjectionMatrix();
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
    camera = new THREE.PerspectiveCamera(30, 0.72, 0.1, 50);
    camera.position.set(0, 1.35, 7.6);
    camera.lookAt(0, 1.18, 0);
    scene.add(new THREE.HemisphereLight(0xfff3d6, 0x7c9a52, 1.5));
    const sun = new THREE.DirectionalLight(0xffe2a8, 2.4);
    sun.position.set(4, 5, 3);
    scene.add(sun);
    const rim = new THREE.DirectionalLight(0xfff0c0, 1.2);
    rim.position.set(-3, 3, -4);
    scene.add(rim);
    // Soft contact shadow on the path.
    const g = document.createElement('canvas');
    g.width = g.height = 128;
    const x = g.getContext('2d');
    const grad = x.createRadialGradient(64, 64, 4, 64, 64, 62);
    grad.addColorStop(0, 'rgba(60,40,10,0.55)');
    grad.addColorStop(1, 'rgba(60,40,10,0)');
    x.fillStyle = grad;
    x.fillRect(0, 0, 128, 128);
    shadow = new THREE.Mesh(new THREE.PlaneGeometry(1.8, 1.0), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(g), transparent: true, depthWrite: false }));
    shadow.rotation.x = -Math.PI / 2;
    shadow.position.y = 0.01;
    scene.add(shadow);
    clock = new THREE.Clock();
    renderer.setAnimationLoop(tick);
  }
  window.addEventListener('resize', layout);
  document.addEventListener('visibilitychange', () => { running = !document.hidden; });
  layout();
}

function tick() {
  if (!running || !renderer) return;
  const dt = Math.min(0.05, clock.getDelta());
  if (current) {
    current.update(dt);
    // a gentle breathing sway so the saint feels alive
    const t = performance.now() / 1000;
    current.object.rotation.y = Math.sin(t * 0.5) * 0.12;
    if (current.object.userData.pop !== undefined) {
      current.object.userData.pop = Math.min(1, current.object.userData.pop + dt * 3);
      const p = current.object.userData.pop;
      const s = p < 1 ? 0.6 + 0.4 * (1 - Math.pow(1 - p, 3)) + Math.sin(p * Math.PI) * 0.12 : 1;
      current.object.scale.setScalar(s);
    }
  }
  renderer.render(scene, camera);
}

export function pause(p) { running = !p; if (!p && clock) clock.getDelta(); }

/** Show a saint (catalog def). Returns when the model is on screen. */
export async function showSaint(def) {
  if (!renderer || !def) return;
  if (currentKey === def.key && current) return;
  const token = ++loadToken;
  const s = await createSaint(def);
  if (token !== loadToken) { s.dispose(); return; }
  if (current) { scene.remove(current.object); current.dispose(); }
  current = s;
  currentKey = def.key;
  s.object.userData.pop = 0;
  s.object.scale.setScalar(0.6);
  scene.add(s.object);
  s.play('Idle_A');
  layout();
}

export function cheer() {
  if (!current) return;
  const a = current.play('Interact', { once: true });
  if (a) setTimeout(() => current && current.play('Idle_A'), 1400);
}

export function hasWebGL() { return !!renderer; }
