// The cathedral interior: a huge, bright nave with stone columns, stained
// glass, light beams and an altar — and no pews, just an open floor. Built
// from primitives and canvas textures. Fixed third-person follow camera.
import { createSaint, THREE } from './saint3d.js';
import { direction, isTouch, makeJoystick, clearKeys } from './walkinput.js';

const HALF_W = 15;          // nave + aisles half width
const DOOR_Z = 45;          // entrance wall
const APSE_Z = -55;         // far wall
const STEPS_Z = -39;        // front edge of the altar steps
const COL_X = 9;
const COL_Z = [35, 25, 15, 5, -5, -15, -25, -35];
const COL_R = 2.0;          // column base + saint radius
const SPEED = 8.5;

let active = null;
window.__sqChurch = {
  get active() { return !!active; },
  get pos() { return active ? { x: active.player.position.x, z: active.player.position.z } : null; },
  get anim() { return active ? active.anim : null; },
  get moving() { return active ? active.moving : false; },
};

function canvasTex(w, h, draw, repeat) {
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  draw(c.getContext('2d'), w, h);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  t.anisotropy = 4;
  if (repeat) { t.wrapS = t.wrapT = THREE.RepeatWrapping; t.repeat.set(repeat[0], repeat[1]); }
  return t;
}

function rng(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296); }

const floorTex = () => canvasTex(512, 512, (x, w) => {
  const r = rng(7);
  const t = w / 2;
  for (let i = 0; i < 2; i++) for (let j = 0; j < 2; j++) {
    x.fillStyle = (i + j) % 2 ? '#cdb58a' : '#f6eedd';
    x.fillRect(i * t, j * t, t, t);
    x.strokeStyle = 'rgba(150,120,80,0.12)';
    x.lineWidth = 2;
    for (let k = 0; k < 4; k++) { x.beginPath(); x.moveTo(i * t + r() * t, j * t); x.bezierCurveTo(i * t + r() * t, j * t + t * 0.3, i * t + r() * t, j * t + t * 0.7, i * t + r() * t, j * t + t); x.stroke(); }
  }
  x.strokeStyle = '#c9a85c'; x.lineWidth = 6; x.strokeRect(0, 0, w, w);
  x.fillStyle = '#d4af52';
  x.beginPath(); x.arc(t, t, 18, 0, Math.PI * 2); x.fill();
}, [8, 26]);

const stoneTex = (rx, ry) => canvasTex(512, 512, (x, w, h) => {
  const r = rng(3);
  x.fillStyle = '#b9a47e'; x.fillRect(0, 0, w, h);
  const bh = 64;
  for (let row = 0; row < h / bh; row++) {
    const off = row % 2 ? 64 : 0;
    for (let col = -1; col < w / 128 + 1; col++) {
      const v = 205 + Math.floor(r() * 30);
      x.fillStyle = `rgb(${v},${v - 14},${v - 40})`;
      x.fillRect(col * 128 + off + 2, row * bh + 2, 124, bh - 4);
    }
  }
}, [rx, ry]);

const glassTex = (seed, tall = true) => canvasTex(256, tall ? 512 : 512, (x, w, h) => {
  const r = rng(seed);
  const pal = ['#1e5bd8', '#d62839', '#f4b41a', '#2a9d5c', '#7b3fd1', '#2bb0e6', '#f07c1b', '#fdf0a6'];
  x.fillStyle = '#2b2116'; x.fillRect(0, 0, w, h);
  const cw = w / 6, ch = h / 12;
  for (let i = 0; i < 6; i++) for (let j = 0; j < 12; j++) {
    x.fillStyle = pal[Math.floor(r() * pal.length)];
    x.fillRect(i * cw + 2, j * ch + 2, cw - 4, ch - 4);
  }
  // A glowing saint medallion with a halo and cross.
  const cx = w / 2, cy = h * 0.42, rad = w * 0.34;
  x.fillStyle = '#2b2116'; x.beginPath(); x.arc(cx, cy, rad + 6, 0, 7); x.fill();
  x.fillStyle = '#fff3b0'; x.beginPath(); x.arc(cx, cy, rad, 0, 7); x.fill();
  x.fillStyle = '#f4b41a'; x.beginPath(); x.arc(cx, cy - rad * 0.35, rad * 0.42, 0, 7); x.fill();
  x.fillStyle = '#fbe7c6'; x.beginPath(); x.arc(cx, cy - rad * 0.3, rad * 0.26, 0, 7); x.fill();
  x.fillStyle = pal[Math.floor(r() * 5)];
  x.beginPath(); x.moveTo(cx - rad * 0.55, cy + rad * 0.85); x.lineTo(cx, cy - rad * 0.05); x.lineTo(cx + rad * 0.55, cy + rad * 0.85); x.fill();
  x.strokeStyle = '#2b2116'; x.lineWidth = 5; x.strokeRect(3, 3, w - 6, h - 6);
});

