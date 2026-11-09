import { attemptCapture, cellsAlong, OOB, EMPTY, playableCount, ownedCount } from './capture.js';
import { pickCountry, countryCentroid, rasterizeArena } from './geo.js';
import * as api from './api.js';
import { project, unproject, groundAxes, fitGround } from './camera.js';
import { findGuideLoop } from './guide.js';

const WIN_PCT = 50;
const MATCH_MS = 180000;
const GRID = 160;
const PLAYER_ID = 1;
const START_RADIUS = 3;
const SPEED = 11;
const TAIL_SAFE = 3;
const BOT_ROSTER = [
  { slug: 'quarry', name: 'Bot Quarry', color: '#e85d4c' },
  { slug: 'marina', name: 'Bot Marina', color: '#7b6cff' },
  { slug: 'cedar', name: 'Bot Cedar', color: '#14b8a6' },
];

const keys = new Set();
const pointer = { down: false, id: null, x: 0, y: 0, lx: 0, ly: 0, t0: 0, dragging: false, handled: false, lastUpAt: 0 };
const joy = { active: false, x: 0, y: 0, px: 0, py: 0 };
let pinchDist = null;

let canvas;
let ctx;
let ui = {};
let mapData = null;
let user = null;
let podium = [];
let selected = null;
let hover = null;
let mode = 'boot'; // boot | world | arena | ending
let setupRequired = false;
let cam = { tx: 0, tz: 0, dist: 340, pitch: 0.82, yaw: 0, fov: 0.95 };
let worldBounds = { minX: -185, maxX: 185, minZ: -92, maxZ: 88 };
let worldCamTouched = false;
let tutorial = { step: 'off', waypoint: 0, gainText: '', doneAt: 0 };
const IS_TOUCH = typeof window !== 'undefined' && (('ontouchstart' in window) || (navigator.maxTouchPoints > 0));
let match = null;
let flash = 0;
let now = performance.now();
let lastT = now;
let accTime = 0;

function $(id) {
  return document.getElementById(id);
}

function clamp(v, a, b) {
  return Math.max(a, Math.min(b, v));
}

function hexToRgb(hex) {
  const h = hex.replace('#', '');
  const n = parseInt(h.length === 3 ? h.split('').map((c) => c + c).join('') : h, 16);
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function mixHex(hex, toward, t) {
  const a = hexToRgb(hex);
  const b = hexToRgb(toward);
  const r = Math.round(a.r + (b.r - a.r) * t);
  const g = Math.round(a.g + (b.g - a.g) * t);
  const bl = Math.round(a.b + (b.b - a.b) * t);
  return `rgb(${r},${g},${bl})`;
}

let audioCtx = null;
function tone(freq, dur, type = 'sine', gain = 0.05) {
  try {
    audioCtx = audioCtx || new AudioContext();
    const o = audioCtx.createOscillator();
    const g = audioCtx.createGain();
    o.type = type;
    o.frequency.value = freq;
    g.gain.setValueAtTime(gain, audioCtx.currentTime);
    g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + dur);
    o.connect(g);
    g.connect(audioCtx.destination);
    o.start();
    o.stop(audioCtx.currentTime + dur);
  } catch {
    /* audio is optional */
  }
}

function sfx(kind) {
  if (kind === 'capture') {
    tone(420, 0.08, 'triangle', 0.04);
    setTimeout(() => tone(620, 0.1, 'triangle', 0.045), 70);
  } else if (kind === 'die') {
    tone(180, 0.22, 'sawtooth', 0.035);
  } else if (kind === 'win') {
    [523, 659, 784].forEach((f, i) => setTimeout(() => tone(f, 0.18, 'triangle', 0.05), i * 140));
  } else if (kind === 'select') {
    tone(500, 0.06, 'sine', 0.03);
  }
}

