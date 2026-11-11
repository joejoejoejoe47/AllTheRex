// Arena Duel: your saint vs a shadow on a floating sky arena.
import { skyAndLights, clouds, islands, makeOrb, bursts, shadowify, hudBar } from './common.js';

export async function duel(ctx) {
  const { THREE, scene, camera, player, stats, rng, el, hud, center } = ctx;
  skyAndLights(THREE, scene, { fogNear: 50, fogFar: 160 });
  clouds(THREE, scene, rng, { count: 16, radius: 60, y: [-10, 20] });
  islands(THREE, scene, rng, 7, 55);
  const R = 9;
  const arena = new THREE.Group();
  const floor = new THREE.Mesh(new THREE.CylinderGeometry(R, R * 0.92, 1.2, 48), new THREE.MeshStandardMaterial({ color: 0xe9dcc0, roughness: 0.85 }));
  floor.position.y = -0.6;
  const inlay = new THREE.Mesh(new THREE.RingGeometry(2.2, 2.6, 48), new THREE.MeshStandardMaterial({ color: 0xf3c64d, emissive: 0xb07a10, emissiveIntensity: 0.3, side: THREE.DoubleSide }));
  inlay.rotation.x = -Math.PI / 2;
  inlay.position.y = 0.01;
  const rim = new THREE.Mesh(new THREE.TorusGeometry(R, 0.25, 10, 64), new THREE.MeshStandardMaterial({ color: 0xf3c64d, metalness: 0.6, roughness: 0.3 }));
  rim.rotation.x = Math.PI / 2;
  const under = new THREE.Mesh(new THREE.ConeGeometry(R * 0.9, 9, 24), new THREE.MeshStandardMaterial({ color: 0xa48b6b, roughness: 1 }));
  under.rotation.x = Math.PI;
  under.position.y = -5.7;
  arena.add(floor, inlay, rim, under);
  const pillarMat = new THREE.MeshStandardMaterial({ color: 0xfff7e6, roughness: 0.6 });
  for (let i = 0; i < 8; i++) {
    const a = (i / 8) * Math.PI * 2;
    const p = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.45, 3.2, 12), pillarMat);
    p.position.set(Math.cos(a) * (R - 0.6), 1.6, Math.sin(a) * (R - 0.6));
    const orb = makeOrb(THREE, { size: 0.28 });
    orb.position.set(p.position.x, 3.5, p.position.z);
    arena.add(p, orb);
  }
  scene.add(arena);

  // The opponent is a shadow-version of another saint.
  const others = ctx.catalog.filter((s) => s.key !== ctx.def.key);
  const oppDef = others[Math.floor(rng() * others.length)];
  const opp = await ctx.createSaint(oppDef, { halo: false });
  shadowify(THREE, opp.object);
  scene.add(opp.object);
  const aura = new THREE.PointLight(0x8a4dff, 6, 6);
  aura.position.y = 1.5;
  opp.object.add(aura);
  const oppShield = new THREE.Mesh(new THREE.SphereGeometry(1.6, 20, 14), new THREE.MeshBasicMaterial({ color: 0x6a3dd1, transparent: true, opacity: 0.25, depthWrite: false }));
  oppShield.position.y = 1.2;
  oppShield.visible = false;
  opp.object.add(oppShield);
  const myShield = new THREE.Mesh(new THREE.SphereGeometry(1.6, 20, 14), new THREE.MeshBasicMaterial({ color: 0xfff1a0, transparent: true, opacity: 0.3, depthWrite: false, blending: THREE.AdditiveBlending }));
  myShield.position.y = 1.2;
  myShield.visible = false;
  player.object.add(myShield);

  player.object.position.set(0, 0, 5);
  opp.object.position.set(0, 0, -5);
  camera.position.set(0, 13, 15.5);
  camera.lookAt(0, 0, 0);
  const fx = bursts(THREE, scene);

  const meLight = hudBar(el, `${ctx.def.name} — light`, 'light');
  const darkBar = hudBar(el, 'Shadow — darkness', 'dark');
  hud.append(meLight.node, darkBar.node);
  center.append(el('div.panel.cream', { style: { padding: '4px 12px', fontWeight: 800 } }, 'Turn the shadow back into light!'));
  const joy = ctx.joystick();
  const shieldBtn = ctx.button('🛡️ Shield', 'blue', () => raiseShield());
  ctx.button('✨ Shine', '', () => shine());
  ctx.onKeyDown = (code) => { if (code === 'Space') shine(); if (code === 'ShiftLeft' || code === 'ShiftRight') raiseShield(); };
  ctx.renderer.domElement.addEventListener('pointerdown', (e) => { if (e.target === ctx.renderer.domElement) shine(); });

  let myHp = 100, oppHp = 100, cool = 0, shieldT = 0, shieldCool = 0, oppCool = 2.0, oppShieldT = 0, oppShieldCool = 4, over = false, t = 0;
  const orbs = [];
  const speed = 4 + stats.hope * 0.35;
  const dmg = 6 + stats.faith * 0.55;
  const hurt = 15 - stats.charity;
  let oppTarget = new THREE.Vector3(0, 0, -5);
  let walking = false;

  function shine() {
    if (over || cool > 0) return;
    cool = 0.5;
    player.play('Throw', { once: true, speed: 1.6 });
    setTimeout(() => { if (!over) player.play(walking ? 'Running_A' : 'Idle_A'); }, 450);
    const o = makeOrb(THREE, { size: 0.32 });
    o.position.copy(player.object.position).add(new THREE.Vector3(0, 1.4, 0));
    const dir = opp.object.position.clone().add(new THREE.Vector3(0, 1.2, 0)).sub(o.position).normalize();
    orbs.push({ mesh: o, v: dir.multiplyScalar(15), mine: true, life: 2 });
    scene.add(o);
  }
  function raiseShield() {
    if (over || shieldCool > 0) return;
    shieldT = 1.3;
    shieldCool = 4.5 - stats.hope * 0.15;
    myShield.visible = true;
    shieldBtn.disabled = true;
  }
  function oppThrow() {
    const o = makeOrb(THREE, { color: 0x5b2a9e, glow: '140,80,255', size: 0.36 });
    o.position.copy(opp.object.position).add(new THREE.Vector3(0, 1.4, 0));
    const lead = player.object.position.clone();
    const dir = lead.add(new THREE.Vector3(0, 1.1, 0)).sub(o.position).normalize();
    orbs.push({ mesh: o, v: dir.multiplyScalar(9 + (100 - oppHp) * 0.03), mine: false, life: 3 });
    scene.add(o);
    opp.play('Throw', { once: true, speed: 1.4 });
    setTimeout(() => opp.play('Walking_A'), 500);
  }
  opp.play('Walking_A');

  function end(won) {
    if (over) return;
    over = true;
    if (won) {
      fx.spawn(opp.object.position.clone().add(new THREE.Vector3(0, 1.2, 0)), { n: 40, speed: 6, life: 1.4, color: 0xfff6c0, size: 0.8 });
      opp.object.visible = false;
      center.replaceChildren(el('div.panel.cream', { style: { padding: '6px 14px', fontWeight: 800, fontSize: '20px' } }, '🕊️ The shadow turned back into light!'));
    } else {
      center.replaceChildren(el('div.panel.cream', { style: { padding: '6px 14px', fontWeight: 800, fontSize: '20px' } }, 'Your saint needs a rest. Try again!'));
    }
    joy.remove();
    setTimeout(() => ctx.end(Math.max(0, Math.round(myHp)), won), 1400);
  }

  return {
    score: () => Math.max(0, Math.round(myHp)),
    summary: (r) => (r.won ? `You cleared the shadow with ${r.score}% of your light left.` : 'The shadow was too strong this time.'),
    debug: () => ({ myHp, oppHp, orbs: orbs.length, player: player.object.position.toArray(), opp: opp.object.position.toArray(), over }),
    update(dt) {
      t += dt;
      cool = Math.max(0, cool - dt);
      shieldCool = Math.max(0, shieldCool - dt);
      if (shieldCool === 0) shieldBtn.disabled = false;
      if (shieldT > 0) { shieldT -= dt; if (shieldT <= 0) myShield.visible = false; }
      if (over) { opp.update(dt); fx.update(dt); return; }
      // player movement (camera-relative: up = toward the shadow side)
      const a = ctx.axis();
      const moving = Math.hypot(a.x, a.y) > 0.1;
      if (moving !== walking) { walking = moving; player.play(moving ? 'Running_A' : 'Idle_A'); }
      const p = player.object.position;
      p.x += a.x * speed * dt;
      p.z += a.y * speed * dt;
      const l = Math.hypot(p.x, p.z);
      if (l > R - 1.2) { p.x *= (R - 1.2) / l; p.z *= (R - 1.2) / l; }
      player.object.rotation.y = Math.atan2(opp.object.position.x - p.x, opp.object.position.z - p.z);
      // opponent AI: circle around, keep distance, throw, sometimes shield
      const o = opp.object.position;
      if (o.distanceTo(oppTarget) < 0.5 || rng() < 0.004) {
        const ang = Math.atan2(o.z - p.z, o.x - p.x) + (rng() - 0.5) * 1.8;
        const dist = 5 + rng() * 2.5;
        oppTarget.set(p.x + Math.cos(ang) * dist, 0, p.z + Math.sin(ang) * dist);
        const tl = Math.hypot(oppTarget.x, oppTarget.z);
        if (tl > R - 1.5) oppTarget.multiplyScalar((R - 1.5) / tl);
      }
      const step = oppTarget.clone().sub(o);
      if (step.length() > 0.05) o.addScaledVector(step.normalize(), (3 + (100 - oppHp) * 0.02) * dt);
      opp.object.rotation.y = Math.atan2(p.x - o.x, p.z - o.z);
      oppCool -= dt;
      if (oppCool <= 0) { oppThrow(); oppCool = 1.2 + rng() * 0.9 - (100 - oppHp) * 0.004; }
      oppShieldCool -= dt;
      if (oppShieldCool <= 0) { oppShieldT = 1.1; oppShieldCool = 3.5 + rng() * 2.5; oppShield.visible = true; }
      if (oppShieldT > 0) { oppShieldT -= dt; if (oppShieldT <= 0) oppShield.visible = false; }
      // orbs
      for (let i = orbs.length - 1; i >= 0; i--) {
        const b = orbs[i];
        b.mesh.position.addScaledVector(b.v, dt);
        b.life -= dt;
        let hit = false;
        if (b.mine) {
          if (b.mesh.position.distanceTo(o.clone().add(new THREE.Vector3(0, 1.2, 0))) < 1.2) {
            hit = true;
            if (oppShieldT > 0) fx.spawn(b.mesh.position, { color: 0xb08cff, n: 8 });
            else {
              oppHp -= dmg;
              fx.spawn(b.mesh.position, { color: 0xfff2a0, n: 16 });
              opp.play('Hit_A', { once: true });
              setTimeout(() => !over && opp.play('Walking_A'), 400);
            }
          }
        } else if (b.mesh.position.distanceTo(p.clone().add(new THREE.Vector3(0, 1.1, 0))) < 1.0) {
          hit = true;
          if (shieldT > 0) fx.spawn(b.mesh.position, { color: 0xfff2a0, n: 10 });
          else {
            myHp -= hurt;
            fx.spawn(b.mesh.position, { color: 0x8a4dff, n: 14 });
            player.play('Hit_A', { once: true });
            setTimeout(() => !over && player.play(walking ? 'Running_A' : 'Idle_A'), 400);
          }
        }
        if (hit || b.life <= 0) { scene.remove(b.mesh); orbs.splice(i, 1); }
      }
      meLight.set(myHp);
      darkBar.set(oppHp);
      opp.update(dt);
      fx.update(dt);
      if (oppHp <= 0) end(true);
      else if (myHp <= 0) end(false);
    },
    dispose() { opp.dispose(); },
  };
}
