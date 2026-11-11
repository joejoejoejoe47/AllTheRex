// Chapel Defense: guard the village chapel from drifting shadows for 60 s.
import { skyAndLights, clouds, islands, makeOrb, bursts, glowTexture } from './common.js';

const DURATION = 60;

export async function defense(ctx) {
  const { THREE, scene, camera, player, stats, rng, el, hud, center } = ctx;
  skyAndLights(THREE, scene, { top: '#6fb3e6', bottom: '#ffe1b0', fogNear: 35, fogFar: 110 });
  clouds(THREE, scene, rng, { count: 14, radius: 60, y: [16, 28] });
  islands(THREE, scene, rng, 6, 55);
  const R = 17;
  const ground = new THREE.Mesh(new THREE.CircleGeometry(R + 14, 48), new THREE.MeshStandardMaterial({ color: 0x86c95f, roughness: 1 }));
  ground.rotation.x = -Math.PI / 2;
  scene.add(ground);
  const pathRing = new THREE.Mesh(new THREE.RingGeometry(2.6, 4.2, 40), new THREE.MeshStandardMaterial({ color: 0xd9c6a0, roughness: 1 }));
  pathRing.rotation.x = -Math.PI / 2;
  pathRing.position.y = 0.01;
  scene.add(pathRing);
  // The chapel (built from simple shapes in the style of the village).
  const chapel = new THREE.Group();
  const wallMat = new THREE.MeshStandardMaterial({ color: 0xf4e7cc, roughness: 0.8 });
  const roofMat = new THREE.MeshStandardMaterial({ color: 0x3d63b8, roughness: 0.55 });
  const gold = new THREE.MeshStandardMaterial({ color: 0xf3c64d, metalness: 0.6, roughness: 0.3, emissive: 0x8a5a00, emissiveIntensity: 0.3 });
  const body = new THREE.Mesh(new THREE.BoxGeometry(3, 2.6, 4), wallMat);
  body.position.y = 1.3;
  const roof = new THREE.Mesh(new THREE.ConeGeometry(2.9, 2.0, 4), roofMat);
  roof.rotation.y = Math.PI / 4;
  roof.scale.set(1, 1, 1.35);
  roof.position.y = 3.6;
  const tower = new THREE.Mesh(new THREE.BoxGeometry(1.2, 2.2, 1.2), wallMat);
  tower.position.set(0, 3.6, 1.6);
  const spire = new THREE.Mesh(new THREE.ConeGeometry(0.95, 1.8, 4), roofMat);
  spire.rotation.y = Math.PI / 4;
  spire.position.set(0, 5.6, 1.6);
  const crossV = new THREE.Mesh(new THREE.BoxGeometry(0.14, 0.9, 0.14), gold);
  crossV.position.set(0, 6.9, 1.6);
  const crossH = new THREE.Mesh(new THREE.BoxGeometry(0.55, 0.14, 0.14), gold);
  crossH.position.set(0, 7.05, 1.6);
  const door = new THREE.Mesh(new THREE.BoxGeometry(0.9, 1.4, 0.05), new THREE.MeshStandardMaterial({ color: 0x8a5a2b }));
  door.position.set(0, 0.7, 2.03);
  const win = new THREE.Mesh(new THREE.CircleGeometry(0.42, 20), new THREE.MeshBasicMaterial({ color: 0xffe08a }));
  win.position.set(0, 2.0, 2.03);
  chapel.add(body, roof, tower, spire, crossV, crossH, door, win);
  chapel.rotation.y = Math.PI / 6;
  scene.add(chapel);
  const chapelGlow = new THREE.PointLight(0xffe08a, 20, 12);
  chapelGlow.position.set(0, 3, 0);
  scene.add(chapelGlow);
  player.object.position.set(2.6, 0, 3.0);
  camera.position.set(0, 21, 17);
  camera.lookAt(0, 0, 1);
  const fx = bursts(THREE, scene);
  const shadowTex = glowTexture(THREE, '120,60,220');

  const scoreEl = el('div.big', { text: '0' });
  const lightEl = el('div', { style: { fontWeight: 800 } });
  const timeEl = el('div', { style: { fontWeight: 800 } });
  hud.append(el('div.panel.cream', {}, el('div', { style: { fontWeight: 800, fontSize: '14px' }, text: 'Shadows turned to light' }), scoreEl),
    el('div.panel.cream', {}, lightEl, el('div', { style: { fontSize: '12px', fontWeight: 800 }, text: 'Candle light: ', id: 'candle-pts' })));
  center.append(el('div.panel.cream', { style: { padding: '4px 14px' } }, timeEl));

  const shadows = [];
  const candles = [];
  let t = 0, spawnT = 1.2, score = 0, light = 10, candlePts = 3, placing = false, over = false, beamT = 0, cool = 0;
  const beam = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 1, 8, 1, true), new THREE.MeshBasicMaterial({ color: 0xfff2a0, transparent: true, opacity: 0.8, blending: THREE.AdditiveBlending, depthWrite: false }));
  beam.visible = false;
  scene.add(beam);
  const candleBtn = ctx.button('🕯️ Candle (3)', '', () => { if (candlePts >= 3) { placing = !placing; candleBtn.style.outline = placing ? '4px solid #3d97dc' : ''; ctx.help.textContent = placing ? 'Tap the grass to place your candle.' : 'Tap/click a shadow to shine · Space: shine on the nearest'; } });
  const syncHud = () => {
    lightEl.textContent = `⛪ Chapel light: ${'💛'.repeat(Math.max(0, light))}${'🖤'.repeat(Math.max(0, 10 - light))}`;
    candleBtn.textContent = `🕯️ Candle (${candlePts}/3)`;
    candleBtn.disabled = candlePts < 3;
    document.getElementById('candle-pts').textContent = `Candle light: ${candlePts} (3 lights a candle)`;
  };
  syncHud();

  function spawn() {
    const a = rng() * Math.PI * 2;
    const g = new THREE.Group();
    const s = new THREE.Mesh(new THREE.SphereGeometry(0.55, 14, 10), new THREE.MeshStandardMaterial({ color: 0x2b1747, emissive: 0x4a1a8c, emissiveIntensity: 0.7, transparent: true, opacity: 0.92 }));
    const tail = new THREE.Mesh(new THREE.ConeGeometry(0.45, 1.2, 10), s.material);
    tail.rotation.x = Math.PI / 2;
    tail.position.z = -0.7;
    const eyes = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.1, 0.05), new THREE.MeshBasicMaterial({ color: 0xd9c2ff }));
    eyes.position.set(0, 0.12, 0.52);
    const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: shadowTex, transparent: true, depthWrite: false }));
    glow.scale.setScalar(2.6);
    g.add(glow, s, tail, eyes);
    g.position.set(Math.cos(a) * R, 1.1, Math.sin(a) * R);
    g.lookAt(0, 1.1, 0);
    scene.add(g);
    shadows.push({ g, speed: 1.3 + Math.min(1.6, t * 0.022) + rng() * 0.4, wob: rng() * 6, alive: true });
  }

  function purify(sh, fromCandle = null) {
    if (!sh.alive) return;
    sh.alive = false;
    score++;
    if (!fromCandle) candlePts = Math.min(3, candlePts + 1);
    const from = fromCandle ? fromCandle.position.clone().add(new THREE.Vector3(0, 1.4, 0)) : player.object.position.clone().add(new THREE.Vector3(0, 1.4, 0));
    const to = sh.g.position.clone();
    const mid = from.clone().add(to).multiplyScalar(0.5);
    beam.position.copy(mid);
    beam.scale.set(1, from.distanceTo(to), 1);
    beam.lookAt(to);
    beam.rotateX(Math.PI / 2);
    beam.visible = true;
    beamT = 0.15;
    fx.spawn(to, { color: 0xfff6c0, n: 16, speed: 3 });
    scene.remove(sh.g);
    scoreEl.textContent = String(score);
    if (!fromCandle) {
      player.object.rotation.y = Math.atan2(to.x - player.object.position.x, to.z - player.object.position.z);
      player.play('Throw', { once: true, speed: 1.8 });
      setTimeout(() => !over && player.play('Idle_A'), 380);
    }
    syncHud();
  }

  const ray = new THREE.Raycaster();
  const ndc = new THREE.Vector2();
  const groundPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
  ctx.renderer.domElement.addEventListener('pointerdown', (e) => {
    if (over) return;
    const r = ctx.renderer.domElement.getBoundingClientRect();
    if (placing) {
      ndc.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
      ray.setFromCamera(ndc, camera);
      const p = new THREE.Vector3();
      if (ray.ray.intersectPlane(groundPlane, p) && Math.hypot(p.x, p.z) < R - 1 && Math.hypot(p.x, p.z) > 3) placeCandle(p);
      return;
    }
    // Generous tap target: nearest live shadow within 60px on screen.
    let best = null, bd = 60;
    for (const sh of shadows) {
      if (!sh.alive) continue;
      const v = sh.g.position.clone().project(camera);
      const sx = (v.x + 1) / 2 * r.width + r.left, sy = (1 - v.y) / 2 * r.height + r.top;
      const d = Math.hypot(sx - e.clientX, sy - e.clientY);
      if (d < bd) { bd = d; best = sh; }
    }
    if (best) purify(best);
  });
  ctx.onKeyDown = (code) => {
    if (code !== 'Space' || over || cool > 0) return;
    cool = 0.35;
    let best = null, bd = Infinity;
    for (const sh of shadows) { if (sh.alive) { const d = sh.g.position.length(); if (d < bd) { bd = d; best = sh; } } }
    if (best) purify(best);
  };

  function placeCandle(p) {
    placing = false;
    candleBtn.style.outline = '';
    candlePts -= 3;
    const g = new THREE.Group();
    const stick = new THREE.Mesh(new THREE.CylinderGeometry(0.22, 0.26, 1.1, 12), new THREE.MeshStandardMaterial({ color: 0xfffaf0 }));
    stick.position.y = 0.55;
    const flame = makeOrb(THREE, { size: 0.16, color: 0xffd36b, glow: '255,200,90' });
    flame.position.y = 1.3;
    const ring = new THREE.Mesh(new THREE.RingGeometry(4.6, 4.8, 40), new THREE.MeshBasicMaterial({ color: 0xffe08a, transparent: true, opacity: 0.35, side: THREE.DoubleSide }));
    ring.rotation.x = -Math.PI / 2;
    ring.position.y = 0.03;
    g.add(stick, flame, ring);
    g.position.set(p.x, 0, p.z);
    scene.add(g);
    candles.push({ g, cool: 0.5 });
    ctx.help.textContent = 'Tap/click a shadow to shine · Space: shine on the nearest';
    syncHud();
  }

  return {
    score: () => score,
    summary: (r) => `You turned ${r.score} shadows back into light${light > 0 ? ' and kept the chapel shining!' : '.'}`,
    debug: () => ({ score, light, t, shadows: shadows.filter((s) => s.alive).map((s) => s.g.position.toArray()), candles: candles.length, candlePts }),
    update(dt) {
      if (over) { fx.update(dt); return; }
      t += dt;
      cool = Math.max(0, cool - dt);
      spawnT -= dt;
      if (spawnT <= 0) { spawn(); spawnT = Math.max(0.55, 1.6 - t * 0.018) * (0.7 + rng() * 0.6); }
      for (const sh of shadows) {
        if (!sh.alive) continue;
        const p = sh.g.position;
        const d = Math.hypot(p.x, p.z);
        const step = sh.speed * dt;
        p.x -= (p.x / d) * step;
        p.z -= (p.z / d) * step;
        p.y = 1.1 + Math.sin(t * 3 + sh.wob) * 0.25;
        if (d < 2.4) {
          sh.alive = false;
          scene.remove(sh.g);
          light--;
          fx.spawn(p.clone(), { color: 0x8a4dff, n: 12 });
          chapelGlow.intensity = 6 + light * 1.4;
          syncHud();
        }
      }
      for (const c of candles) {
        c.cool -= dt;
        if (c.cool <= 0) {
          let best = null, bd = 4.8;
          for (const sh of shadows) { if (sh.alive) { const d = sh.g.position.distanceTo(c.g.position); if (d < bd) { bd = d; best = sh; } } }
          if (best) { purify(best, c.g); c.cool = 1.4; }
        }
      }
      if (beamT > 0) { beamT -= dt; if (beamT <= 0) beam.visible = false; }
      const left = Math.max(0, DURATION - t);
      timeEl.textContent = `Keep the chapel shining: ${Math.ceil(left)}s`;
      fx.update(dt);
      if (light <= 0 || left <= 0) {
        over = true;
        const won = light > 0;
        center.replaceChildren(el('div.panel.cream', { style: { padding: '6px 14px', fontWeight: 800, fontSize: '20px' } }, won ? '⛪ The chapel is safe!' : 'The shadows reached the chapel. Try again!'));
        if (won) player.play('Interact', { once: true });
        setTimeout(() => ctx.end(score, won), 1200);
      }
    },
  };
}
