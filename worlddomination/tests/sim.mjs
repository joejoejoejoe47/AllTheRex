/**
 * Minimal headless copy of the browser movement rules (no bots), used by the
 * Node tests and the local QA script to walk the tutorial loop exactly the
 * way game.js does and produce the same heartbeats and capture log.
 */
import { attemptCapture, cellsAlong, OOB, ownedCount, playableCount } from '../assets/js/capture.js';

export const SPEED = 11;
export const START_RADIUS = 3;
export const PLAYER_ID = 1;

export function paintDisk(grid, cx, cy, r, id) {
  for (let y = cy - r; y <= cy + r; y++) {
    for (let x = cx - r; x <= cx + r; x++) {
      if (y < 0 || x < 0 || y >= grid.length || x >= grid[0].length || grid[y][x] === OOB) continue;
      if ((x - cx) ** 2 + (y - cy) ** 2 <= r * r) grid[y][x] = id;
    }
  }
}

/** Walk the player through `targets` (cell-centre points) at full speed. */
export function walk(grid, spawn, targets, { dt = 1 / 60 } = {}) {
  let g = grid.map((r) => r.slice());
  paintDisk(g, spawn.x, spawn.y, START_RADIUS, PLAYER_ID);
  const playable = playableCount(g);
  const p = { x: spawn.x + 0.5, y: spawn.y + 0.5, trail: [] };
  const heartbeats = [{ t: 0, x: p.x, y: p.y }];
  const captures = [];
  const pctLog = [];
  let t = 0;
  let lastHb = 0;
  let lastDir = null;
  let force = false;
  const owner = (x, y) => (y < 0 || x < 0 || y >= g.length || x >= g[0].length ? OOB : g[y][x]);
  const pct = () => (ownedCount(g, PLAYER_ID) / playable) * 100;
  pctLog.push({ t, pct: pct() });
  for (const target of targets) {
    for (let guard = 0; guard < 100000; guard++) {
      const dx = target.x - p.x;
      const dy = target.y - p.y;
      const d = Math.hypot(dx, dy);
      if (d < 0.05) break;
      const step = Math.min(d, SPEED * dt);
      const mv = { x: dx / d, y: dy / d };
      const nx = p.x + mv.x * step;
      const ny = p.y + mv.y * step;
      t += dt * 1000;
      for (const [cx, cy] of cellsAlong(p.x, p.y, nx, ny, (x, y) => owner(x, y) !== OOB)) {
        if (owner(cx, cy) === PLAYER_ID) {
          if (p.trail.length) {
            const trail = p.trail.map((c) => [c[0], c[1]]);
            const r = attemptCapture(g, PLAYER_ID, p.trail);
            p.trail = [];
            if (r.ok && r.captured > 0) {
              g = r.grid;
              captures.push({ t, trail });
              force = true;
              pctLog.push({ t, pct: pct(), captured: r.captured });
            }
          }
        } else {
          const last = p.trail[p.trail.length - 1];
          if (!last || last[0] !== cx || last[1] !== cy) p.trail.push([cx, cy]);
        }
      }
      p.x = nx;
      p.y = ny;
      const key = `${mv.x.toFixed(2)},${mv.y.toFixed(2)}`;
      if (force || t - lastHb > 250 || (key !== lastDir && t - lastHb > 30)) {
        heartbeats.push({ t, x: +p.x.toFixed(3), y: +p.y.toFixed(3) });
        lastHb = t;
        lastDir = key;
        force = false;
      }
    }
  }
  heartbeats.push({ t, x: +p.x.toFixed(3), y: +p.y.toFixed(3) });
  return { grid: g, heartbeats, captures, pct: pct(), pctLog, elapsed: t, playable };
}