const roseTex = () => canvasTex(512, 512, (x, w) => {
  const c = w / 2;
  const pal = ['#1e5bd8', '#d62839', '#f4b41a', '#2a9d5c', '#7b3fd1', '#2bb0e6'];
  x.fillStyle = '#2b2116'; x.beginPath(); x.arc(c, c, c, 0, 7); x.fill();
  for (let ring = 4; ring >= 1; ring--) {
    const n = ring * 8;
    for (let i = 0; i < n; i++) {
      x.fillStyle = pal[(i + ring) % pal.length];
      x.beginPath(); x.moveTo(c, c);
      x.arc(c, c, ring * c / 4.3, (i / n) * Math.PI * 2 + 0.02, ((i + 1) / n) * Math.PI * 2 - 0.02);
      x.closePath(); x.fill();
    }
  }
  x.fillStyle = '#fff3b0'; x.beginPath(); x.arc(c, c, c * 0.16, 0, 7); x.fill();
  x.fillStyle = '#f4b41a'; x.fillRect(c - 8, c - 40, 16, 80); x.fillRect(c - 30, c - 18, 60, 16);
});

const beamTex = () => canvasTex(64, 256, (x, w, h) => {
  const g = x.createLinearGradient(0, 0, 0, h);
  g.addColorStop(0, 'rgba(255,244,200,0.9)');
  g.addColorStop(1, 'rgba(255,244,200,0)');
  x.fillStyle = g; x.fillRect(0, 0, w, h);
  const s = x.createLinearGradient(0, 0, w, 0);
  s.addColorStop(0, 'rgba(0,0,0,1)'); s.addColorStop(0.3, 'rgba(0,0,0,0)'); s.addColorStop(0.7, 'rgba(0,0,0,0)'); s.addColorStop(1, 'rgba(0,0,0,1)');
  x.globalCompositeOperation = 'destination-out'; x.fillStyle = s; x.fillRect(0, 0, w, h);
});

const vaultTex = () => canvasTex(512, 512, (x, w, h) => {
  const r = rng(9);
  const g = x.createLinearGradient(0, 0, 0, h);
  g.addColorStop(0, '#2f5fa8'); g.addColorStop(1, '#4a7cc4');
  x.fillStyle = g; x.fillRect(0, 0, w, h);
  x.fillStyle = '#f6d36b';
  for (let i = 0; i < 70; i++) {
    const cx = r() * w, cy = r() * h, s = 3 + r() * 5;
    x.beginPath();
    for (let k = 0; k < 10; k++) { const a = (k / 10) * Math.PI * 2, rr = k % 2 ? s * 0.45 : s; x.lineTo(cx + Math.cos(a) * rr, cy + Math.sin(a) * rr); }
    x.fill();
  }
}, [6, 3]);

function archShape(w, h) {
  const s = new THREE.Shape();
  s.moveTo(-w / 2, 0); s.lineTo(w / 2, 0); s.lineTo(w / 2, h - w * 0.6);
  s.quadraticCurveTo(w / 2, h - w * 0.1, 0, h);
  s.quadraticCurveTo(-w / 2, h - w * 0.1, -w / 2, h - w * 0.6);
  s.lineTo(-w / 2, 0);
  return s;
}
function uvFit(geo) {
  geo.computeBoundingBox();
  const b = geo.boundingBox, p = geo.attributes.position, uv = [];
  for (let i = 0; i < p.count; i++) uv.push((p.getX(i) - b.min.x) / (b.max.x - b.min.x), (p.getY(i) - b.min.y) / (b.max.y - b.min.y));
  geo.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
  return geo;
}

