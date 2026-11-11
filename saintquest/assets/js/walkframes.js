// The walk-up-the-path "video": a pre-extracted WebP frame sequence drawn to
// a canvas, so it can play forward and backward smoothly (no video seeking).
// Frames load coarse-to-fine so walking works early; neighbouring frames are
// cross-faded for smooth motion between stored frames.

const base = () => (window.SQ_BASE || '') + '/assets/';
let manifest = null, set = null, imgs = [], loadedCount = 0, total = 0, started = false;
const progressFns = [];

export function onProgress(fn) { progressFns.push(fn); }
export const duration = () => (manifest ? manifest.duration : 12);
export const fps = () => (manifest ? manifest.fps : 24);
export const progress = () => (total ? loadedCount / total : 0);
export const setName = () => (set ? set.name : null);

function pickSet(m) {
  const c = navigator.connection || {};
  const small = Math.min(screen.width, screen.height) < 700 && window.matchMedia('(pointer: coarse)').matches;
  const slow = c.saveData || /(^|-)2g|3g/.test(c.effectiveType || '');
  const name = small || slow ? 'sd' : 'hd';
  return { name, ...m.sets[name] };
}

export async function startLoading() {
  if (started) return;
  started = true;
  manifest = await fetch(base() + 'walk/walk.json').then((r) => r.json());
  set = pickSet(manifest);
  total = set.count;
  imgs = new Array(total).fill(null);
  // Coarse to fine: every 16th frame first, then 8th, 4th, 2nd, all.
  const order = [];
  const seen = new Set();
  for (const stride of [16, 8, 4, 2, 1]) for (let i = 0; i < total; i += stride) if (!seen.has(i)) { seen.add(i); order.push(i); }
  if (!seen.has(total - 1)) order.splice(1, 0, total - 1);
  let next = 0;
  const worker = async () => {
    while (next < order.length) {
      const i = order[next++];
      const img = new Image();
      img.decoding = 'async';
      img.src = base() + set.dir + String(i).padStart(3, '0') + '.webp';
      try { await img.decode(); imgs[i] = img; } catch { /* skip a broken frame */ }
      loadedCount++;
      for (const fn of progressFns) fn(progress());
    }
  };
  await Promise.all([worker(), worker(), worker(), worker()]);
}

function nearest(i, dir) {
  for (let k = i; k >= 0 && k < total; k += dir) if (imgs[k]) return k;
  return -1;
}

/** Draw the scene at time t (seconds) into ctx (canvas sized to the set). Returns true if drawn. */
export function draw(ctx, t) {
  if (!set) return false;
  const f = Math.max(0, Math.min(total - 1, (t * manifest.fps) / set.step));
  let a = nearest(Math.floor(f), -1), b = nearest(Math.ceil(f), 1);
  if (a < 0) a = b;
  if (b < 0) b = a;
  if (a < 0) return false;
  const c = ctx.canvas;
  if (c.width !== set.w) { c.width = set.w; c.height = set.h; }
  ctx.globalAlpha = 1;
  ctx.drawImage(imgs[a], 0, 0, set.w, set.h);
  if (b !== a) {
    const mix = (f - a) / (b - a);
    if (mix > 0.01) { ctx.globalAlpha = mix; ctx.drawImage(imgs[b], 0, 0, set.w, set.h); ctx.globalAlpha = 1; }
  }
  return true;
}

/** URL of the stored frame nearest to time t (for the door transition). */
export function frameUrl(t) {
  if (!set) return null;
  const i = Math.max(0, Math.min(total - 1, Math.round((t * manifest.fps) / set.step)));
  return base() + set.dir + String(i).padStart(3, '0') + '.webp';
}