function resize() {
  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  canvas.width = Math.floor(canvas.clientWidth * dpr);
  canvas.height = Math.floor(canvas.clientHeight * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function viewSize() {
  return { w: canvas.clientWidth, h: canvas.clientHeight };
}

function arenaZ(y) {
  const n = match && match.grid ? match.grid.length : GRID;
  return n - y;
}

function fillProjected(c, points, w, h, y = 0) {
  ctx.beginPath();
  let started = false;
  for (const [x, z] of points) {
    const p = project(c, x, y, z, w, h);
    if (!p) continue;
    if (!started) {
      ctx.moveTo(p.x, p.y);
      started = true;
    } else ctx.lineTo(p.x, p.y);
  }
  if (started) ctx.closePath();
  return started;
}

function drawSky(w, h) {
  const g = ctx.createLinearGradient(0, 0, 0, h);
  g.addColorStop(0, '#1c86e0');
  g.addColorStop(0.28, '#3aa4f0');
  g.addColorStop(0.55, '#7ec8ff');
  g.addColorStop(0.78, '#c5e6ff');
  g.addColorStop(1, '#eaf6ff');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, w, h);
  const sun = { x: w * 0.8, y: h * 0.13 };
  const sg = ctx.createRadialGradient(sun.x, sun.y, 6, sun.x, sun.y, 110);
  sg.addColorStop(0, 'rgba(255,248,210,1)');
  sg.addColorStop(0.28, 'rgba(255,226,110,0.5)');
  sg.addColorStop(1, 'rgba(255,220,80,0)');
  ctx.fillStyle = sg;
  ctx.beginPath();
  ctx.arc(sun.x, sun.y, 110, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = '#fff4b8';
  ctx.beginPath();
  ctx.arc(sun.x, sun.y, 20, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = 'rgba(255,255,255,0.55)';
  for (const cl of [
    [w * 0.16, h * 0.12, 58],
    [w * 0.22, h * 0.145, 40],
    [w * 0.4, h * 0.09, 44],
    [w * 0.46, h * 0.11, 28],
    [w * 0.62, h * 0.16, 36],
  ]) {
    ctx.beginPath();
    ctx.ellipse(cl[0], cl[1], cl[2], cl[2] * 0.36, 0, 0, Math.PI * 2);
    ctx.fill();
  }
}

/**
 * The flat Earth is a slab floating under the open sky: a sea-blue top face
 * plus a visible front edge so it reads as a flat world, not an endless sea.
 */
function drawSlab(c, b, w, h, { top = '#1f78b4', edge = '#0f3f66', depth = 4, rim = 'rgba(255,255,255,0.55)' } = {}) {
  const ring = [[b.minX, b.minZ], [b.maxX, b.minZ], [b.maxX, b.maxZ], [b.minX, b.maxZ]];
  // front (south) edge face
  const a0 = project(c, b.minX, 0, b.minZ, w, h);
  const a1 = project(c, b.maxX, 0, b.minZ, w, h);
  const d0 = project(c, b.minX, -depth, b.minZ, w, h);
  const d1 = project(c, b.maxX, -depth, b.minZ, w, h);
  if (a0 && a1 && d0 && d1) {
    ctx.beginPath();
    ctx.moveTo(a0.x, a0.y);
    ctx.lineTo(a1.x, a1.y);
    ctx.lineTo(d1.x, d1.y);
    ctx.lineTo(d0.x, d0.y);
    ctx.closePath();
    ctx.fillStyle = edge;
    ctx.fill();
  }
  if (fillProjected(c, ring, w, h, 0)) {
    const g = ctx.createLinearGradient(0, 0, 0, h);
    g.addColorStop(0, mixHex(top, '#9fd6ff', 0.25));
    g.addColorStop(1, top);
    ctx.fillStyle = g;
    ctx.fill();
    ctx.strokeStyle = rim;
    ctx.lineWidth = 2;
    ctx.stroke();
  }
}

function computeWorldBounds(countries) {
  let minX = Infinity;
  let maxX = -Infinity;
  let minZ = Infinity;
  let maxZ = -Infinity;
  for (const country of countries) {
    for (const ring of country.overview || []) {
      for (const [x, z] of ring) {
        if (x < minX) minX = x;
        if (x > maxX) maxX = x;
        if (z < minZ) minZ = z;
        if (z > maxZ) maxZ = z;
      }
    }
  }
  if (!Number.isFinite(minX)) return worldBounds;
  return { minX: minX - 5, maxX: maxX + 5, minZ: minZ - 4, maxZ: maxZ + 4 };
}

function worldSafeRect(w, h) {
  if (w <= 760) {
    const top = 124;
    const bottom = h - 430;
    return bottom - top >= 150 ? { left: 10, right: w - 10, top, bottom } : { left: 10, right: w - 10, top: 64, bottom: h - 40 };
  }
  const podiumW = Math.min(280, w * 0.34) + 32;
  return { left: 16, right: w - podiumW, top: 70, bottom: h - 34 };
}

function fitWorldCamera() {
  const { w, h } = viewSize();
  if (!w || !h) return;
  cam = fitGround({ tx: 0, tz: 0, dist: 340, pitch: 0.98, yaw: 0, fov: 0.95 }, worldBounds, w, h, worldSafeRect(w, h));
  // Tall phone screens need the target pulled further south / the camera
  // further back than desktop, so the pan/zoom limits follow the fitted view.
  worldLimits = {
    minTz: Math.min(-110, cam.tz - 20),
    maxDist: Math.max(900, cam.dist * 1.25),
  };
}

let worldLimits = { minTz: -110, maxDist: 900 };

function drawCountryPoly(c, rings, w, h, fill, stroke, width = 1.2) {
  for (const ring of rings) {
    if (fillProjected(c, ring.map(([lng, lat]) => [lng, lat]), w, h, 0)) {
      if (fill) {
        ctx.fillStyle = fill;
        ctx.fill();
      }
      if (stroke) {
        ctx.strokeStyle = stroke;
        ctx.lineWidth = width;
        ctx.stroke();
      }
    }
  }
}

function renderWorld(dt) {
  const { w, h } = viewSize();
  drawSky(w, h);
  drawSlab(cam, worldBounds, w, h, { depth: 6 });
  const hoverId = hover && hover.id;
  const selId = selected && selected.id;
  for (const country of mapData.countries) {
    let fill = country.color;
    if (country.id === selId) fill = mixHex(country.color, '#ffe27a', 0.45);
    else if (country.id === hoverId) fill = mixHex(country.color, '#ffffff', 0.28);
    drawCountryPoly(cam, country.overview, w, h, fill, 'rgba(40,30,10,0.55)', 1.05);
  }
  if (selected) {
    const pulse = 1.6 + Math.sin(now * 0.006) * 0.5;
    drawCountryPoly(cam, selected.overview, w, h, null, 'rgba(255,214,70,0.95)', pulse);
  }
}

function paintDisk(grid, cx, cy, r, id) {
  const rows = grid.length;
  const cols = grid[0].length;
  for (let y = cy - r; y <= cy + r; y++) {
    for (let x = cx - r; x <= cx + r; x++) {
      if (x < 0 || y < 0 || x >= cols || y >= rows) continue;
      if (grid[y][x] === OOB) continue;
      if ((x - cx) * (x - cx) + (y - cy) * (y - cy) <= r * r) grid[y][x] = id;
    }
  }
}

function findSpawn(grid, avoid = []) {
  const rows = grid.length;
  const cols = grid[0].length;
  const candidates = [];
  for (let y = 0; y < rows; y += 2) {
    for (let x = 0; x < cols; x += 2) {
      if (grid[y][x] !== EMPTY) continue;
      let far = true;
      for (const a of avoid) {
        if (Math.hypot(x - a.x, y - a.y) < 18) {
          far = false;
          break;
        }
      }
      if (far) candidates.push({ x, y });
    }
  }
  if (!candidates.length) {
    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        if (grid[y][x] === EMPTY || (grid[y][x] !== OOB && !avoid.length)) {
          return { x, y };
        }
      }
    }
    return { x: (cols / 2) | 0, y: (rows / 2) | 0 };
  }
  return candidates[(Math.random() * candidates.length) | 0];
}

function actorBase(partial) {
  return {
    trail: [],
    dead: false,
    deadUntil: 0,
    dir: { x: 1, y: 0 },
    mode: 'expand',
    goal: null,
    think: 0,
    maxTrail: 14 + ((Math.random() * 16) | 0),
    peakPct: 0,
    ...partial,
  };
}

function startArena(country, tokenInfo) {
  const ras = rasterizeArena(country.arena, GRID);
  const grid = ras.grid;
  const playable = playableCount(grid);
  const botCount = playable > 5200 ? 3 : 2;
  const playerSpawn = (tokenInfo.spawn && Number.isFinite(tokenInfo.spawn.x) && Number.isFinite(tokenInfo.spawn.y))
    ? { x: tokenInfo.spawn.x | 0, y: tokenInfo.spawn.y | 0 }
    : findSpawn(grid);
  paintDisk(grid, playerSpawn.x, playerSpawn.y, START_RADIUS, PLAYER_ID);
  const player = actorBase({
    id: PLAYER_ID,
    name: user ? user.name : 'You',
    color: '#ffd24a',
    isBot: false,
    x: playerSpawn.x + 0.5,
    y: playerSpawn.y + 0.5,
    slug: 'player',
  });
  const actors = [player];
  for (let i = 0; i < botCount; i++) {
    const info = BOT_ROSTER[i];
    const spawn = findSpawn(grid, actors.map((a) => ({ x: a.x, y: a.y })));
    paintDisk(grid, spawn.x, spawn.y, START_RADIUS, PLAYER_ID + 1 + i);
    actors.push(actorBase({
      id: PLAYER_ID + 1 + i,
      name: info.name,
      color: info.color,
      isBot: true,
      slug: info.slug,
      x: spawn.x + 0.5,
      y: spawn.y + 0.5,
    }));
  }
  match = {
    country,
    token: tokenInfo.token,
    matchId: tokenInfo.matchId,
    grid,
    playable,
    actors,
    player,
    startedAt: performance.now(),
    durationMs: tokenInfo.durationMs || MATCH_MS,
    winPct: tokenInfo.winPct || WIN_PCT,
    ending: false,
    heartbeats: [{ t: 0, x: player.x, y: player.y }],
    captures: [],
    lastHb: 0,
    lastHbDir: null,
    forceHb: false,
    capturePulse: 0,
    lastGain: 0,
  };
  match.guideLoop = findGuideLoop(grid, playerSpawn.x, playerSpawn.y, START_RADIUS);
  tutorial = { step: match.guideLoop ? 'guide' : 'free', waypoint: 0, gainText: '', doneAt: 0 };
  cam.tx = player.x;
  cam.tz = arenaZ(player.y);
  cam.dist = 26;
  cam.pitch = 0.62;
  cam.yaw = 0;
  cam.fov = 0.9;
  mode = 'arena';
  ui.arenaHud.hidden = false;
  ui.selectCard.hidden = true;
  ui.joystick.hidden = !IS_TOUCH;
  updateArenaHud(0);
  renderControls();
  renderTutorial();
}

function pctOf(actor) {
  if (!match) return 0;
  return (ownedCount(match.grid, actor.id) / Math.max(1, match.playable)) * 100;
}

function kill(actor) {
  if (!match || actor.dead) return;
  actor.dead = true;
  actor.deadUntil = now + 1400;
  actor.trail = [];
  const grid = match.grid;
  for (let y = 0; y < grid.length; y++) {
    for (let x = 0; x < grid[0].length; x++) {
      if (grid[y][x] === actor.id) grid[y][x] = EMPTY;
    }
  }
  sfx('die');
  if (!actor.isBot) showNotice('Knocked out! Your trail was crossed. You respawn with fresh starter land.');
}

function respawn(actor) {
  const others = match.actors.filter((a) => a !== actor && !a.dead).map((a) => ({ x: a.x, y: a.y }));
  const spawn = findSpawn(match.grid, others);
  paintDisk(match.grid, spawn.x, spawn.y, START_RADIUS, actor.id);
  actor.x = spawn.x + 0.5;
  actor.y = spawn.y + 0.5;
  actor.trail = [];
  actor.dead = false;
  actor.mode = 'expand';
  actor.goal = null;
  if (!actor.isBot && match) {
    match.heartbeats.push({ t: now - match.startedAt, x: +actor.x.toFixed(3), y: +actor.y.toFixed(3), respawn: true });
    match.lastHb = now;
    if (tutorial.step === 'guide') tutorial.step = 'free';
    renderTutorial();
  }
}

function cellOwner(x, y) {
  const gx = Math.floor(x);
  const gy = Math.floor(y);
  const grid = match.grid;
  if (gy < 0 || gx < 0 || gy >= grid.length || gx >= grid[0].length) return OOB;
  return grid[gy][gx];
}

function trailHas(actor, x, y, ignoreTail = TAIL_SAFE) {
  const safeFrom = Math.max(0, actor.trail.length - ignoreTail);
  for (let i = 0; i < safeFrom; i++) {
    if (actor.trail[i][0] === x && actor.trail[i][1] === y) return true;
  }
  return false;
}

function closeCapture(actor) {
  if (!actor.trail.length) return;
  const trailCopy = actor.trail.map((cell) => [cell[0], cell[1]]);
  const before = actor.isBot ? 0 : pctOf(actor);
  const result = attemptCapture(match.grid, actor.id, actor.trail);
  actor.trail = [];
  if (!result.ok || result.captured <= 0) return;
  match.grid = result.grid;
  if (actor.isBot) {
    flash = Math.max(flash, 0.12);
    return;
  }
  flash = 0.5;
  sfx('capture');
  match.capturePulse = 1;
  match.forceHb = true;
  match.captures.push({ t: now - match.startedAt, trail: trailCopy });
  const after = pctOf(actor);
  match.lastGain = after - before;
  const standings = standingsNow();
  const rank = standings.findIndex((s) => s.actor === actor) + 1;
  const leader = standings[0];
  const lead = rank === 1
    ? 'You lead this practice match.'
    : `${leader.actor.name} (practice bot) leads with ${leader.pct.toFixed(1)}%.`;
  tutorial.gainText = `Loop closed! +${match.lastGain.toFixed(1)}% land. You now hold ${after.toFixed(1)}% — #${rank} of ${standings.length}. ${lead}`;
  tutorial.doneAt = now;
  if (tutorial.step === 'guide' || tutorial.step === 'free') tutorial.step = 'captured';
  renderTutorial();
}

function standingsNow() {
  return match.actors
    .map((a) => ({ actor: a, pct: pctOf(a) }))
    .sort((a, b) => b.pct - a.pct || (a.actor.isBot ? 1 : 0) - (b.actor.isBot ? 1 : 0));
}

function processCell(actor, cx, cy) {
  const owner = cellOwner(cx + 0.5, cy + 0.5);
  if (owner === OOB) return false;
  for (const other of match.actors) {
    if (other.dead) continue;
    if (trailHas(other, cx, cy, other === actor ? TAIL_SAFE : 1)) {
      if (actor.isBot && other === match.player && tutorial.step === 'guide') {
        // First-capture tutorial: practice bots step around a new explorer's
        // trail instead of cutting it.
        actor.goal = pickExpandGoal(actor);
        return false;
      }
      kill(other);
      if (other === actor) return false;
    }
  }
  if (actor.dead) return false;
  if (owner === actor.id) {
    if (actor.trail.length) closeCapture(actor);
  } else {
    const last = actor.trail[actor.trail.length - 1];
    if (!last || last[0] !== cx || last[1] !== cy) actor.trail.push([cx, cy]);
  }
  return true;
}

function tryMove(actor, nx, ny) {
  const rows = match.grid.length;
  const cols = match.grid[0].length;
  if (nx < 0.6 || ny < 0.6 || nx > cols - 0.6 || ny > rows - 0.6) return;
  if (cellOwner(nx, ny) === OOB) {
    // Slide along the border, still recording every cell entered.
    if (nx !== actor.x && cellOwner(nx, actor.y) !== OOB) tryMove(actor, nx, actor.y);
    else if (ny !== actor.y && cellOwner(actor.x, ny) !== OOB) tryMove(actor, actor.x, ny);
    return;
  }
  const cells = cellsAlong(actor.x, actor.y, nx, ny, (x, y) => cellOwner(x + 0.5, y + 0.5) !== OOB);
  for (const [gx, gy] of cells) {
    // Trails stay 4-connected so a loop is a closed wall for the flood fill
    // (and the server accepts it).
    if (!processCell(actor, gx, gy) || actor.dead) return;
  }
  actor.x = nx;
  actor.y = ny;
}

function nearestOwned(actor) {
  const grid = match.grid;
  let best = null;
  let bestD = Infinity;
  const ax = Math.floor(actor.x);
  const ay = Math.floor(actor.y);
  for (let y = 0; y < grid.length; y++) {
    for (let x = 0; x < grid[0].length; x++) {
      if (grid[y][x] !== actor.id) continue;
      const d = (x - ax) * (x - ax) + (y - ay) * (y - ay);
      if (d < bestD) {
        bestD = d;
        best = { x: x + 0.5, y: y + 0.5 };
      }
    }
  }
  return best || { x: actor.x, y: actor.y };
}

function pickExpandGoal(actor) {
  const grid = match.grid;
  for (let tries = 0; tries < 40; tries++) {
    const x = 2 + Math.random() * (grid[0].length - 4);
    const y = 2 + Math.random() * (grid.length - 4);
    if (cellOwner(x, y) === EMPTY) return { x, y };
  }
  return { x: actor.x + (Math.random() - 0.5) * 20, y: actor.y + (Math.random() - 0.5) * 20 };
}

function updateBot(bot, dt) {
  if (bot.dead) return;
  const onHome = cellOwner(bot.x, bot.y) === bot.id;
  bot.think -= dt;
  if (bot.think <= 0) {
    bot.think = 0.35 + Math.random() * 0.45;
    const player = match.player;
    const nearPlayerTrail = player.trail.some((c) => Math.hypot(c[0] - bot.x, c[1] - bot.y) < 10);
    if (!onHome && (bot.trail.length >= bot.maxTrail || Math.random() < 0.2)) {
      bot.mode = 'return';
      bot.goal = nearestOwned(bot);
    } else if (nearPlayerTrail && player.trail.length > 4 && tutorial.step !== 'guide') {
      // practice bots leave a new explorer alone until the first loop is closed
      bot.mode = 'hunt';
      const mid = player.trail[(player.trail.length / 2) | 0];
      bot.goal = { x: mid[0] + 0.5, y: mid[1] + 0.5 };
    } else if (onHome) {
      bot.mode = 'expand';
      bot.goal = pickExpandGoal(bot);
      bot.maxTrail = 12 + ((Math.random() * 18) | 0);
    }
  }
  if (!bot.goal) bot.goal = pickExpandGoal(bot);
  let dx = bot.goal.x - bot.x;
  let dy = bot.goal.y - bot.y;
  const mag = Math.hypot(dx, dy) || 1;
  if (mag < 1.2 && bot.mode === 'expand') bot.mode = 'return';
  if (bot.mode === 'return') bot.goal = nearestOwned(bot);
  dx = bot.goal.x - bot.x;
  dy = bot.goal.y - bot.y;
  const m = Math.hypot(dx, dy) || 1;
  bot.dir = { x: dx / m, y: dy / m };
  bot.moving = true;
  tryMove(bot, bot.x + bot.dir.x * SPEED * 0.86 * dt, bot.y + bot.dir.y * SPEED * 0.86 * dt);
}

function readMoveVector() {
  let ix = 0;
  let iy = 0;
  if (keys.has('KeyW') || keys.has('ArrowUp')) iy -= 1;
  if (keys.has('KeyS') || keys.has('ArrowDown')) iy += 1;
  if (keys.has('KeyA') || keys.has('ArrowLeft')) ix -= 1;
  if (keys.has('KeyD') || keys.has('ArrowRight')) ix += 1;
  if (joy.active && (joy.x || joy.y)) {
    ix = joy.x;
    iy = joy.y;
  }
  const m = Math.hypot(ix, iy);
  if (m < 0.12) return null;
  return { x: ix / m, y: iy / m };
}

function collideHeads() {
  const list = match.actors.filter((a) => !a.dead);
  for (let i = 0; i < list.length; i++) {
    for (let j = i + 1; j < list.length; j++) {
      const a = list[i];
      const b = list[j];
      if (Math.hypot(a.x - b.x, a.y - b.y) > 0.7) continue;
      if (tutorial.step === 'guide' && (a === match.player || b === match.player)) continue;
      const aOut = a.trail.length > 0;
      const bOut = b.trail.length > 0;
      if (aOut) kill(a);
      if (bOut) kill(b);
    }
  }
}

function maybeEndMatch() {
  if (!match || match.ending) return;
  const elapsed = now - match.startedAt;
  for (const s of standingsNow()) s.actor.peakPct = Math.max(s.actor.peakPct, s.pct);
  let outcome = null;
  if (pctOf(match.player) >= match.winPct) outcome = 'win';
  else if (elapsed >= match.durationMs) {
    outcome = 'timeout';
  }
  if (outcome) finishArena(outcome);
}

async function finishArena(outcome) {
  if (!match || match.ending) return;
  match.ending = true;
  mode = 'ending';
  tutorial.step = 'off';
  renderTutorial();
  let playerPct = pctOf(match.player);
  if (outcome === 'win') sfx('win');
  let saved = null;
  let saveError = '';
  try {
    if (match.player && !match.player.dead) {
      match.heartbeats.push({
        t: now - match.startedAt,
        x: +match.player.x.toFixed(3),
        y: +match.player.y.toFixed(3),
      });
    }
    saved = await api.finishMatch({
      token: match.token,
      intent: outcome === 'quit' ? 'quit' : 'finish',
      claimedPct: +pctOf(match.player).toFixed(4),
      heartbeats: match.heartbeats || [],
      captures: match.captures || [],
    });
    if (saved && saved.player) {
      if (Number.isFinite(saved.player.pct)) playerPct = saved.player.pct;
      if (saved.player.outcome) outcome = saved.player.outcome;
    }
    podium = saved.podium || podium;
    renderPodium();
  } catch (err) {
    saveError = err.message || 'Could not save this result.';
  }
  const title = outcome === 'win'
    ? `You dominate ${match.country.name}!`
    : outcome === 'timeout'
      ? `Time! You held ${playerPct.toFixed(1)}% of ${match.country.name}`
      : outcome === 'quit'
        ? `You left ${match.country.name} with ${playerPct.toFixed(1)}%`
        : `You held ${playerPct.toFixed(1)}% of ${match.country.name}`;
  showModal(`
    <div class="card end-card">
      <h2>${escapeHtml(title)}</h2>
      <p class="lead">Official score (server-checked): <strong>${playerPct.toFixed(1)}%</strong></p>
      <ul class="end-bots">${standingsNow().map(({ actor: a, pct }) => `<li><span class="swatch" style="background:${a.color}"></span>${escapeHtml(a === match.player ? `${a.name} (you)` : a.name)}${a.isBot ? ' <em>practice bot</em>' : ''} — ${pct.toFixed(1)}%</li>`).join('')}</ul>
      <p class="muted">Bots are practice opponents. They cannot take the podium or be named champion.</p>
      ${saveError ? `<p class="err">${escapeHtml(saveError)}</p>` : '<p class="ok">Result saved. Only real explorer accounts can reach the podium.</p>'}
      <button class="btn primary" data-close-world>Back to the world</button>
    </div>`);
  ui.modal.querySelector('[data-close-world]').addEventListener('click', () => {
    hideModal();
    leaveArena(false);
  });
}

function leaveArena(submitQuit) {
  if (submitQuit && match && !match.ending) {
    finishArena('quit');
    return;
  }
  match = null;
  mode = 'world';
  fitWorldCamera();
  if (selected) {
    const c0 = countryCentroid(selected);
    cam = { ...cam, tx: c0.lng, tz: c0.lat, dist: cam.dist * 0.6 };
  }
  ui.arenaHud.hidden = true;
  ui.joystick.hidden = true;
  tutorial.step = 'off';
  renderTutorial();
  renderControls();
  if (selected) {
    ui.selectCard.hidden = false;
    fillSelectCard();
  }
}

function updateArenaHud(elapsed) {
  const remain = Math.max(0, match.durationMs - elapsed);
  const s = Math.ceil(remain / 1000);
  const mm = String((s / 60) | 0);
  const ss = String(s % 60).padStart(2, '0');
  const rows = standingsNow().map(({ actor: a, pct }, i) => {
    return `<div class="bar-row ${a === match.player ? 'you' : ''}">
      <span class="who"><b class="rank">#${i + 1}</b><i style="background:${a.color}"></i>${escapeHtml(a === match.player ? `${a.name} (you)` : a.name)}${a.isBot ? '<em>practice bot</em>' : ''}</span>
      <span class="pct">${pct.toFixed(1)}%</span>
      <span class="track"><span style="width:${Math.min(100, pct)}%;background:${a.color}"></span></span>
    </div>`;
  }).join('');
  ui.matchInfo.innerHTML = `<div class="match-top"><strong>${escapeHtml(match.country.name)}</strong><span>Claim ${match.winPct}% · ${mm}:${ss}</span></div>${rows}`;
}

function renderArena(dt) {
  const { w, h } = viewSize();
  const p = match.player;
  const follow = 1 - Math.exp(-dt * 7.5);
  const ahead = p.moving ? 3.2 : 0.6;
  cam.tx += ((p.x + p.dir.x * ahead) - cam.tx) * follow;
  cam.tz += ((arenaZ(p.y + p.dir.y * ahead)) - cam.tz) * follow;
  if (match.capturePulse > 0) match.capturePulse = Math.max(0, match.capturePulse - dt * 1.35);
  drawSky(w, h);
  const n = match.grid.length;
  const cols = match.grid[0].length;
  drawSlab(cam, { minX: -12, maxX: cols + 12, minZ: -12, maxZ: n + 12 }, w, h, { depth: 3 });
  drawArenaGround(w, h);
  drawTerritories(w, h);
  drawGuide(w, h);
  for (const actor of match.actors) drawTrail(actor, w, h);
  // far (north, small y) first so nearer explorers overlap them
  const sorted = match.actors.slice().sort((a, b) => a.y - b.y);
  for (const actor of sorted) {
    if (actor.dead) continue;
    drawGuy(actor, w, h);
  }
  if (flash > 0) {
    ctx.fillStyle = `rgba(255,230,120,${flash * 0.35})`;
    ctx.fillRect(0, 0, w, h);
    flash = Math.max(0, flash - dt);
  }
}

function drawArenaGround(w, h) {
  const step = 1;
  ctx.beginPath();
  let started = false;
  // Approximate by drawing all land as merged scan spans on a low mesh: project country arena ring in grid space
  const ring = match.country.arena.outer;
  const pts = ringToGrid(ring, match.country.arena).map(([x, y]) => [x, arenaZ(y)]);
  if (fillProjected(cam, pts, w, h, 0)) {
    // neutral green land so every explorer colour (yellow you, red/purple/teal
    // bots) stands out clearly
    ctx.fillStyle = mixHex(match.country.color, '#3f7a45', 0.7);
    ctx.fill();
    ctx.strokeStyle = 'rgba(40,28,8,0.75)';
    ctx.lineWidth = 2.4;
    ctx.stroke();
  }
  void step;
}

function ringToGrid(ring) {
  // Re-project lng/lat onto the same raster used by rasterizeArena by sampling bbox again — use inverse of cell centers roughly:
  // We stored no transform. Rebuild the same fit:
  const arena = match.country.arena;
  const outer = arena.outer;
  let minX = Infinity;
  let minY = Infinity;
  let maxX = -Infinity;
  let maxY = -Infinity;
  for (const [x, y] of outer) {
    if (x < minX) minX = x;
    if (y < minY) minY = y;
    if (x > maxX) maxX = x;
    if (y > maxY) maxY = y;
  }
  const pad = 0.05 * Math.max(maxX - minX, maxY - minY, 1);
  minX -= pad;
  minY -= pad;
  maxX += pad;
  maxY += pad;
  const bw = Math.max(maxX - minX, 1e-6);
  const bh = Math.max(maxY - minY, 1e-6);
  const inner = GRID - 4;
  const scale = inner / Math.max(bw, bh);
  const ox = 2 + (inner - bw * scale) / 2;
  const oy = 2 + (inner - bh * scale) / 2;
  return ring.map(([lng, lat]) => [ox + (lng - minX) * scale, oy + (maxY - lat) * scale]);
}

function drawTerritories(w, h) {
  const grid = match.grid;
  const rows = grid.length;
  const cols = grid[0].length;
  const byId = new Map(match.actors.map((a) => [a.id, a]));
  for (let y = 0; y < rows; y++) {
    let x = 0;
    while (x < cols) {
      const id = grid[y][x];
      if (id <= 0) {
        x++;
        continue;
      }
      let x2 = x + 1;
      while (x2 < cols && grid[y][x2] === id) x2++;
      const actor = byId.get(id);
      const quad = [
        [x, arenaZ(y)],
        [x2, arenaZ(y)],
        [x2, arenaZ(y + 1)],
        [x, arenaZ(y + 1)],
      ];
      if (fillProjected(cam, quad, w, h, 0.04)) {
        let fill = actor ? actor.color + 'cc' : 'rgba(255,255,255,0.35)';
        if (actor && !actor.isBot && match.capturePulse > 0) {
          fill = mixHex(actor.color, '#fff4b0', 0.35 + match.capturePulse * 0.45);
        }
        ctx.fillStyle = fill;
        ctx.fill();
      }
      x = x2;
    }
  }
}

function drawTrail(actor, w, h) {
  if (!actor.trail.length) return;
  const pts = [];
  for (const [x, y] of actor.trail) {
    const p = project(cam, x + 0.5, 0.16, arenaZ(y + 0.5), w, h);
    if (p) pts.push(p);
  }
  const head = project(cam, actor.x, 0.18, arenaZ(actor.y), w, h);
  if (head) pts.push(head);
  if (pts.length < 2) return;
  const tipZ = head ? head.z : pts[pts.length - 1].z;
  const width = clamp(140 / (tipZ + 6), 6, 16);
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  ctx.beginPath();
  ctx.moveTo(pts[0].x, pts[0].y);
  for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i].x, pts[i].y);
  ctx.strokeStyle = 'rgba(12, 16, 24, 0.8)';
  ctx.lineWidth = width + 5;
  ctx.stroke();
  ctx.strokeStyle = actor.isBot ? 'rgba(255,255,255,0.72)' : '#fff4c2';
  ctx.lineWidth = width + 1.8;
  ctx.stroke();
  ctx.strokeStyle = actor.color;
  ctx.lineWidth = width * 0.62;
  ctx.stroke();
  if (head) {
    ctx.fillStyle = actor.color;
    ctx.beginPath();
    ctx.arc(head.x, head.y, width * 0.42, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = '#fff8dc';
    ctx.lineWidth = 1.6;
    ctx.stroke();
  }
}