function buildCathedral(scene) {
  const disposables = [];
  const keep = (o) => { disposables.push(o); return o; };
  const L = (opts) => keep(new THREE.MeshLambertMaterial(opts));
  const stone = L({ map: keep(stoneTex(6, 3)) });
  const stoneV = L({ map: keep(vaultTex()), side: THREE.DoubleSide });
  const pale = L({ color: 0xeadcc0 });
  const gold = keep(new THREE.MeshStandardMaterial({ color: 0xf2c14e, metalness: 0.75, roughness: 0.3, emissive: 0x6a4a08, emissiveIntensity: 0.35 }));
  const add = (geo, mat, x, y, z, rot) => { const m = new THREE.Mesh(keep(geo), mat); m.position.set(x, y, z); if (rot) m.rotation.set(rot[0] || 0, rot[1] || 0, rot[2] || 0); scene.add(m); return m; };
  const len = DOOR_Z - APSE_Z, midZ = (DOOR_Z + APSE_Z) / 2;

  // Floor and red runner (no pews — the whole floor is open).
  add(new THREE.PlaneGeometry(HALF_W * 2, len), L({ map: keep(floorTex()) }), 0, 0, midZ, [-Math.PI / 2]);
  add(new THREE.PlaneGeometry(3.2, DOOR_Z - STEPS_Z), L({ color: 0xa3252b }), 0, 0.02, (DOOR_Z + STEPS_Z) / 2, [-Math.PI / 2]);
  add(new THREE.PlaneGeometry(3.6, DOOR_Z - STEPS_Z), L({ color: 0xd9a93f }), 0, 0.015, (DOOR_Z + STEPS_Z) / 2, [-Math.PI / 2]);

  // Side walls, vaulted ceiling and ribs.
  for (const sx of [-1, 1]) add(new THREE.BoxGeometry(1, 20, len), stone, sx * (HALF_W + 0.5), 10, midZ);
  add(new THREE.CylinderGeometry(HALF_W, HALF_W, len, 32, 1, true, Math.PI / 2, Math.PI), stoneV, 0, 20, midZ, [Math.PI / 2]);
  for (let z = DOOR_Z - 5; z > APSE_Z; z -= 10) add(new THREE.TorusGeometry(HALF_W - 0.2, 0.35, 6, 32, Math.PI), pale, 0, 20, z);
  add(new THREE.BoxGeometry(0.8, 0.8, len), gold, 0, 20 + HALF_W - 0.4, midZ);

  // Columns with bases, capitals and arches between them.
  for (const sx of [-1, 1]) {
    for (const z of COL_Z) {
      add(new THREE.BoxGeometry(3, 1, 3), pale, sx * COL_X, 0.5, z);
      add(new THREE.CylinderGeometry(1.05, 1.15, 15, 16), stone, sx * COL_X, 8.5, z);
      add(new THREE.BoxGeometry(2.8, 1, 2.8), gold, sx * COL_X, 16.4, z);
    }
    for (let i = 0; i < COL_Z.length - 1; i++) {
      add(new THREE.TorusGeometry(5, 0.55, 6, 20, Math.PI), pale, sx * COL_X, 16.9, (COL_Z[i] + COL_Z[i + 1]) / 2, [0, Math.PI / 2, 0]);
    }
    add(new THREE.BoxGeometry(1.2, 1.2, COL_Z[0] - COL_Z[COL_Z.length - 1] + 3), pale, sx * COL_X, 22.4, (COL_Z[0] + COL_Z[COL_Z.length - 1]) / 2);
  }

  // Stained glass windows and slanting light beams.
  const beam = keep(beamTex());
  for (const sx of [-1, 1]) {
    COL_Z.slice(0, -1).forEach((z0, i) => {
      const z = z0 - 5;
      const g = uvFit(new THREE.ShapeGeometry(archShape(5.2, 15)));
      const m = add(g, keep(new THREE.MeshBasicMaterial({ map: keep(glassTex(11 + i * 7 + (sx > 0 ? 3 : 0))), side: THREE.DoubleSide })), sx * (HALF_W - 0.02), 3, z, [0, -sx * Math.PI / 2, 0]);
      m.renderOrder = 1;
      // Frame
      add(new THREE.BoxGeometry(0.3, 0.5, 6), gold, sx * (HALF_W - 0.1), 2.9, z);
      if (sx < 0 && i % 2 === 0) {
        const b = add(new THREE.PlaneGeometry(4.5, 30), keep(new THREE.MeshBasicMaterial({ map: beam, transparent: true, opacity: 0.42, blending: THREE.AdditiveBlending, depthWrite: false, side: THREE.DoubleSide })), -HALF_W + 9, 6.5, z, [0, Math.PI / 2, -0.95]);
        b.renderOrder = 2;
      }
    });
  }

  // Entrance wall with an open doorway (bright daylight outside).
  const doorW = 7, doorH = 10;
  add(new THREE.BoxGeometry(HALF_W - doorW / 2, 36, 1.2), stone, -(HALF_W + doorW / 2) / 2, 18, DOOR_Z + 0.6);
  add(new THREE.BoxGeometry(HALF_W - doorW / 2, 36, 1.2), stone, (HALF_W + doorW / 2) / 2, 18, DOOR_Z + 0.6);
  add(new THREE.BoxGeometry(doorW, 36 - doorH, 1.2), stone, 0, doorH + (36 - doorH) / 2, DOOR_Z + 0.6);
  add(new THREE.PlaneGeometry(doorW, doorH), keep(new THREE.MeshBasicMaterial({ color: 0xfff6d8 })), 0, doorH / 2, DOOR_Z + 1.3, [0, Math.PI, 0]);
  const wood = L({ color: 0x7a4a24 });
  for (const sx of [-1, 1]) {
    const leaf = new THREE.Group();
    leaf.position.set(sx * doorW / 2, 0, DOOR_Z);
    leaf.rotation.y = sx * 1.25;
    const panel = new THREE.Mesh(keep(new THREE.BoxGeometry(doorW / 2, doorH, 0.35)), wood);
    panel.position.set(-sx * doorW / 4, doorH / 2, 0);
    leaf.add(panel);
    const band = new THREE.Mesh(keep(new THREE.BoxGeometry(doorW / 2, 0.35, 0.45)), gold);
    band.position.set(-sx * doorW / 4, doorH * 0.7, 0);
    leaf.add(band);
    scene.add(leaf);
  }
  add(new THREE.TorusGeometry(doorW / 2 + 0.4, 0.4, 6, 20, Math.PI), gold, 0, doorH, DOOR_Z - 0.05);
  // Rose window over the entrance (inside face).
  add(new THREE.CircleGeometry(5, 40), keep(new THREE.MeshBasicMaterial({ map: keep(roseTex()) })), 0, 22, DOOR_Z - 0.05, [0, Math.PI, 0]);

  // Apse: far wall, big rose window, golden cross, altar on steps.
  add(new THREE.BoxGeometry(HALF_W * 2 + 2, 36, 1.2), stone, 0, 18, APSE_Z - 0.6);
  add(new THREE.CircleGeometry(7, 48), keep(new THREE.MeshBasicMaterial({ map: keep(roseTex()) })), 0, 25, APSE_Z + 0.05);
  add(new THREE.TorusGeometry(7.3, 0.5, 8, 48), gold, 0, 25, APSE_Z + 0.1);
  add(new THREE.BoxGeometry(1.4, 11, 0.6), gold, 0, 11.5, APSE_Z + 0.5);
  add(new THREE.BoxGeometry(6.5, 1.4, 0.6), gold, 0, 14, APSE_Z + 0.5);
  [[24, 0.5, STEPS_Z - 8], [19, 1.0, STEPS_Z - 9.5], [14, 1.5, STEPS_Z - 11]].forEach(([w, h, z], i) => {
    add(new THREE.BoxGeometry(w, 0.5, (APSE_Z - z) * -2), i % 2 ? pale : L({ color: 0xe9dcc3 }), 0, h - 0.25, z);
  });
  add(new THREE.BoxGeometry(8, 2.4, 3), pale, 0, 2.7, -48);
  add(new THREE.BoxGeometry(8.2, 0.25, 3.2), gold, 0, 3.95, -48);
  add(new THREE.BoxGeometry(3.4, 1.8, 0.1), L({ color: 0xa3252b }), 0, 2.6, -46.45);
  add(new THREE.BoxGeometry(0.4, 1.4, 0.1), gold, 0, 2.6, -46.38);
  add(new THREE.BoxGeometry(1.2, 0.4, 0.1), gold, 0, 2.9, -46.38);
  const flameMat = keep(new THREE.MeshBasicMaterial({ color: 0xffd36b }));
  for (const cx of [-3, -1.5, 1.5, 3]) {
    add(new THREE.CylinderGeometry(0.12, 0.15, 1.2, 8), pale, cx, 4.7, -48);
    add(new THREE.SphereGeometry(0.15, 8, 6), flameMat, cx, 5.45, -48);
  }
  for (const sx of [-1, 1]) {
    add(new THREE.CylinderGeometry(0.25, 0.4, 3.5, 10), gold, sx * 6, 3.25, -44.5);
    add(new THREE.SphereGeometry(0.3, 8, 6), flameMat, sx * 6, 5.2, -44.5);
  }

  // Floating dust in the light.
  const n = 500, pts = new Float32Array(n * 3), r = rng(5);
  for (let i = 0; i < n; i++) { pts[i * 3] = (r() - 0.5) * 28; pts[i * 3 + 1] = r() * 18; pts[i * 3 + 2] = APSE_Z + r() * len; }
  const dg = keep(new THREE.BufferGeometry());
  dg.setAttribute('position', new THREE.BufferAttribute(pts, 3));
  const dust = new THREE.Points(dg, keep(new THREE.PointsMaterial({ color: 0xfff1c0, size: 0.12, transparent: true, opacity: 0.7, depthWrite: false, blending: THREE.AdditiveBlending })));
  scene.add(dust);

  // Light.
  scene.add(new THREE.HemisphereLight(0xfff2d8, 0x9c845a, 1.25));
  scene.add(new THREE.AmbientLight(0xffffff, 0.2));
  const sun = new THREE.DirectionalLight(0xffe7b8, 1.6);
  sun.position.set(-20, 30, 10);
  scene.add(sun);
  const altarLight = new THREE.PointLight(0xffd27a, 60, 45, 1.6);
  altarLight.position.set(0, 9, -44);
  scene.add(altarLight);
  return { disposables, dust };
}

