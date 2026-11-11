// A small turntable 3D viewer for the store, trade plate and saint pages.
// Falls back to the pre-rendered picture if WebGL is not available.
import { createSaint, THREE } from './saint3d.js';
import { el, thumb } from './ui.js';

const live = new Set();

export function mountViewer(container, def, { spin = true, onPlate = false } = {}) {
  container.replaceChildren(el('img', { src: thumb(def.key), alt: def.name }));
  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power' });
  } catch {
    return { dispose() {} };
  }
  const canvas = renderer.domElement;
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  const scene = new THREE.Scene();
  const cam = new THREE.PerspectiveCamera(28, 0.75, 0.1, 50);
  cam.position.set(0, 1.5, onPlate ? 7.2 : 6.8);
  cam.lookAt(0, 1.15, 0);
  scene.add(new THREE.HemisphereLight(0xfff3d6, 0x88a0c0, 1.6));
  const key = new THREE.DirectionalLight(0xffffff, 2.2);
  key.position.set(3, 5, 4);
  scene.add(key);
  const rim = new THREE.DirectionalLight(0xffe7a0, 1.4);
  rim.position.set(-3, 2, -3);
  scene.add(rim);
  const disc = new THREE.Mesh(new THREE.CylinderGeometry(1.1, 1.2, 0.12, 48), new THREE.MeshStandardMaterial({ color: onPlate ? 0xf5d77a : 0xffe9a8, metalness: 0.3, roughness: 0.4 }));
  disc.position.y = -0.06;
  scene.add(disc);
  let saint = null;
  let angle = 0, dragging = false, lastX = 0, alive = true;
  const clock = new THREE.Clock();
  createSaint(def).then((s) => {
    if (!alive) { s.dispose(); return; }
    saint = s;
    scene.add(s.object);
    container.replaceChildren(canvas);
    resize();
  }).catch(() => {});
  function resize() {
    const r = container.getBoundingClientRect();
    if (r.width < 2) return;
    renderer.setSize(r.width, r.height, false);
    cam.aspect = r.width / r.height;
    cam.updateProjectionMatrix();
  }
  const ro = new ResizeObserver(resize);
  ro.observe(container);
  canvas.style.touchAction = 'pan-y';
  canvas.addEventListener('pointerdown', (e) => { dragging = true; lastX = e.clientX; canvas.setPointerCapture(e.pointerId); });
  canvas.addEventListener('pointermove', (e) => { if (dragging) { angle += (e.clientX - lastX) * 0.012; lastX = e.clientX; } });
  canvas.addEventListener('pointerup', () => { dragging = false; });
  canvas.addEventListener('pointercancel', () => { dragging = false; });
  renderer.setAnimationLoop(() => {
    if (!container.isConnected) { api.dispose(); return; }
    const dt = Math.min(0.05, clock.getDelta());
    if (saint) {
      if (spin && !dragging) angle += dt * 0.5;
      saint.object.rotation.y = angle;
      saint.update(dt);
    }
    renderer.render(scene, cam);
  });
  const api = {
    dispose() {
      if (!alive) return;
      alive = false;
      live.delete(api);
      renderer.setAnimationLoop(null);
      ro.disconnect();
      if (saint) saint.dispose();
      renderer.dispose();
      renderer.forceContextLoss?.();
    },
    play(name, opts) { return saint && saint.play(name, opts); },
  };
  live.add(api);
  return api;
}

export function disposeAll() { for (const v of [...live]) v.dispose(); }
