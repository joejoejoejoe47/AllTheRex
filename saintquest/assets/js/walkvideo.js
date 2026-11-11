// The walk "videos": one generated first-person clip per path segment (the
// main path to the cathedral, Market Street on the left, Park Square on the
// right), motion-interpolated to 60 fps. Each segment has a forward and a
// reversed copy, so W plays forward and S plays backward as real video at
// any speed. The playing <video> is drawn into the walk canvas every frame;
// the canvas keeps the last good frame, so switching clips never flashes.

const base = () => (window.SQ_BASE || '') + '/assets/';
let manifest = null, started = false, readyFns = [];
const players = {}; // seg -> { fwd, rev, ready }
let blendUntil = 0, host = null, lastDrawn = null, active = null, activeDir = 0, idleSince = 0;

export const segments = () => (manifest ? manifest.segments : null);
export const junction = () => (manifest ? manifest.junction : 4);
export const duration = (seg = 'main') => (manifest && manifest.segments[seg] ? manifest.segments[seg].duration : 12);
export const loaded = () => !!manifest;
export function onReady(fn) { readyFns.push(fn); }

function makeVideo() {
  const v = document.createElement('video');
  v.muted = true;
  v.defaultMuted = true;
  v.playsInline = true;
  v.setAttribute('playsinline', '');
  v.setAttribute('muted', '');
  v.preload = 'auto';
  host.append(v);
  return v;
}

// Each clip is downloaded whole and played from memory (a blob URL), so
// seeking (turning around, switching paths) is instant on any server or CDN.
let queue = Promise.resolve();
function fetchInto(p, which, url) {
  queue = queue.then(async () => {
    try {
      const res = await fetch(base() + url);
      if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);
      const total = +res.headers.get('content-length') || 0;
      const reader = res.body.getReader();
      const parts = [];
      let got = 0;
      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        parts.push(value);
        got += value.length;
        if (total) p.progress[which] = Math.min(0.99, got / total);
      }
      p[which].src = URL.createObjectURL(new Blob(parts, { type: 'video/mp4' }));
      p[which].load();
    } catch (e) {
      console.warn('walk video', url, e);
      p[which].src = base() + url; // fall back to streaming
    }
    p.progress[which] = 1;
  });
  return queue;
}

/** Create (and start downloading) a segment's two clips. */
export function prepare(seg) {
  if (!manifest || !manifest.segments[seg]) return null;
  if (players[seg]) return players[seg];
  const s = manifest.segments[seg];
  const p = { seg, fwd: makeVideo(), rev: makeVideo(), dur: s.duration, progress: { fwd: 0, rev: 0 } };
  players[seg] = p;
  fetchInto(p, 'fwd', s.fwd);
  fetchInto(p, 'rev', s.rev);
  return p;
}

export async function startLoading() {
  if (started) return;
  started = true;
  host = document.createElement('div');
  host.id = 'walk-videos';
  host.setAttribute('aria-hidden', 'true');
  document.body.append(host);
  manifest = await fetch(base() + 'walk/walk.json?v=2.1').then((r) => r.json());
  prepare('main');
  for (const fn of readyFns) fn();
  // Side paths buffer a little later so the main path gets the bandwidth first.
  setTimeout(() => { prepare('market'); prepare('park'); }, 2500);
}

/** 0..1 how much of a segment's forward clip is downloaded. */
export function buffered(seg = 'main') {
  const p = players[seg];
  return p ? p.progress.fwd : 0;
}

const ready = (v) => v && v.readyState >= 2;

/**
 * Advance a segment by velocity vel (video-seconds per second, negative =
 * backward) from time t. Returns the new time. The video clock is the truth
 * while playing, so the picture and t never drift apart.
 */
export function advance(seg, t, vel) {
  const p = prepare(seg);
  if (!p) return t;
  const D = p.dur;
  const dir = vel > 0.02 ? 1 : vel < -0.02 ? -1 : 0;
  if (dir === 1 && t >= D - 0.005) return D;
  if (dir === -1 && t <= 0.005) return 0;
  if (dir === 0) {
    for (const v of [p.fwd, p.rev]) if (!v.paused) v.pause();
    if (active && active.seg !== seg) active = null;
    // While standing still, line both clips up with t so turning around is instant.
    if (!idleSince) idleSince = performance.now();
    if (performance.now() - idleSince > 120) {
      if (ready(p.fwd) && Math.abs(p.fwd.currentTime - t) > 0.02) p.fwd.currentTime = t;
      if (ready(p.rev) && Math.abs(p.rev.currentTime - (D - t)) > 0.02) p.rev.currentTime = Math.max(0, D - t);
    }
    activeDir = 0;
    return t;
  }
  idleSince = 0;
  const v = dir === 1 ? p.fwd : p.rev;
  const other = dir === 1 ? p.rev : p.fwd;
  if (!other.paused) other.pause();
  // Pause any other segment's clips.
  for (const k in players) if (k !== seg) for (const x of [players[k].fwd, players[k].rev]) if (!x.paused) x.pause();
  const want = dir === 1 ? t : D - t;
  if (activeDir !== dir || !active || active.seg !== seg) {
    if (Math.abs(v.currentTime - want) > 0.03) v.currentTime = Math.max(0, Math.min(D, want));
    activeDir = dir;
    active = { seg, v };
  }
  const rate = Math.max(0.1, Math.min(4, Math.abs(vel)));
  if (Math.abs(v.playbackRate - rate) > 0.01) v.playbackRate = rate;
  if (v.paused && ready(v)) v.play().catch(() => {});
  // A clip that played to its last frame means we reached the end (or the start, walking back).
  if (!v.seeking && activeDir === dir && v.duration && v.currentTime >= v.duration - 0.03) return dir === 1 ? D : 0;
  if (v.paused || v.seeking || !ready(v)) return t;
  const vt = v.currentTime;
  const nt = dir === 1 ? vt : D - vt;
  // Only ever move in the walking direction (ignore a stale clock right after a seek).
  if (dir === 1 ? nt < t - 0.05 : nt > t + 0.05) return t;
  return Math.max(0, Math.min(D, nt));
}

/** Draw the current picture of segment seg (at time t) into ctx. Returns true when something is drawn. */
export function draw(ctx, seg, t) {
  const p = players[seg];
  if (!p) return false;
  const c = ctx.canvas;
  const s = manifest.segments[seg];
  if (c.width !== s.w) { c.width = s.w; c.height = s.h; }
  let v = null;
  if (active && active.seg === seg && activeDir && ready(active.v) && !active.v.seeking) v = active.v;
  else if (ready(p.fwd) && !p.fwd.seeking && Math.abs(p.fwd.currentTime - t) < 0.06) v = p.fwd;
  else if (ready(p.rev) && !p.rev.seeking && Math.abs(p.rev.currentTime - (p.dur - t)) < 0.06) v = p.rev;
  if (!v) return lastDrawn !== null; // keep the last good frame
  // Changing path (at the crossroads): blend over ~0.25 s instead of cutting.
  if (lastDrawn && lastDrawn !== seg) blendUntil = performance.now() + 250;
  ctx.globalAlpha = performance.now() < blendUntil ? 0.3 : 1;
  ctx.drawImage(v, 0, 0, s.w, s.h);
  ctx.globalAlpha = 1;
  lastDrawn = seg;
  return true;
}

/** URL of the still at the end of a segment (for the door transition). */
export function endUrl(seg = 'main') {
  return manifest ? base() + manifest.segments[seg].end : null;
}
