// Shared scenery and effects for the Holy Battle mini-games.
export function skyAndLights(THREE, scene, { top = '#5fb7f0', bottom = '#fff1c9', fog = 0xffe9c4, fogNear = 40, fogFar = 140 } = {}) {
  const c = document.createElement('canvas');
  c.width = 2; c.height = 256;
  const x = c.getContext('2d');
  const g = x.createLinearGradient(0, 0, 0, 256);
  g.addColorStop(0, top);
  g.addColorStop(0.65, bottom);
  g.addColorStop(1, '#ffd98a');
  x.fillStyle = g;
  x.fillRect(0, 0, 2, 256);
  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  scene.background = tex;
  scene.fog = new THREE.Fog(fog, fogNear, fogFar);
  scene.add(new THREE.HemisphereLight(0xfff4dc, 0x6f8f4e, 1.4));
  const sun = new THREE.DirectionalLight(0xffe6b0, 2.4);
  sun.position.set(20, 30, 10);
  scene.add(sun);
  return sun;
}

export function clouds(THREE, scene, rng, { count = 14, radius = 70, y = [18, 32] } = {}) {
  const mat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 1, emissive: 0xfff3e0, emissiveIntensity: 0.25 });
  const geo = new THREE.SphereGeometry(1, 12, 10);
  for (let i = 0; i < count; i++) {
    const g = new THREE.Group();
    const n = 3 + Math.floor(rng() * 3);
    for (let j = 0; j < n; j++) {
      const m = new THREE.Mesh(geo, mat);
      m.scale.setScalar(2 + rng() * 2.5);
      m.position.set(j * 2.6 - n, rng() * 1.2, rng() * 1.5);
      g.add(m);
    }
    const a = rng() * Math.PI * 2;
    g.position.set(Math.cos(a) * radius * (0.7 + rng() * 0.4), y[0] + rng() * (y[1] - y[0]), Math.sin(a) * radius * (0.7 + rng() * 0.4));
    scene.add(g);
  }
}

/** Floating sky islands like the ones in the village picture. */
export function islands(THREE, scene, rng, count = 6, radius = 60) {
  const grass = new THREE.MeshStandardMaterial({ color: 0x7cc35a, roughness: 0.9 });
  const rock = new THREE.MeshStandardMaterial({ color: 0xa48b6b, roughness: 1 });
  const roof = new THREE.MeshStandardMaterial({ color: 0x3d63b8, roughness: 0.6 });
  const wall = new THREE.MeshStandardMaterial({ color: 0xf1e3c4, roughness: 0.8 });
  for (let i = 0; i < count; i++) {
    const g = new THREE.Group();
    const top = new THREE.Mesh(new THREE.CylinderGeometry(3, 2.6, 0.8, 10), grass);
    const bottom = new THREE.Mesh(new THREE.ConeGeometry(2.6, 4.5, 10), rock);
    bottom.rotation.x = Math.PI;
    bottom.position.y = -2.6;
    g.add(top, bottom);
    const tower = new THREE.Mesh(new THREE.CylinderGeometry(0.6, 0.7, 2.4, 8), wall);
    tower.position.set(0.4, 1.6, 0);
    const cap = new THREE.Mesh(new THREE.ConeGeometry(0.85, 1.4, 8), roof);
    cap.position.set(0.4, 3.5, 0);
    g.add(tower, cap);
    const a = (i / count) * Math.PI * 2 + rng() * 0.5;
    g.position.set(Math.cos(a) * radius, 8 + rng() * 16, Math.sin(a) * radius);
    g.scale.setScalar(1 + rng() * 1.2);
    g.userData.bob = rng() * 6;
    scene.add(g);
  }
}

export function glowTexture(THREE, color = '255,240,170') {
  const c = document.createElement('canvas');
  c.width = c.height = 64;
  const x = c.getContext('2d');
  const g = x.createRadialGradient(32, 32, 0, 32, 32, 32);
  g.addColorStop(0, `rgba(${color},1)`);
  g.addColorStop(0.35, `rgba(${color},0.6)`);
  g.addColorStop(1, `rgba(${color},0)`);
  x.fillStyle = g;
  x.fillRect(0, 0, 64, 64);
  return new THREE.CanvasTexture(c);
}

/** Orb: bright core + additive glow sprite. */
export function makeOrb(THREE, { color = 0xfff2a0, glow = '255,240,170', size = 0.35 } = {}) {
  const g = new THREE.Group();
  const core = new THREE.Mesh(new THREE.SphereGeometry(size, 16, 12), new THREE.MeshBasicMaterial({ color }));
  const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTexture(THREE, glow), transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
  halo.scale.setScalar(size * 6);
  g.add(core, halo);
  return g;
}

/** Simple particle bursts (sparkles), pooled. */
export function bursts(THREE, scene) {
  const tex = glowTexture(THREE);
  const live = [];
  return {
    spawn(pos, { color = 0xffffff, n = 14, speed = 4, life = 0.8, size = 0.5 } = {}) {
      for (let i = 0; i < n; i++) {
        const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, color, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
        s.position.copy(pos);
        s.scale.setScalar(size);
        const a = Math.random() * Math.PI * 2, b = Math.random() * Math.PI - Math.PI / 2;
        s.userData = { v: new THREE.Vector3(Math.cos(a) * Math.cos(b), Math.sin(b) + 0.6, Math.sin(a) * Math.cos(b)).multiplyScalar(speed * (0.4 + Math.random() * 0.8)), life, max: life };
        scene.add(s);
        live.push(s);
      }
    },
    update(dt) {
      for (let i = live.length - 1; i >= 0; i--) {
        const s = live[i];
        s.userData.life -= dt;
        s.position.addScaledVector(s.userData.v, dt);
        s.userData.v.y -= 3 * dt;
        s.material.opacity = Math.max(0, s.userData.life / s.userData.max);
        if (s.userData.life <= 0) { scene.remove(s); s.material.dispose(); live.splice(i, 1); }
      }
    },
  };
}

/** Make a "shadow" version of a saint: dark purple, softly glowing, no halo. */
export function shadowify(THREE, obj) {
  obj.traverse((o) => {
    if (o.isMesh) {
      o.material = new THREE.MeshStandardMaterial({ color: 0x2b1747, emissive: 0x4a1a8c, emissiveIntensity: 0.55, roughness: 0.5, transparent: true, opacity: 0.95 });
    }
  });
}

export function hudBar(el, label, cls) {
  const fill = el('i', { style: { width: '100%' } });
  const node = el('div.panel.cream', {}, el('div', { style: { fontWeight: 800, fontSize: '14px' }, text: label }), el('div.hud-bar.' + cls, {}, fill));
  return { node, set(v) { fill.style.width = Math.max(0, Math.min(100, v)) + '%'; } };
}