function blocked(x, z) {
  for (const sx of [-1, 1]) for (const cz of COL_Z) if (Math.hypot(x - sx * COL_X, z - cz) < COL_R) return true;
  return false;
}

/** Door-opening transition over the painted doors. Resolves when the screen is white. */
export function doorTransition(rect) {
  return new Promise((resolve) => {
    const fx = document.createElement('div');
    fx.id = 'door-fx';
    const w = Math.max(rect.width, 40), h = Math.max(rect.height, 56);
    const frame = document.createElement('div');
    frame.className = 'door-frame';
    Object.assign(frame.style, { left: rect.left + rect.width / 2 - w / 2 + 'px', top: rect.top + rect.height - h + 'px', width: w + 'px', height: h + 'px' });
    frame.innerHTML = '<div class="door-glow"></div><div class="door-leaf l"></div><div class="door-leaf r"></div>';
    const white = document.createElement('div');
    white.className = 'door-white';
    fx.append(frame, white);
    document.body.append(fx);
    requestAnimationFrame(() => requestAnimationFrame(() => fx.classList.add('open')));
    setTimeout(() => {
      const cx = rect.left + rect.width / 2, cy = rect.top + rect.height / 2;
      frame.style.transformOrigin = `${cx - parseFloat(frame.style.left)}px ${cy - parseFloat(frame.style.top)}px`;
      fx.classList.add('zoom');
    }, 750);
    setTimeout(() => resolve(fx), 1350);
  });
}

