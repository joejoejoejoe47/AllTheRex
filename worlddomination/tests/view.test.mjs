import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { project, unproject, fitGround } from '../assets/js/camera.js';
import { findGuideLoop } from '../assets/js/guide.js';
import { rasterizeArena } from '../assets/js/geo.js';
import { cellsAlong, OOB } from '../assets/js/capture.js';
import { walk, START_RADIUS } from './sim.mjs';

const here = dirname(fileURLToPath(import.meta.url));
let pass = 0;
let fail = 0;
function check(cond, label) {
  if (cond) {
    pass++;
    console.log(`  ok  ${label}`);
  } else {
    fail++;
    console.error(`  FAIL: ${label}`);
  }
}

// --- orientation ---------------------------------------------------------
const W = 1280;
const H = 720;
const cam = { tx: 0, tz: 0, dist: 120, pitch: 0.82, yaw: 0, fov: 0.95 };
const north = project(cam, 0, 0, 40, W, H);
const south = project(cam, 0, 0, -40, W, H);
const east = project(cam, 40, 0, 0, W, H);
const west = project(cam, -40, 0, 0, W, H);
const tall = project(cam, 0, 5, 0, W, H);
const ground = project(cam, 0, 0, 0, W, H);
check(north.y < south.y, 'world view: north (higher latitude) is above south on screen');
check(east.x > west.x, 'world view: east is to the right of west');
check(tall.y < ground.y, 'things above the ground draw above it (explorer head over feet)');
const back = unproject(cam, 400, 300, W, H);
const again = project(cam, back.x, 0, back.z, W, H);
check(Math.abs(again.x - 400) < 0.01 && Math.abs(again.y - 300) < 0.01, 'click picking (unproject) round-trips');

const data = JSON.parse(readFileSync(join(here, '../assets/data/countries.json'), 'utf8'));
const byId = new Map(data.countries.map((c) => [c.id, c]));
const canada = byId.get('CA');
const brazil = byId.get('BR');
const centroid = (c) => {
  let s = 0;
  let n = 0;
  for (const r of c.overview) for (const [, lat] of r) { s += lat; n++; }
  return s / n;
};
check(project(cam, -100, 0, centroid(canada), W, H).y < project(cam, -50, 0, centroid(brazil), W, H).y, 'Canada draws above Brazil');

// arena mapping used by game.js: grid row 0 is the north edge, z = rows - y
const us = byId.get('US');
const ras = rasterizeArena(us.arena, 160);
const rows = ras.grid.length;
const arenaZ = (y) => rows - y;
const acam = { tx: 80, tz: arenaZ(80), dist: 26, pitch: 0.62, yaw: 0, fov: 0.9 };
const rowNorth = project(acam, 80, 0, arenaZ(70), W, H);
const rowSouth = project(acam, 80, 0, arenaZ(90), W, H);
check(rowNorth.y < rowSouth.y, 'arena: northern grid rows draw above southern rows');
// W moves to a smaller grid y (north) and must move up on screen
const before = project(acam, 80, 0, arenaZ(80), W, H);
const afterW = project(acam, 80, 0, arenaZ(79), W, H);
const afterD = project(acam, 81, 0, arenaZ(80), W, H);
check(afterW.y < before.y, 'arena: W / up arrow walks up the screen');
check(afterD.x > before.x, 'arena: D / right arrow walks right on screen');
// Alaska (north-west) vs Florida (south-east) inside the US raster
let alaska = null;
let florida = null;
for (let y = 0; y < rows && !alaska; y++) for (let x = 0; x < 40; x++) if (ras.grid[y][x] !== OOB) { alaska = { x, y }; break; }
for (let y = rows - 1; y >= 0 && !florida; y--) for (let x = 159; x > 100; x--) if (ras.grid[y][x] !== OOB) { florida = { x, y }; break; }
check(alaska.y < florida.y, 'US raster: Alaska rows are north of Florida rows (not flipped)');

