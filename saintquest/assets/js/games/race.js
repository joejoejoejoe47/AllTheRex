// Light-Orb Race: 45 seconds down the golden path against three rival saints.
import { skyAndLights, clouds, islands, makeOrb, bursts, glowTexture } from './common.js';

const LANES = [-2.6, 0, 2.6];
const DURATION = 45;

export async function race(ctx) {
  const { THREE, scene, camera, player, stats, rng, el, hud, center } = ctx;
  skyAndLights(THREE, scene, { fogNear: 30, fogFar: 120 });
  clouds(THREE, scene, rng, { count: 24, radius: 80, y: [14, 30] });
  islands(THREE, scene, rng, 8, 70);
  const speed = 12 + stats.hope * 0.3;
  const length = speed * DURATION + 60;
  // Path, grass and flowers along the way.
  const path = new THREE.Mesh(new THREE.PlaneGeometry(9, length + 80), new THREE.MeshStandardMaterial({ color: 0xd9c6a0, roughness: 0.95 }));
  path.rotation.x = -Math.PI / 2;
  path.position.z = -length / 2 + 20;
  scene.add(path);
  const grass = new THREE.Mesh(new THREE.PlaneGeometry(80, length + 80), new THREE.MeshStandardMaterial({ color: 0x7cc35a, roughness: 1 }));
  grass.rotation.x = -Math.PI / 2;
  grass.position.set(0, -0.02, path.position.z);
  scene.add(grass);
  const stripeMat = new THREE.MeshBasicMaterial({ color: 0xfff0b0, transparent: true, opacity: 0.5 });
  for (let z = 10; z > -length; z -= 6) {
    for (const x of [-1.3, 1.3]) {
      const s = new THREE.Mesh(new THREE.PlaneGeometry(0.12, 2.2), stripeMat);
      s.rotation.x = -Math.PI / 2;
      s.position.set(x, 0.01, z);
      scene.add(s);
    }
  }
  const flowerColors = [0xb388ff, 0xffd54f, 0xff8a80, 0xffffff, 0x80d8ff];
  const flowerGeo = new THREE.SphereGeometry(0.22, 6, 5);
  const flowerMats = flowerColors.map((c) => new THREE.MeshStandardMaterial({ color: c }));
  const count = Math.floor(length / 2);
  for (let k = 0; k < flowerMats.length; k++) {
    const inst = new THREE.InstancedMesh(flowerGeo, flowerMats[k], count);
    const m = new THREE.Matrix4();
    for (let i = 0; i < count; i++) {
      const side = rng() < 0.5 ? -1 : 1;
      m.makeTranslation(side * (5 + rng() * 10), 0.2, 20 - rng() * (length + 40));
      inst.setMatrixAt(i, m);
    }
    scene.add(inst);
  }
  const archMat = new THREE.MeshStandardMaterial({ color: 0xf3c64d, metalness: 0.5, roughness: 0.35 });
  for (let z = -40; z > -length; z -= 60) {
    const arch = new THREE.Mesh(new THREE.TorusGeometry(5.2, 0.25, 8, 24, Math.PI), archMat);
    arch.position.set(0, 0, z);
    scene.add(arch);
  }
  // Orbs and shadow puddles, placed from the server's seed.
  const orbs = [];
  const puddles = [];
  const gap = (speed * DURATION) / 54; // at most ~54 orbs, under the server's cap of 60
  for (let z = -18; z > -(speed * DURATION); z -= gap) {
    const lane = Math.floor(rng() * 3);
    const o = makeOrb(THREE, { size: 0.42 });
    o.position.set(LANES[lane], 1.1, z);
    scene.add(o);
    orbs.push({ mesh: o, lane, z, taken: false });
    if (rng() < 0.55) {
      let pl = Math.floor(rng() * 3);
      if (pl === lane) pl = (pl + 1 + Math.floor(rng() * 2)) % 3;
      const p = new THREE.Mesh(new THREE.CircleGeometry(1.1, 20), new THREE.MeshBasicMaterial({ color: 0x3a1f5e, transparent: true, opacity: 0.85 }));
      p.rotation.x = -Math.PI / 2;
      p.position.set(LANES[pl], 0.02, z - gap / 2);
      scene.add(p);
      puddles.push({ mesh: p, lane: pl, z: z - gap / 2, hit: false });
    }
  }
  // Rival saints race beside the path, collecting at the pace the server set.
  const rivals = [];
  const xs = [-6.5, 6.5, -9.5];
  for (let i = 0; i < ctx.rivals.length; i++) {
    const r = ctx.rivals[i];
    const d = ctx.defOf(r.key);
    const s = await ctx.createSaint(d);
    s.object.position.set(xs[i], 0, 2 + i);
    s.object.rotation.y = Math.PI;
    s.play('Running_A');
    scene.add(s.object);
    rivals.push({ s, name: d.name, final: r.orbs, x: xs[i], shown: 0, offset: (rng() - 0.5) * 6 });
  }
  player.object.rotation.y = Math.PI;
  player.play('Running_A');
  const fx = bursts(THREE, scene);

  const scoreEl = el('div.big', { text: '0' });
  const timeEl = el('div', { style: { fontWeight: 800 }, text: `${DURATION}s` });
  hud.append(el('div.panel.cream', {}, el('div', { style: { fontWeight: 800, fontSize: '14px' }, text: 'Your light orbs' }), scoreEl));
  const board = el('div.panel.cream', { style: { fontSize: '13px', fontWeight: 800, minWidth: '170px' } });
  hud.append(board);
  center.append(el('div.panel.cream', { style: { padding: '4px 14px' } }, timeEl));

  let lane = 1, x = 0, z = 0, t = 0, score = 0, slow = 0, over = false;
  const move = (d) => { if (!over) lane = Math.max(0, Math.min(2, lane + d)); };
  ctx.onKeyDown = (code) => {
    if (code === 'KeyA' || code === 'ArrowLeft') move(-1);
    if (code === 'KeyD' || code === 'ArrowRight') move(1);
  };
  const cv = ctx.renderer.domElement;
  let sx = null;
  cv.addEventListener('pointerdown', (e) => { sx = e.clientX; });
  cv.addEventListener('pointerup', (e) => {
    if (sx === null) return;
    const dx = e.clientX - sx;
    if (Math.abs(dx) > 30) move(dx > 0 ? 1 : -1);
    else move(e.clientX < innerWidth / 2 ? -1 : 1);
    sx = null;
  });
  ctx.button('◀', 'blue', () => move(-1));
  ctx.button('▶', 'blue', () => move(1));

  const drawBoard = () => {
    const rows = [{ name: 'You', n: score, me: true }, ...rivals.map((r) => ({ name: r.name.replace('St. ', ''), n: r.shown }))].sort((a, b) => b.n - a.n);
    board.replaceChildren(el('div', { text: '🏁 Race board' }), ...rows.map((r, i) => el('div', { style: { display: 'flex', justifyContent: 'space-between', gap: '8px', color: r.me ? '#1f6aa8' : '' } }, el('span', { text: `${i + 1}. ${r.name}` }), el('span', { text: String(r.n) }))));
  };
  drawBoard();

  return {
    score: () => score,
    summary: (r) => `You collected ${r.score} light orbs. Rivals: ${(r.rivals || ctx.rivals.map((x) => x.orbs)).join(', ')}.`,
    debug: () => { const n = orbs.find((o) => !o.taken && o.z < z); return { score, lane, z, t, next: n ? { lane: n.lane, z: n.z } : null }; },
    update(dt) {
      if (over) { fx.update(dt); return; }
      t += dt;
      slow = Math.max(0, slow - dt);
      const v = speed * (slow > 0 ? 0.5 : 1);
      z -= v * dt;
      x += (LANES[lane] - x) * Math.min(1, dt * 12);
      player.object.position.set(x, 0, z);
      camera.position.set(x * 0.4, 6.5, z + 10.5);
      camera.lookAt(x * 0.6, 1.2, z - 8);
      for (const o of orbs) {
        if (o.taken) continue;
        o.mesh.rotation.y += dt * 3;
        o.mesh.position.y = 1.1 + Math.sin(t * 4 + o.z) * 0.15;
        if (Math.abs(o.z - z) < 1.1 && Math.abs(LANES[o.lane] - x) < 1.3) {
          o.taken = true;
          score++;
          fx.spawn(o.mesh.position.clone(), { color: 0xfff2a0, n: 10 });
          scene.remove(o.mesh);
          scoreEl.textContent = String(score);
        }
      }
      for (const p of puddles) {
        if (!p.hit && Math.abs(p.z - z) < 1.0 && Math.abs(LANES[p.lane] - x) < 1.2) {
          p.hit = true;
          slow = 1.0;
          fx.spawn(new THREE.Vector3(x, 0.5, z), { color: 0x8a4dff, n: 10 });
        }
      }
      for (const r of rivals) {
        const frac = Math.min(1, t / DURATION);
        r.shown = Math.floor(r.final * frac);
        r.s.object.position.set(r.x, 0, z + r.offset * Math.sin(t * 0.4 + r.x));
        r.s.update(dt);
      }
      const left = Math.max(0, DURATION - t);
      timeEl.textContent = `${Math.ceil(left)}s left`;
      if (Math.floor(t * 4) !== Math.floor((t - dt) * 4)) drawBoard();
      fx.update(dt);
      if (left <= 0) {
        over = true;
        player.play('Idle_A');
        for (const r of rivals) { r.shown = r.final; r.s.play('Idle_A'); }
        drawBoard();
        const best = Math.max(...rivals.map((r) => r.final));
        center.replaceChildren(el('div.panel.cream', { style: { padding: '6px 14px', fontWeight: 800, fontSize: '20px' } }, score > best ? '🏆 You won the race!' : 'The race is over!'));
        setTimeout(() => ctx.end(score, score > best), 1200);
      }
    },
    dispose() { for (const r of rivals) r.s.dispose(); },
  };
}