/** Enter the church. def = saint catalog entry. Returns a promise resolving when shown. */
export async function enterChurch(def, { onLeave, fx } = {}) {
  if (active) return;
  const root = document.createElement('div');
  root.id = 'church-root';
  const canvas = document.createElement('canvas');
  root.append(canvas);
  const title = document.createElement('div');
  title.className = 'church-title';
  title.innerHTML = `<b>Cathedral of All Saints</b><small>${isTouch() ? 'Walk with the joystick' : 'Walk with W A S D or the arrow keys'} · the door behind you leads home</small>`;
  root.append(title);
  const leave = document.createElement('button');
  leave.type = 'button';
  leave.id = 'leave-church';
  leave.className = 'btn';
  leave.textContent = '🚪 Leave church';
  root.append(leave);
  if (isTouch()) root.append(makeJoystick('church-stick'));
  document.body.append(root);
  document.body.classList.add('in-church');

  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas, antialias: true, powerPreference: 'high-performance' });
  } catch (e) {
    root.remove();
    document.body.classList.remove('in-church');
    throw e;
  }
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.0;
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0xe8d6b0);
  scene.fog = new THREE.Fog(0xe8d6b0, 70, 230);
  const camera = new THREE.PerspectiveCamera(55, 1, 0.1, 400);
  const built = buildCathedral(scene);
  const saint = await createSaint(def);
  const player = saint.object;
  player.scale.setScalar(1.15);
  player.position.set(0, 0, DOOR_Z - 9);
  player.rotation.y = Math.PI;
  scene.add(player);
  const camOff = new THREE.Vector3(0, 4.4, 9.5);
  camera.position.copy(player.position).add(camOff);
  camera.position.z = Math.min(camera.position.z, DOOR_Z - 0.3);
  camera.lookAt(player.position.x, 3.4, player.position.z - 6);
  const resize = () => {
    renderer.setSize(window.innerWidth, window.innerHeight, false);
    camera.aspect = window.innerWidth / Math.max(1, window.innerHeight);
    camera.updateProjectionMatrix();
  };
  resize();
  window.addEventListener('resize', resize);

  const clock = new THREE.Clock();
  let heading = Math.PI, leaving = false, entered = performance.now();
  const state = { player, anim: 'Idle_A', moving: false };
  active = state;
  const look = new THREE.Vector3();
  const tick = () => {
    const dt = Math.min(0.05, clock.getDelta());
    const d = leaving ? { x: 0, y: 0 } : direction();
    const moving = !!(d.x || d.y);
    if (moving) {
      const p = player.position;
      const nx = Math.min(HALF_W - 0.8, Math.max(-HALF_W + 0.8, p.x + d.x * SPEED * dt));
      const nz = Math.min(DOOR_Z - 0.6, Math.max(STEPS_Z, p.z + d.y * SPEED * dt));
      if (!blocked(nx, nz)) { p.x = nx; p.z = nz; } else if (!blocked(nx, p.z)) p.x = nx; else if (!blocked(p.x, nz)) p.z = nz;
      const want = Math.atan2(d.x, d.y);
      let diff = want - heading;
      diff = Math.atan2(Math.sin(diff), Math.cos(diff));
      heading += diff * Math.min(1, dt * 12);
      player.rotation.y = heading;
      if (p.z > DOOR_Z - 1.4 && Math.abs(p.x) < 3 && performance.now() - entered > 1200) exit();
    }
    if (moving !== state.moving) {
      state.moving = moving;
      state.anim = moving ? 'Running_A' : 'Idle_A';
      saint.play(state.anim);
    }
    saint.update(dt);
    const want = player.position.clone().add(camOff);
    want.x = Math.max(-HALF_W + 2, Math.min(HALF_W - 2, want.x));
    want.z = Math.min(DOOR_Z - 0.3, want.z);
    camera.position.lerp(want, Math.min(1, dt * 4));
    look.set(player.position.x, 3.4, player.position.z - 6);
    camera.lookAt(look);
    built.dust.rotation.y += dt * 0.004;
    renderer.render(scene, camera);
  };
  renderer.setAnimationLoop(tick);

  async function exit() {
    if (leaving) return;
    leaving = true;
    clearKeys();
    const white = document.createElement('div');
    white.className = 'church-fade';
    document.body.append(white);
    await new Promise((r) => setTimeout(r, 450));
    renderer.setAnimationLoop(null);
    window.removeEventListener('resize', resize);
    saint.dispose();
    scene.traverse((o) => { if (o.geometry) o.geometry.dispose(); });
    for (const x of built.disposables) x.dispose?.();
    renderer.dispose();
    renderer.forceContextLoss?.();
    root.remove();
    document.body.classList.remove('in-church');
    active = null;
    if (onLeave) onLeave();
    white.classList.add('out');
    setTimeout(() => white.remove(), 600);
  }
  leave.addEventListener('click', exit);
  // Fade the door transition away to reveal the nave.
  if (fx) { fx.classList.add('done'); setTimeout(() => fx.remove(), 700); }
  setTimeout(() => title.classList.add('gone'), 4000);
  return { exit };
}

export const inChurch = () => !!active;