// --- whole world fits under the sky ----------------------------------------
let minX = Infinity; let maxX = -Infinity; let minZ = Infinity; let maxZ = -Infinity;
for (const c of data.countries) for (const r of c.overview) for (const [x, z] of r) {
  minX = Math.min(minX, x); maxX = Math.max(maxX, x); minZ = Math.min(minZ, z); maxZ = Math.max(maxZ, z);
}
const bounds = { minX: minX - 5, maxX: maxX + 5, minZ: minZ - 4, maxZ: maxZ + 4 };
for (const [w, h, safe] of [
  [1280, 720, { left: 16, right: 1280 - 312, top: 70, bottom: 686 }],
  [1920, 1080, { left: 16, right: 1920 - 312, top: 70, bottom: 1046 }],
  [390, 844, { left: 10, right: 380, top: 64, bottom: 514 }],
]) {
  const fit = fitGround({ tx: 0, tz: 0, dist: 340, pitch: 0.98, yaw: 0, fov: 0.95 }, bounds, w, h, safe);
  let inside = true;
  for (const [x, z] of [[bounds.minX, bounds.minZ], [bounds.maxX, bounds.minZ], [bounds.maxX, bounds.maxZ], [bounds.minX, bounds.maxZ]]) {
    const p = project(fit, x, 0, z, w, h);
    if (!p || p.x < safe.left - 0.5 || p.x > safe.right + 0.5 || p.y < safe.top - 0.5 || p.y > safe.bottom + 0.5) inside = false;
  }
  const farEdge = project(fit, 0, 0, bounds.maxZ, w, h);
  check(inside, `${w}x${h}: initial world view contains the whole flat world`);
  check(farEdge.y > 40, `${w}x${h}: blue sky remains visible above the far (north) edge`);
  const sw = project(fit, bounds.minX, 0, bounds.minZ, w, h);
  const se = project(fit, bounds.maxX, 0, bounds.minZ, w, h);
  check(se.x - sw.x > (safe.right - safe.left) * 0.85, `${w}x${h}: the world fills most of the free width (${Math.round(se.x - sw.x)}px)`);
}

// --- trails are 4-connected ------------------------------------------------
const diag = cellsAlong(10.5, 10.5, 14.2, 13.9);
let fourConn = true;
let px = 10;
let py = 10;
for (const [x, y] of diag) {
  if (Math.abs(x - px) + Math.abs(y - py) !== 1) fourConn = false;
  px = x;
  py = y;
}
check(fourConn && diag.length > 0, 'diagonal movement produces a 4-connected trail');

// --- first-capture guide loop + real enclosure, checked by the server -------
const php = process.env.PHP_BIN || 'php';
const phpOk = spawnSync(php, ['-v']).status === 0;
function serverEval(payload) {
  const r = spawnSync(php, [join(here, 'fixtures/evaluate.php')], { input: JSON.stringify(payload), encoding: 'utf8' });
  if (r.status !== 0) throw new Error(r.stderr || 'php failed');
  return JSON.parse(r.stdout);
}

for (const id of ['US', 'FRA', 'BR', 'AU', 'IN', 'GB']) {
  const c = byId.get(id);
  if (!c) {
    check(false, `${id} present in countries.json`);
    continue;
  }
  const grid = rasterizeArena(c.arena, 160).grid;
  const matchId = `test-${id}-0001`;
  let spawn;
  if (phpOk) {
    const s = serverEval({ country: id, matchId });
    spawn = s.spawn;
    const jsPlayable = grid.flat().filter((v) => v !== OOB).length;
    check(s.playableBefore === jsPlayable, `${id}: browser and server rasterize the same playable area (${jsPlayable} cells)`);
  } else {
    spawn = { x: 80, y: 80 };
  }
  const guide = findGuideLoop(grid, spawn.x, spawn.y, START_RADIUS);
  check(!!guide, `${id}: a tutorial loop fits next to the server spawn`);
  if (!guide) continue;
  const run = walk(grid, spawn, guide.waypoints);
  const startPct = run.pctLog[0].pct;
  const jump = run.pctLog.find((e) => e.captured);
  check(!!jump && run.captures.length === 1, `${id}: walking the guide closes exactly one loop`);
  check(run.pct > startPct * 3, `${id}: land jumps from ${startPct.toFixed(2)}% to ${run.pct.toFixed(2)}% (all enclosed cells claimed)`);
  const r = guide.rect;
  let interiorOwned = true;
  for (let y = r.y0; y <= r.y1; y++) for (let x = r.x0; x <= r.x1; x++) if (run.grid[y][x] !== 1) interiorOwned = false;
  check(interiorOwned, `${id}: every cell inside the guide rectangle is now owned`);
  if (phpOk) {
    const s = serverEval({ country: id, spawn, heartbeats: run.heartbeats, captures: run.captures, elapsed: Math.ceil(run.elapsed) + 500 });
    check(s.ok === true, `${id}: server accepts the real client log${s.reason ? ` (${s.reason})` : ''}`);
    check(Math.abs(s.pct - run.pct) < 1e-6, `${id}: server score ${Number(s.pct).toFixed(3)}% equals browser ${run.pct.toFixed(3)}%`);
  }
}

if (phpOk) {
  // a forged capture (cells never walked) is refused by the server
  const c = byId.get('US');
  const grid = rasterizeArena(c.arena, 160).grid;
  const spawn = serverEval({ country: 'US', matchId: 'forge-1' }).spawn;
  const guide = findGuideLoop(grid, spawn.x, spawn.y, START_RADIUS);
  const run = walk(grid, spawn, guide.waypoints);
  const forged = serverEval({ country: 'US', spawn, heartbeats: [{ t: 0, x: spawn.x + 0.5, y: spawn.y + 0.5 }], captures: run.captures, elapsed: 60000 });
  check(forged.ok === false, 'server refuses a capture log without the matching walk');
} else {
  console.log('  (php not found: server parity checks skipped)');
}

console.log(`${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