function drawGuy(actor, w, h) {
  const feet = project(cam, actor.x, 0, arenaZ(actor.y), w, h);
  const crown = project(cam, actor.x, 2.35, arenaZ(actor.y), w, h);
  if (!feet) return;
  let s = crown ? (feet.y - crown.y) : (320 / (feet.z + 5));
  s = clamp(s, 34, 120);
  const yaw = cam.yaw;
  const side = Math.cos(yaw) * actor.dir.x - Math.sin(yaw) * actor.dir.y;
  const flip = side < 0 ? -1 : 1;
  const moving = !!actor.moving;
  const walk = moving ? Math.sin(now * 0.016 + actor.id * 2) : 0;
  const bob = moving ? Math.abs(walk) * s * 0.045 : 0;
  const x = feet.x;
  const y = feet.y - bob;
  const skin = actor.isBot ? '#e3b08a' : '#f3c29c';
  const outfit = actor.color;
  const pants = mixHex(actor.color, '#243044', 0.48);
  const boots = '#3b2818';
  const hair = actor.isBot ? mixHex(actor.color, '#2b160c', 0.5) : '#4a2a12';

  ctx.fillStyle = 'rgba(18, 16, 10, 0.34)';
  ctx.beginPath();
  ctx.ellipse(x, feet.y + s * 0.02, s * 0.3, s * 0.1, 0, 0, Math.PI * 2);
  ctx.fill();

  ctx.strokeStyle = pants;
  ctx.lineWidth = Math.max(4, s * 0.11);
  ctx.lineCap = 'round';
  ctx.beginPath();
  ctx.moveTo(x - s * 0.07, y - s * 0.4);
  ctx.lineTo(x - s * 0.12 - walk * s * 0.14, y - s * 0.02);
  ctx.moveTo(x + s * 0.07, y - s * 0.4);
  ctx.lineTo(x + s * 0.12 + walk * s * 0.14, y - s * 0.02);
  ctx.stroke();
  ctx.strokeStyle = boots;
  ctx.lineWidth = Math.max(3, s * 0.08);
  ctx.beginPath();
  ctx.moveTo(x - s * 0.12 - walk * s * 0.14, y - s * 0.08);
  ctx.lineTo(x - s * 0.12 - walk * s * 0.14 + flip * s * 0.07, y + s * 0.01);
  ctx.moveTo(x + s * 0.12 + walk * s * 0.14, y - s * 0.08);
  ctx.lineTo(x + s * 0.12 + walk * s * 0.14 + flip * s * 0.07, y + s * 0.01);
  ctx.stroke();

  ctx.fillStyle = outfit;
  roundRect(x - s * 0.2, y - s * 0.76, s * 0.4, s * 0.4, s * 0.09);
  ctx.fill();
  ctx.fillStyle = mixHex(outfit, '#fff1b0', 0.38);
  ctx.fillRect(x - s * 0.2, y - s * 0.44, s * 0.4, s * 0.06);

  ctx.strokeStyle = mixHex(outfit, '#000000', 0.18);
  ctx.lineWidth = Math.max(3, s * 0.08);
  ctx.beginPath();
  ctx.moveTo(x - s * 0.2, y - s * 0.68);
  ctx.lineTo(x - s * 0.32, y - s * 0.44 + walk * s * 0.08);
  ctx.moveTo(x + s * 0.2, y - s * 0.68);
  ctx.lineTo(x + s * 0.34, y - s * 0.5 - walk * s * 0.08);
  ctx.stroke();
  ctx.fillStyle = skin;
  ctx.beginPath();
  ctx.arc(x - s * 0.32, y - s * 0.44 + walk * s * 0.08, s * 0.055, 0, Math.PI * 2);
  ctx.arc(x + s * 0.34, y - s * 0.5 - walk * s * 0.08, s * 0.055, 0, Math.PI * 2);
  ctx.fill();

  ctx.fillStyle = skin;
  ctx.beginPath();
  ctx.arc(x + flip * s * 0.03, y - s * 0.88, s * 0.155, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = hair;
  ctx.beginPath();
  ctx.ellipse(x + flip * s * 0.025, y - s * 0.97, s * 0.165, s * 0.11, 0, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = '#1a120c';
  ctx.beginPath();
  ctx.arc(x + flip * s * 0.07, y - s * 0.89, s * 0.03, 0, Math.PI * 2);
  ctx.arc(x + flip * s * 0.14, y - s * 0.89, s * 0.03, 0, Math.PI * 2);
  ctx.fill();
  ctx.strokeStyle = '#b36b4d';
  ctx.lineWidth = Math.max(1.2, s * 0.025);
  ctx.beginPath();
  ctx.arc(x + flip * s * 0.1, y - s * 0.84, s * 0.055, 0.2, Math.PI - 0.2);
  ctx.stroke();

  const label = actor.name;
  ctx.font = `700 ${Math.max(13, Math.round(s * 0.2))}px Nunito, Trebuchet MS, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'bottom';
  const tw = ctx.measureText(label).width;
  const ny = y - s * 1.12;
  ctx.fillStyle = 'rgba(10, 18, 30, 0.74)';
  roundRect(x - tw / 2 - 8, ny - Math.max(18, s * 0.26), tw + 16, Math.max(18, s * 0.26), 9);
  ctx.fill();
  ctx.fillStyle = '#fff8e8';
  ctx.fillText(label, x, ny - 3);
  ctx.textBaseline = 'alphabetic';
}

function roundRect(x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function updateWorldCamera(dt) {
  let ix = 0;
  let iz = 0;
  if (keys.has('KeyW') || keys.has('ArrowUp')) iz += 1;
  if (keys.has('KeyS') || keys.has('ArrowDown')) iz -= 1;
  if (keys.has('KeyA') || keys.has('ArrowLeft')) ix -= 1;
  if (keys.has('KeyD') || keys.has('ArrowRight')) ix += 1;
  if (ix || iz) {
    const m = Math.hypot(ix, iz) || 1;
    const speed = cam.dist * 0.55;
    const { fx, fz, rx, rz } = groundAxes(cam);
    worldCamTouched = true;
    cam.tx += ((rx * ix + fx * iz) / m) * speed * dt;
    cam.tz += ((rz * ix + fz * iz) / m) * speed * dt;
  }
  cam.tx = clamp(cam.tx, -180, 180);
  cam.tz = clamp(cam.tz, worldLimits.minTz, 90);
  cam.dist = clamp(cam.dist, 40, worldLimits.maxDist);
}

function updateArena(dt) {
  for (const actor of match.actors) {
    if (actor.dead && now >= actor.deadUntil) respawn(actor);
  }
  const player = match.player;
  if (!player.dead) {
    const mv = readMoveVector();
    player.moving = !!mv;
    if (mv) {
      player.dir = mv;
      tryMove(player, player.x + mv.x * SPEED * dt, player.y + mv.y * SPEED * dt);
    }
    const dirKey = mv ? `${mv.x.toFixed(2)},${mv.y.toFixed(2)}` : 'stop';
    const turned = dirKey !== match.lastHbDir;
    if (match.forceHb || now - match.lastHb > 250 || (turned && now - match.lastHb > 30)) {
      if (match.heartbeats.length < 3900) {
        match.heartbeats.push({ t: now - match.startedAt, x: +player.x.toFixed(3), y: +player.y.toFixed(3) });
      }
      match.lastHb = now;
      match.lastHbDir = dirKey;
      match.forceHb = false;
    }
    updateTutorialProgress();
  }
  for (const actor of match.actors) {
    if (actor.isBot) updateBot(actor, dt);
  }
  collideHeads();
  const elapsed = now - match.startedAt;
  updateArenaHud(elapsed);
  maybeEndMatch();
}

function frame(t) {
  now = t;
  const dt = Math.min(0.05, (t - lastT) / 1000);
  lastT = t;
  accTime += dt;
  const { w, h } = viewSize();
  if (mode === 'world') {
    updateWorldCamera(dt);
    renderWorld(dt);
  } else if (mode === 'arena' || mode === 'ending') {
    if (mode === 'arena') updateArena(dt);
    renderArena(dt);
  } else {
    drawSky(w, h);
  }
  requestAnimationFrame(frame);
}

// Country picking works in screen space against the exact polygons that
// were drawn, so a click lands on whatever the player sees under the cursor.
// If the click is just off a coast (or on a tiny island) the nearest drawn
// country within a few pixels wins.
const PICK_SLOP_PX = 10;
const CLICK_MOVE_PX = 8;
const CLICK_MOVE_QUICK_PX = 16;
const CLICK_QUICK_MS = 350;

function pickAt(sx, sy) {
  if (!mapData) return null;
  const { w, h } = viewSize();
  const hit = unproject(cam, sx, sy, w, h);
  if (hit) {
    const exact = pickCountry(hit.x, hit.z, mapData.countries);
    if (exact) return exact;
  }
  return pickNearScreen(sx, sy, w, h);
}

function pickNearScreen(sx, sy, w, h) {
  let best = null;
  let bestD = PICK_SLOP_PX * PICK_SLOP_PX;
  for (const country of mapData.countries) {
    for (const ring of country.overview) {
      let prev = null;
      for (const [lng, lat] of ring) {
        const q = project(cam, lng, 0, lat, w, h);
        if (q && prev) {
          const d = segDist2(sx, sy, prev.x, prev.y, q.x, q.y);
          if (d < bestD) {
            bestD = d;
            best = country;
          }
        }
        prev = q;
      }
    }
  }
  return best;
}

function segDist2(px, py, ax, ay, bx, by) {
  const dx = bx - ax;
  const dy = by - ay;
  const len = dx * dx + dy * dy;
  let t = len ? ((px - ax) * dx + (py - ay) * dy) / len : 0;
  t = Math.max(0, Math.min(1, t));
  const ex = ax + dx * t - px;
  const ey = ay + dy * t - py;
  return ex * ex + ey * ey;
}

function selectCountry(country) {
  if (!country) return false;
  selected = country;
  fillSelectCard();
  ui.selectCard.hidden = false;
  sfx('select');
  return true;
}

// Our own on-screen UI keeps its clicks. Anything else (the canvas, or a
// transparent layer some browser extension injects above it) is the map.
function isUiTarget(target) {
  return !!(target && target.closest && target.closest('#joystick,#select-card,#arena-hud,#podium,#controls-guide,#topbar a,#topbar button,#user-slot,#modal,#notice,#fatal,#tutorial-banner,button,input,a,label,select,textarea'));
}

function resetPointer() {
  pointer.down = false;
  pointer.id = null;
  pointer.dragging = false;
}

function onPointerDown(e) {
  if (e.button !== undefined && e.button !== 0 && e.pointerType === 'mouse') return;
  if (isUiTarget(e.target)) return;
  if (!ui.modal.hidden) return;
  pointer.down = true;
  pointer.id = e.pointerId;
  pointer.x = pointer.lx = e.clientX;
  pointer.y = pointer.ly = e.clientY;
  pointer.t0 = performance.now();
  pointer.dragging = false;
  pointer.handled = false;
  if (e.target === canvas) {
    try { canvas.setPointerCapture(e.pointerId); } catch { /* ignore */ }
  }
}

function onPointerMove(e) {
  if (!pointer.down || e.pointerId !== pointer.id) {
    if (mode === 'world' && !pointer.down && e.pointerType !== 'touch') {
      hover = pickAt(e.clientX, e.clientY);
      canvas.style.cursor = hover ? 'pointer' : 'grab';
    }
    return;
  }
  const moved = Math.hypot(e.clientX - pointer.x, e.clientY - pointer.y);
  if (!pointer.dragging && moved > CLICK_MOVE_PX) {
    pointer.dragging = true;
    // pan from where the press started so the map does not jump
    pointer.lx = pointer.x;
    pointer.ly = pointer.y;
  }
  if (!pointer.dragging) return;
  const dx = e.clientX - pointer.lx;
  const dy = e.clientY - pointer.ly;
  pointer.lx = e.clientX;
  pointer.ly = e.clientY;
  if (mode === 'world') {
    worldCamTouched = true;
    const k = cam.dist * 0.0032;
    cam.tx -= dx * k;
    cam.tz += dy * k;
  }
}

function isClick(e) {
  const moved = Math.hypot(e.clientX - pointer.x, e.clientY - pointer.y);
  const quick = performance.now() - (pointer.t0 || 0) < CLICK_QUICK_MS;
  return moved <= CLICK_MOVE_PX || (quick && moved <= CLICK_MOVE_QUICK_PX);
}

function onPointerUp(e) {
  if (!pointer.down || e.pointerId !== pointer.id) return;
  const click = isClick(e);
  pointer.down = false;
  pointer.dragging = false;
  if (mode === 'world' && click) {
    pointer.handled = selectCountry(pickAt(e.clientX, e.clientY));
    pointer.lastUpAt = performance.now();
  }
}

// Belt and braces: if a browser or input tool delivers a click without a
// matching pointerdown/pointerup pair on the map, still select.
function onMapClick(e) {
  if (mode !== 'world' || isUiTarget(e.target) || !ui.modal.hidden) return;
  if (performance.now() - (pointer.lastUpAt || 0) < 400) return;
  selectCountry(pickAt(e.clientX, e.clientY));
}

function onWheel(e) {
  e.preventDefault();
  if (mode === 'world') {
    worldCamTouched = true;
    cam.dist = clamp(cam.dist * (e.deltaY > 0 ? 1.08 : 0.92), 40, worldLimits.maxDist);
  } else if (mode === 'arena') {
    cam.dist = clamp(cam.dist * (e.deltaY > 0 ? 1.06 : 0.94), 14, 52);
  }
}

function bindJoystick() {
  const stick = ui.joystick;
  const nub = stick.querySelector('.nub');
  const setFrom = (cx, cy) => {
    const r = stick.getBoundingClientRect();
    const px = cx - (r.left + r.width / 2);
    const py = cy - (r.top + r.height / 2);
    const m = Math.hypot(px, py);
    const max = r.width * 0.36;
    const used = Math.min(m, max);
    const ang = Math.atan2(py, px);
    joy.x = (used / max) * Math.cos(ang);
    joy.y = (used / max) * Math.sin(ang);
    joy.active = true;
    nub.style.transform = `translate(${joy.x * max}px, ${joy.y * max}px)`;
  };
  const clear = () => {
    joy.active = false;
    joy.x = joy.y = 0;
    nub.style.transform = 'translate(0,0)';
  };
  stick.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    stick.setPointerCapture(e.pointerId);
    setFrom(e.clientX, e.clientY);
  });
  stick.addEventListener('pointermove', (e) => {
    if (!stick.hasPointerCapture(e.pointerId)) return;
    setFrom(e.clientX, e.clientY);
  });
  stick.addEventListener('pointerup', clear);
  stick.addEventListener('pointercancel', clear);
}

function fillSelectCard() {
  if (!selected) return;
  ui.selectCard.innerHTML = `
    <p class="kicker">${escapeHtml(selected.continent)}</p>
    <h2>${escapeHtml(selected.name)}</h2>
    <p>Walk your explorer, leave a trail, and loop back to your land to claim everything inside — just like the classic earth enclosure games.</p>
    <button class="btn primary" id="play-btn">Play</button>`;
  $('play-btn').addEventListener('click', () => beginPlay());
}

async function beginPlay() {
  if (!selected || !user) return;
  const btn = $('play-btn');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Entering…';
  }
  try {
    const res = await api.startMatch(selected.id);
    startArena(selected, res.match);
  } catch (err) {
    showNotice(err.message || 'Could not start the match.');
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Play';
    }
  }
}

function renderPodium() {
  podium = (podium || []).filter((entry) => entry && !entry.isBot);
  const slots = [podium[1], podium[0], podium[2]];
  const labels = ['2nd', 'Champion', '3rd'];
  const html = slots.map((entry, i) => {
    if (!entry) {
      return `<div class="stand s${i}"><div class="plinth"></div><p class="place">${labels[i]}</p><p class="empty-slot">Open</p></div>`;
    }
    return `<div class="stand s${i} filled">
      <div class="figure human"></div>
      <div class="plinth"></div>
      <p class="place">${labels[i]}</p>
      <p class="who">${escapeHtml(entry.displayName)}</p>
      <p class="pts">${entry.points} pts</p>
    </div>`;
  }).join('');
  ui.podiumList.innerHTML = html;
  ui.podiumEmpty.hidden = podium.length > 0;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
}

function showModal(html) {
  ui.modal.innerHTML = html;
  ui.modal.hidden = false;
}

function hideModal() {
  ui.modal.hidden = true;
  ui.modal.innerHTML = '';
}

function showNotice(msg) {
  const n = $('notice');
  n.hidden = false;
  n.textContent = msg;
  clearTimeout(showNotice._t);
  showNotice._t = setTimeout(() => { n.hidden = true; }, 3600);
}

function showAuth(kind = 'signup') {
  const signup = kind === 'signup';
  showModal(`
    <div class="card auth-card">
      <h2>${signup ? 'Create your explorer' : 'Welcome back'}</h2>
      <p class="lead">${signup ? 'Make an account to walk the flat world and claim countries. This is stored on the server — not just in this browser.' : 'Log in with the email you used to create your World Domination account.'}</p>
      <form id="auth-form">
        ${signup ? '<label>Display name<input name="name" maxlength="24" required autocomplete="nickname"></label>' : ''}
        <label>Email<input name="email" type="email" required autocomplete="username"></label>
        <label>Password<input name="password" type="password" minlength="8" required autocomplete="${signup ? 'new-password' : 'current-password'}"><small>At least 8 characters.</small></label>
        <p class="err" id="auth-err" hidden></p>
        <button class="btn primary" type="submit">${signup ? 'Create account' : 'Log in'}</button>
      </form>
      <p class="swap">${signup ? 'Already have an account?' : 'Need an account?'} <button type="button" class="link" id="auth-swap">${signup ? 'Log in' : 'Create one'}</button></p>
    </div>`);
  $('auth-swap').addEventListener('click', () => showAuth(signup ? 'login' : 'signup'));
  $('auth-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const err = $('auth-err');
    err.hidden = true;
    try {
      const res = signup
        ? await api.signup(String(fd.get('name') || ''), String(fd.get('email') || ''), String(fd.get('password') || ''))
        : await api.login(String(fd.get('email') || ''), String(fd.get('password') || ''));
      user = res.user;
      hideModal();
      afterLogin();
    } catch (ex) {
      err.hidden = false;
      err.textContent = ex.message || 'Could not complete that.';
    }
  });
}

function afterLogin() {
  ui.userSlot.innerHTML = `<span class="hello">Explorer <strong></strong></span><button class="link" id="logout-btn">Log out</button>`;
  ui.userSlot.querySelector('strong').textContent = user.name;
  $('logout-btn').addEventListener('click', async () => {
    try { await api.logout(); } catch { /* still leave */ }
    user = null;
    if (mode === 'arena') leaveArena(false);
    showAuth('login');
    ui.userSlot.innerHTML = '';
    ui.selectCard.hidden = true;
  });
  mode = 'world';
}

function bindUi() {
  ui = {
    userSlot: $('user-slot'),
    podium: $('podium'),
    podiumList: $('podium-list'),
    podiumEmpty: $('podium-empty'),
    selectCard: $('select-card'),
    arenaHud: $('arena-hud'),
    matchInfo: $('match-info'),
    joystick: $('joystick'),
    modal: $('modal'),
    controls: $('controls-guide'),
    tutorial: $('tutorial-banner'),
  };
  $('leave-btn').addEventListener('click', () => {
    if (mode !== 'arena') return;
    if (confirm('Leave this country? Your current match will be recorded as a retreat.')) {
      leaveArena(true);
    }
  });
  bindJoystick();
}

function bindInput() {
  window.addEventListener('keydown', (e) => {
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' '].includes(e.key)) e.preventDefault();
    keys.add(e.code);
    if (e.code === 'Escape' && mode === 'arena') $('leave-btn').click();
  });
  window.addEventListener('keyup', (e) => keys.delete(e.code));
  window.addEventListener('pointerdown', onPointerDown);
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp);
  window.addEventListener('pointercancel', resetPointer);
  window.addEventListener('blur', resetPointer);
  window.addEventListener('click', onMapClick);
  window.addEventListener('wheel', (e) => { if (!isUiTarget(e.target) && ui.modal.hidden) onWheel(e); }, { passive: false });
  window.addEventListener('resize', () => {
    resize();
    if (mode === 'world' && !worldCamTouched && !selected) fitWorldCamera();
  });
  const startAudio = () => { try { audioCtx = audioCtx || new AudioContext(); audioCtx.resume(); } catch { /* ignore */ } };
  window.addEventListener('pointerdown', startAudio, { once: true });
}

export async function boot() {
  canvas = $('stage');
  ctx = canvas.getContext('2d');
  bindUi();
  bindInput();
  resize();
  requestAnimationFrame(frame);
  try {
    const [session, world, board] = await Promise.all([
      api.loadSession(),
      api.loadCountries(),
      api.loadPodium().catch(() => ({ podium: [] })),
    ]);
    mapData = world;
    worldBounds = computeWorldBounds(world.countries || []);
    fitWorldCamera();
    mode = 'world';
    renderControls();
    $('attr-line').textContent = world.attribution || '';
    podium = board.podium || [];
    renderPodium();
    setupRequired = false;
    if (session.user) {
      user = session.user;
      afterLogin();
    } else {
      showAuth('signup');
    }
  } catch (err) {
    const dbDown = err && err.status === 503;
    showModal(`<div class="card"><h2>${dbDown ? 'Database not ready' : 'Could not load World Domination'}</h2><p>${escapeHtml(err.message || 'Network error')}</p>${dbDown ? '<p>There is no public installer. The host needs the existing Morse Chess <code>DB_*</code> contract, <code>WD_DB_*</code> environment variables, or a private guarded <code>app/.env</code>.</p>' : ''}</div>`);
  }
}

function renderControls() {
  const el = ui.controls;
  if (!el) return;
  const inArena = mode === 'arena' || mode === 'ending';
  document.body.classList.toggle('in-arena', inArena);
  if (window.innerWidth <= 760) {
    el.innerHTML = inArena
      ? `<h3>Controls</h3><p>${IS_TOUCH ? 'Drag the joystick (bottom left)' : '<kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> / arrows'} to walk. Leave your land and loop back to claim the inside.</p>`
      : `<h3>Controls</h3><p>${IS_TOUCH ? 'Drag to look around, tap a country, then <b>Play</b>.' : 'Drag or <kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> to look, click a country, then <b>Play</b>.'}</p>`;
    el.hidden = false;
    return;
  }
  const touch = IS_TOUCH
    ? '<li><kbd>Touch</kbd> drag the round joystick (bottom left)</li>'
    : '';
  if (mode === 'arena' || mode === 'ending') {
    el.innerHTML = `<h3>Controls</h3><ul>
      <li><kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> or <kbd>\u2191</kbd><kbd>\u2190</kbd><kbd>\u2193</kbd><kbd>\u2192</kbd> walk</li>
      ${touch}
      <li>Leave your land to draw a trail, come back to claim the inside</li>
      <li>Don't cross your own trail \u2014 bots can cut it too</li>
      <li><kbd>Scroll</kbd> zoom \u00b7 <kbd>Esc</kbd> leave country</li>
    </ul>`;
  } else {
    el.innerHTML = `<h3>Controls</h3><ul>
      <li><kbd>Drag</kbd> or <kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> / arrows to look around</li>
      <li>${IS_TOUCH ? '<kbd>Drag</kbd> with one finger to look around, <kbd>Tap</kbd> a country' : '<kbd>Scroll</kbd> to zoom'}</li>
      <li><kbd>Click</kbd> a country, then press <b>Play</b></li>
    </ul>`;
  }
  el.hidden = false;
}

function renderTutorial() {
  const el = ui.tutorial;
  if (!el) return;
  if (!match || mode === 'world' || tutorial.step === 'off') {
    el.hidden = true;
    return;
  }
  let html = '';
  if (tutorial.step === 'guide' && match.guideLoop) {
    const wp = match.guideLoop.waypoints[tutorial.waypoint];
    const touchHint = IS_TOUCH ? ` (or push the joystick ${wp.screen})` : '';
    html = `<span class="step">Step ${tutorial.waypoint + 1} of 4</span> ${escapeHtml(wp.label + touchHint)} <span class="flagnote">\u2014 follow the dotted path to the red flag</span>`;
  } else if (tutorial.step === 'captured') {
    html = `<strong>${escapeHtml(tutorial.gainText)}</strong>`;
  } else {
    html = 'Walk off your yellow land, then come back to it. Everything inside your loop becomes yours.';
  }
  el.innerHTML = html;
  el.hidden = false;
}

function updateTutorialProgress() {
  if (!match) return;
  if (tutorial.step === 'guide' && match.guideLoop) {
    const wp = match.guideLoop.waypoints[tutorial.waypoint];
    const p = match.player;
    if (wp && Math.hypot(p.x - wp.x, p.y - wp.y) < 1.4 && tutorial.waypoint < 3) {
      tutorial.waypoint++;
      renderTutorial();
    }
  } else if (tutorial.step === 'captured' && now - tutorial.doneAt > 6000) {
    tutorial.step = 'free';
    renderTutorial();
  }
}

function drawGuide(w, h) {
  if (!match || tutorial.step !== 'guide' || !match.guideLoop) return;
  const p0 = match.player;
  const pts = [{ x: match.guideLoop.waypoints[3].x, y: match.guideLoop.waypoints[3].y }, ...match.guideLoop.waypoints];
  ctx.save();
  ctx.setLineDash([10, 8]);
  ctx.lineDashOffset = -now * 0.03;
  ctx.lineWidth = 4;
  ctx.strokeStyle = 'rgba(255,255,255,0.92)';
  ctx.beginPath();
  let started = false;
  for (const q of pts) {
    const s = project(cam, q.x, 0.2, arenaZ(q.y), w, h);
    if (!s) continue;
    if (!started) {
      ctx.moveTo(s.x, s.y);
      started = true;
    } else ctx.lineTo(s.x, s.y);
  }
  ctx.stroke();
  ctx.setLineDash([]);
  match.guideLoop.waypoints.forEach((wp, i) => {
    const base = project(cam, wp.x, 0, arenaZ(wp.y), w, h);
    const tip = project(cam, wp.x, 2.2, arenaZ(wp.y), w, h);
    if (!base || !tip) return;
    const active = i === tutorial.waypoint;
    ctx.strokeStyle = '#3b2818';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(base.x, base.y);
    ctx.lineTo(tip.x, tip.y);
    ctx.stroke();
    ctx.fillStyle = active ? '#ff4d6d' : 'rgba(255,255,255,0.75)';
    ctx.beginPath();
    ctx.moveTo(tip.x, tip.y);
    ctx.lineTo(tip.x + 22, tip.y + 7);
    ctx.lineTo(tip.x, tip.y + 14);
    ctx.closePath();
    ctx.fill();
    if (active) {
      ctx.font = '800 15px Nunito, Trebuchet MS, sans-serif';
      ctx.textAlign = 'center';
      const label = wp.key;
      const tw = ctx.measureText(label).width;
      ctx.fillStyle = 'rgba(10,18,30,0.82)';
      roundRect(tip.x - tw / 2 - 8, tip.y - 30, tw + 16, 22, 8);
      ctx.fill();
      ctx.fillStyle = '#ffe27a';
      ctx.fillText(label, tip.x, tip.y - 14);
    }
  });
  void p0;
  ctx.restore();
}

if (typeof window !== 'undefined') {
  // Read-only snapshot for local QA scripts. Scores are still checked by the server.
  window.__wdDebug = () => ({
    mode,
    tutorial: { ...tutorial },
    guide: match && match.guideLoop ? match.guideLoop : null,
    player: match ? { x: match.player.x, y: match.player.y, dead: match.player.dead, trail: match.player.trail.length, pct: pctOf(match.player) } : null,
    standings: match ? standingsNow().map((s) => ({ name: s.actor.name, isBot: s.actor.isBot, pct: s.pct })) : [],
    captures: match ? match.captures.length : 0,
    heartbeats: match ? match.heartbeats.length : 0,
    cam: { ...cam },
    country: match ? match.country.id : (selected ? selected.id : null),
  });
}
