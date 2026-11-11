// Saint 3D models: KayKit Adventurers (CC0, Kay Lousberg) with per-saint robe
// colours and a golden halo. Shared by the home scene, store previews,
// battles and the card-art renderer.
import * as THREE from '../vendor/three-bundle.min.js';

const loader = new THREE.GLTFLoader();
const modelCache = new Map();
const texCache = new Map();
let animPromise = null;
const base = () => (window.SQ_BASE || '') + '/assets/';

function loadGltf(url) {
  if (!modelCache.has(url)) {
    modelCache.set(url, new Promise((res, rej) => loader.load(url, res, undefined, rej)));
  }
  return modelCache.get(url);
}

function loadTexture(url) {
  if (!texCache.has(url)) {
    texCache.set(url, new Promise((res, rej) => new THREE.TextureLoader().load(url, (t) => {
      t.flipY = false;
      t.colorSpace = THREE.SRGBColorSpace;
      t.magFilter = THREE.LinearFilter;
      res(t);
    }, undefined, rej)));
  }
  return texCache.get(url);
}

export function loadAnimations() {
  if (!animPromise) {
    animPromise = Promise.all([
      loadGltf(base() + 'models/anim-general.glb'),
      loadGltf(base() + 'models/anim-move.glb'),
    ]).then(([a, b]) => {
      const clips = {};
      for (const c of [...a.animations, ...b.animations]) clips[c.name] = c;
      return clips;
    }).catch(() => ({}));
  }
  return animPromise;
}

export function makeHalo(radius = 0.32) {
  const g = new THREE.Group();
  const ring = new THREE.Mesh(
    new THREE.TorusGeometry(radius, 0.045, 12, 48),
    new THREE.MeshStandardMaterial({ color: 0xffd34d, emissive: 0xffb300, emissiveIntensity: 1.1, metalness: 0.6, roughness: 0.25 })
  );
  ring.rotation.x = Math.PI / 2;
  g.add(ring);
  const glow = new THREE.Mesh(
    new THREE.RingGeometry(radius * 0.6, radius * 1.55, 48),
    new THREE.MeshBasicMaterial({ color: 0xfff1a8, transparent: true, opacity: 0.35, side: THREE.DoubleSide, depthWrite: false, blending: THREE.AdditiveBlending })
  );
  glow.rotation.x = Math.PI / 2;
  g.add(glow);
  g.userData.spin = ring;
  return g;
}

/**
 * Build a saint. def = catalog entry { model, tex, halo }.
 * Returns { object, mixer, play(name, opts), update(dt), dispose() }.
 */
export async function createSaint(def, { halo = true, animate = true } = {}) {
  const gltf = await loadGltf(base() + 'models/' + def.model + '.glb');
  const object = THREE.SkeletonUtils.clone(gltf.scene);
  let tex = null;
  if (def.tex) {
    try { tex = await loadTexture(base() + 'models/tex/' + def.tex); } catch { tex = null; }
  }
  const hidden = new Set(def.hide || []);
  object.traverse((o) => {
    if (hidden.has(o.name)) o.visible = false;
    if (o.isMesh) {
      o.castShadow = true;
      o.frustumCulled = false;
      const m = o.material.clone();
      if (tex) m.map = tex;
      m.metalness = 0;
      m.roughness = 0.62;
      o.material = m;
    }
  });
  let headBone = null;
  object.traverse((o) => { if (!headBone && o.isBone && o.name === 'head') headBone = o; });
  let haloObj = null;
  if (halo) {
    haloObj = makeHalo(def.haloRadius || 0.36);
    const lift = def.haloLift ?? 1.05;
    if (headBone) {
      haloObj.position.set(0, lift, 0);
      headBone.add(haloObj);
    } else {
      haloObj.position.set(0, 2.6, 0);
      object.add(haloObj);
    }
  }
  const mixer = new THREE.AnimationMixer(object);
  const clips = animate ? await loadAnimations() : {};
  let current = null;
  const api = {
    object,
    halo: haloObj,
    mixer,
    clips,
    play(name, { loop = true, fade = 0.25, once = false, speed = 1 } = {}) {
      const clip = clips[name];
      if (!clip) return null;
      const action = mixer.clipAction(clip);
      action.reset();
      action.setEffectiveTimeScale(speed);
      action.setLoop(once ? THREE.LoopOnce : (loop ? THREE.LoopRepeat : THREE.LoopOnce), Infinity);
      action.clampWhenFinished = once;
      if (current && current !== action) current.crossFadeTo(action, fade, false);
      action.play();
      current = action;
      return action;
    },
    update(dt) {
      mixer.update(dt);
      if (haloObj) {
        const t = performance.now() / 1000;
        haloObj.userData.spin.rotation.z = t * 0.6;
      }
    },
    dispose() {
      mixer.stopAllAction();
      object.traverse((o) => { if (o.isMesh) o.material.dispose(); });
    },
  };
  if (animate) api.play('Idle_A');
  return api;
}

export { THREE };
