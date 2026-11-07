import { attemptCapture, OOB, EMPTY, playableCount, ownedCount } from './capture.js';
import { pickCountry, countryCentroid, rasterizeArena } from './geo.js';
import * as api from './api.js';

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
const pointer = { down: false, id: null, x: 0, y: 0, lx: 0, ly: 0, dragging: false };
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
let cam = { tx: 18, tz: 32, dist: 105, pitch: 0.95, yaw: 0.08, fov: 0.92 };
let match = null;
let flash = 0;
let hintT = 6;
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

function cameraPos(c) {
  const cp = Math.cos(c.pitch);
  const sp = Math.sin(c.pitch);
  return {
    x: c.tx + Math.sin(c.yaw) * cp * c.dist,
    y: sp * c.dist + 6,
    z: c.tz - Math.cos(c.yaw) * cp * c.dist,
  };
}

function cameraBasis(c) {
  const p = cameraPos(c);
  const lx = c.tx - p.x;
  const ly = 0 - p.y;
  const lz = c.tz - p.z;
  const fl = Math.hypot(lx, ly, lz) || 1;
  const fx = lx / fl;
  const fy = ly / fl;
  const fz = lz / fl;
  let rx = fz;
  let ry = 0;
  let rz = -fx;
  const rl = Math.hypot(rx, ry, rz) || 1;
  rx /= rl;
  rz /= rl;
  const ux = ry * fz - rz * fy;
  const uy = rz * fx - rx * fz;
  const uz = rx * fy - ry * fx;
  return { p, fx, fy, fz, rx, ry, rz, ux, uy, uz };
}

function project(c, x, y, z, w, h) {
  const b = cameraBasis(c);
  const dx = x - b.p.x;
  const dy = y - b.p.y;
  const dz = z - b.p.z;
  const vx = dx * b.rx + dy * b.ry + dz * b.rz;
  const vy = dx * b.ux + dy * b.uy + dz * b.uz;
  const vz = dx * b.fx + dy * b.fy + dz * b.fz;
  if (vz < 0.55) return null;
  const s = (h * 0.62) / (Math.tan(c.fov * 0.5) * vz);
  return { x: w * 0.5 + vx * s, y: h * 0.56 - vy * s, z: vz };
}

function unproject(c, sx, sy, w, h) {
  const b = cameraBasis(c);
  const s0 = (h * 0.62) / Math.tan(c.fov * 0.5);
  const vx = (sx - w * 0.5) / s0;
  const vy = (h * 0.56 - sy) / s0;
  const vz = 1;
  const dx = vx * b.rx + vy * b.ux + vz * b.fx;
  const dy = vx * b.ry + vy * b.uy + vz * b.fy;
  const dz = vx * b.rz + vy * b.uz + vz * b.fz;
  if (Math.abs(dy) < 1e-6) return null;
  const t = -b.p.y / dy;
  if (t <= 0) return null;
  return { x: b.p.x + dx * t, z: b.p.z + dz * t };
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
  g.addColorStop(0, '#49b7ff');
  g.addColorStop(0.42, '#7ec8ff');
  g.addColorStop(0.58, '#cfe9ff');
  g.addColorStop(1, '#f2d9a0');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, w, h);
  const sun = { x: w * 0.78, y: h * 0.16 };
  const sg = ctx.createRadialGradient(sun.x, sun.y, 8, sun.x, sun.y, 90);
  sg.addColorStop(0, 'rgba(255,244,180,0.95)');
  sg.addColorStop(0.35, 'rgba(255,220,80,0.45)');
  sg.addColorStop(1, 'rgba(255,220,80,0)');
  ctx.fillStyle = sg;
  ctx.beginPath();
  ctx.arc(sun.x, sun.y, 90, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = '#fff4b8';
  ctx.beginPath();
  ctx.arc(sun.x, sun.y, 22, 0, Math.PI * 2);
  ctx.fill();
  // soft clouds
  ctx.fillStyle = 'rgba(255,255,255,0.28)';
  for (const cl of [
    [w * 0.18, h * 0.14, 50],
    [w * 0.42, h * 0.1, 36],
    [w * 0.12, h * 0.2, 28],
  ]) {
    ctx.beginPath();
    ctx.ellipse(cl[0], cl[1], cl[2], cl[2] * 0.38, 0, 0, Math.PI * 2);
    ctx.fill();
  }
}

function drawOcean(c, w, h) {
  const ring = [
    [-200, -110],
    [200, -110],
    [200, 110],
    [-200, 110],
  ];
  if (fillProjected(c, ring, w, h, -0.4)) {
    const g = ctx.createLinearGradient(0, h * 0.4, 0, h);
    g.addColorStop(0, '#1b6fa8');
    g.addColorStop(1, '#0d3f6b');
    ctx.fillStyle = g;
    ctx.fill();
  }
}

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
  drawOcean(cam, w, h);
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
  const playerSpawn = findSpawn(grid);
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
  };
  cam.tx = player.x;
  cam.tz = player.y;
  cam.dist = 36;
  cam.pitch = 1.02;
  cam.yaw = 0.15;
  mode = 'arena';
  hintT = 7;
  ui.arenaHud.hidden = false;
  ui.selectCard.hidden = true;
  ui.joystick.hidden = !(('ontouchstart' in window) || (navigator.maxTouchPoints > 0));
  updateArenaHud(0);
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
  const result = attemptCapture(match.grid, actor.id, actor.trail);
  actor.trail = [];
  if (result.ok) {
    match.grid = result.grid;
    if (result.captured > 0) {
      flash = 0.28;
      if (!actor.isBot) sfx('capture');
    }
  }
}

function processCell(actor, cx, cy) {
  const owner = cellOwner(cx + 0.5, cy + 0.5);
  if (owner === OOB) return false;
  for (const other of match.actors) {
    if (other.dead) continue;
    if (trailHas(other, cx, cy, other === actor ? TAIL_SAFE : 1)) {
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
    if (cellOwner(nx, actor.y) !== OOB) actor.x = nx;
    else if (cellOwner(actor.x, ny) !== OOB) actor.y = ny;
    return;
  }
  const x0 = actor.x;
  const y0 = actor.y;
  const steps = Math.max(Math.abs(Math.floor(nx) - Math.floor(x0)), Math.abs(Math.floor(ny) - Math.floor(y0)), 1);
  for (let i = 1; i <= steps; i++) {
    const t = i / steps;
    const x = x0 + (nx - x0) * t;
    const y = y0 + (ny - y0) * t;
    if (!processCell(actor, Math.floor(x), Math.floor(y))) {
      return;
    }
    if (actor.dead) return;
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
    } else if (nearPlayerTrail && player.trail.length > 4) {
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
  const scores = match.actors.map((a) => ({ actor: a, pct: pctOf(a) }));
  for (const s of scores) s.actor.peakPct = Math.max(s.actor.peakPct, s.pct);
  const leader = scores.reduce((a, b) => (b.pct > a.pct ? b : a));
  let outcome = null;
  if (pctOf(match.player) >= match.winPct) outcome = 'win';
  else if (leader.actor !== match.player && leader.pct >= match.winPct) outcome = 'lose';
  else if (elapsed >= match.durationMs) {
    outcome = leader.actor === match.player ? 'win' : 'timeout';
  }
  if (outcome) finishArena(outcome);
}

async function finishArena(outcome) {
  if (!match || match.ending) return;
  match.ending = true;
  mode = 'ending';
  const playerPct = pctOf(match.player);
  const bots = match.actors.filter((a) => a.isBot).map((a) => ({
    slug: a.slug,
    name: a.name,
    pct: +pctOf(a).toFixed(2),
    outcome: pctOf(a) >= match.winPct && outcome !== 'win' ? 'win' : 'lose',
  }));
  if (outcome === 'win') sfx('win');
  let saved = null;
  let saveError = '';
  try {
    saved = await api.finishMatch({
      token: match.token,
      country: match.country.id,
      playerPct: +playerPct.toFixed(2),
      outcome: outcome === 'win' ? 'win' : outcome === 'timeout' ? 'timeout' : outcome,
      durationMs: Math.round(now - match.startedAt),
      bots,
    });
    podium = saved.podium || podium;
    renderPodium();
  } catch (err) {
    saveError = err.message || 'Could not save this result.';
  }
  const title = outcome === 'win'
    ? `You dominate ${match.country.name}!`
    : outcome === 'timeout'
      ? `Time! You held ${playerPct.toFixed(1)}% of ${match.country.name}`
      : `${leaderName()} claimed ${match.country.name}`;
  showModal(`
    <div class="card end-card">
      <h2>${escapeHtml(title)}</h2>
      <p class="lead">Your territory: <strong>${playerPct.toFixed(1)}%</strong></p>
      <ul class="end-bots">${match.actors.map((a) => `<li><span class="swatch" style="background:${a.color}"></span>${escapeHtml(a.name)}${a.isBot ? ' <em>bot</em>' : ''} — ${pctOf(a).toFixed(1)}%</li>`).join('')}</ul>
      ${saveError ? `<p class="err">${escapeHtml(saveError)}</p>` : '<p class="ok">Result saved to the world podium.</p>'}
      <button class="btn primary" data-close-world>Back to the world</button>
    </div>`);
  ui.modal.querySelector('[data-close-world]').addEventListener('click', () => {
    hideModal();
    leaveArena(false);
  });
}

function leaderName() {
  if (!match) return 'Someone';
  return match.actors.reduce((a, b) => (pctOf(b) > pctOf(a) ? b : a)).name;
}

function leaveArena(submitQuit) {
  if (submitQuit && match && !match.ending) {
    finishArena('quit');
    return;
  }
  match = null;
  mode = 'world';
  cam = { tx: selected ? countryCentroid(selected).lng : 18, tz: selected ? countryCentroid(selected).lat : 32, dist: 90, pitch: 0.95, yaw: 0.08, fov: 0.92 };
  ui.arenaHud.hidden = true;
  ui.joystick.hidden = true;
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
  const rows = match.actors.map((a) => {
    const pct = pctOf(a);
    return `<div class="bar-row ${a === match.player ? 'you' : ''}">
      <span class="who"><i style="background:${a.color}"></i>${escapeHtml(a.name)}${a.isBot ? '<em>bot</em>' : ''}</span>
      <span class="pct">${pct.toFixed(1)}%</span>
      <span class="track"><span style="width:${Math.min(100, pct)}%;background:${a.color}"></span></span>
    </div>`;
  }).join('');
  ui.matchInfo.innerHTML = `<div class="match-top"><strong>${escapeHtml(match.country.name)}</strong><span>Claim ${match.winPct}% · ${mm}:${ss}</span></div>${rows}`;
}

function renderArena(dt) {
  const { w, h } = viewSize();
  drawSky(w, h);
  // follow camera
  const p = match.player;
  cam.tx += (p.x - cam.tx) * Math.min(1, dt * 4);
  cam.tz += (p.y - cam.tz) * Math.min(1, dt * 4);
  drawArenaGround(w, h);
  drawTerritories(w, h);
  for (const actor of match.actors) drawTrail(actor, w, h);
  const sorted = match.actors.slice().sort((a, b) => b.y - a.y);
  for (const actor of sorted) {
    if (actor.dead) continue;
    drawGuy(actor, w, h);
  }
  if (flash > 0) {
    ctx.fillStyle = `rgba(255,230,120,${flash * 0.35})`;
    ctx.fillRect(0, 0, w, h);
    flash = Math.max(0, flash - dt);
  }
  if (hintT > 0) {
    hintT -= dt;
    ctx.save();
    ctx.globalAlpha = Math.min(1, hintT);
    ctx.fillStyle = 'rgba(8,24,40,0.55)';
    ctx.fillRect(w / 2 - 240, 86, 480, 40);
    ctx.fillStyle = '#fff8dc';
    ctx.font = '600 15px Nunito, Trebuchet MS, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Walk out, loop back to your land, and everything inside becomes yours.', w / 2, 112);
    ctx.restore();
  }
}

function drawArenaGround(w, h) {
  const g = match.grid;
  const corners = [
    [0, 0],
    [g[0].length, 0],
    [g[0].length, g.length],
    [0, g.length],
  ];
  if (fillProjected(cam, corners, w, h, -0.15)) {
    ctx.fillStyle = '#14608f';
    ctx.fill();
  }
  // land silhouette
  const step = 1;
  ctx.beginPath();
  let started = false;
  // Approximate by drawing all land as merged scan spans on a low mesh: project country arena ring in grid space
  const ring = match.country.arena.outer;
  const pts = ringToGrid(ring, match.country.arena);
  if (fillProjected(cam, pts, w, h, 0)) {
    ctx.fillStyle = match.country.color;
    ctx.fill();
    ctx.strokeStyle = 'rgba(40,28,8,0.65)';
    ctx.lineWidth = 2;
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
        [x, y],
        [x2, y],
        [x2, y + 1],
        [x, y + 1],
      ];
      if (fillProjected(cam, quad, w, h, 0.04)) {
        ctx.fillStyle = actor ? actor.color + 'cc' : 'rgba(255,255,255,0.35)';
        ctx.fill();
      }
      x = x2;
    }
  }
}

function drawTrail(actor, w, h) {
  if (!actor.trail.length) return;
  ctx.beginPath();
  let started = false;
  for (const [x, y] of actor.trail) {
    const p = project(cam, x + 0.5, 0.12, y + 0.5, w, h);
    if (!p) continue;
    if (!started) {
      ctx.moveTo(p.x, p.y);
      started = true;
    } else ctx.lineTo(p.x, p.y);
  }
  const head = project(cam, actor.x, 0.12, actor.y, w, h);
  if (head && started) ctx.lineTo(head.x, head.y);
  if (!started) return;
  ctx.strokeStyle = actor.color;
  ctx.lineWidth = 3.4;
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  ctx.stroke();
}

function drawGuy(actor, w, h) {
  const p = project(cam, actor.x, 0.2, actor.y, w, h);
  if (!p) return;
  const s = clamp(220 / (p.z + 6), 8, 26);
  const bob = Math.sin(now * 0.012 + actor.id) * (actor.trail.length ? 1.2 : 0.4);
  ctx.fillStyle = 'rgba(0,0,0,0.28)';
  ctx.beginPath();
  ctx.ellipse(p.x, p.y + s * 0.28, s * 0.42, s * 0.16, 0, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = mixHex(actor.color, '#000000', 0.15);
  roundRect(p.x - s * 0.28, p.y - s * 0.15 + bob, s * 0.56, s * 0.5, s * 0.16);
  ctx.fill();
  ctx.fillStyle = actor.color;
  ctx.beginPath();
  ctx.arc(p.x, p.y - s * 0.28 + bob, s * 0.28, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = '#1a1208';
  const ex = actor.dir.x * s * 0.08;
  ctx.beginPath();
  ctx.arc(p.x - s * 0.08 + ex, p.y - s * 0.3 + bob, s * 0.05, 0, Math.PI * 2);
  ctx.arc(p.x + s * 0.1 + ex, p.y - s * 0.3 + bob, s * 0.05, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = 'rgba(10,20,30,0.78)';
  ctx.font = `700 ${Math.max(11, s * 0.55)}px Nunito, Trebuchet MS, sans-serif`;
  ctx.textAlign = 'center';
  ctx.fillText(actor.isBot ? actor.name : actor.name, p.x, p.y - s * 0.85);
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
    const yaw = cam.yaw;
    const fx = Math.sin(yaw);
    const fz = Math.cos(yaw);
    const rx = Math.cos(yaw);
    const rz = -Math.sin(yaw);
    cam.tx += ((rx * ix + fx * iz) / m) * speed * dt;
    cam.tz += ((rz * ix + fz * iz) / m) * speed * dt;
  }
  cam.tx = clamp(cam.tx, -170, 170);
  cam.tz = clamp(cam.tz, -80, 85);
  cam.dist = clamp(cam.dist, 28, 230);
}

function updateArena(dt) {
  for (const actor of match.actors) {
    if (actor.dead && now >= actor.deadUntil) respawn(actor);
  }
  const player = match.player;
  if (!player.dead) {
    const mv = readMoveVector();
    if (mv) {
      player.dir = mv;
      tryMove(player, player.x + mv.x * SPEED * dt, player.y + mv.y * SPEED * dt);
    }
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

function pickAt(sx, sy) {
  const { w, h } = viewSize();
  const hit = unproject(cam, sx, sy, w, h);
  if (!hit) return null;
  return pickCountry(hit.x, hit.z, mapData.countries);
}

function onPointerDown(e) {
  if (e.target.closest && e.target.closest('#joystick,.card,button,input,aside,a')) return;
  pointer.down = true;
  pointer.id = e.pointerId;
  pointer.x = pointer.lx = e.clientX;
  pointer.y = pointer.ly = e.clientY;
  pointer.dragging = false;
  try { canvas.setPointerCapture(e.pointerId); } catch { /* ignore */ }
}

function onPointerMove(e) {
  if (!pointer.down || e.pointerId !== pointer.id) {
    if (mode === 'world' && !pointer.down) {
      hover = pickAt(e.clientX, e.clientY);
      canvas.style.cursor = hover ? 'pointer' : 'grab';
    }
    return;
  }
  const dx = e.clientX - pointer.lx;
  const dy = e.clientY - pointer.ly;
  pointer.lx = e.clientX;
  pointer.ly = e.clientY;
  if (Math.hypot(e.clientX - pointer.x, e.clientY - pointer.y) > 6) pointer.dragging = true;
  if (mode === 'world') {
    const k = cam.dist * 0.0032;
    cam.tx -= dx * k;
    cam.tz += dy * k;
  }
}

function onPointerUp(e) {
  if (e.pointerId !== pointer.id) return;
  const wasDrag = pointer.dragging;
  pointer.down = false;
  if (mode === 'world' && !wasDrag) {
    const hit = pickAt(e.clientX, e.clientY);
    if (hit) {
      selected = hit;
      fillSelectCard();
      ui.selectCard.hidden = false;
      sfx('select');
    }
  }
}

function onWheel(e) {
  e.preventDefault();
  if (mode === 'world') {
    cam.dist = clamp(cam.dist * (e.deltaY > 0 ? 1.08 : 0.92), 28, 230);
  } else if (mode === 'arena') {
    cam.dist = clamp(cam.dist * (e.deltaY > 0 ? 1.06 : 0.94), 18, 70);
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
  const slots = [podium[1], podium[0], podium[2]];
  const labels = ['2nd', 'Champion', '3rd'];
  const html = slots.map((entry, i) => {
    if (!entry) {
      return `<div class="stand s${i}"><div class="plinth"></div><p class="place">${labels[i]}</p><p class="empty-slot">Open</p></div>`;
    }
    return `<div class="stand s${i} filled">
      <div class="figure ${entry.isBot ? 'bot' : 'human'}"></div>
      <div class="plinth"></div>
      <p class="place">${labels[i]}</p>
      <p class="who">${escapeHtml(entry.displayName)}${entry.isBot ? '<em>bot</em>' : ''}</p>
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
  canvas.addEventListener('pointerdown', onPointerDown);
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp);
  canvas.addEventListener('wheel', onWheel, { passive: false });
  window.addEventListener('resize', resize);
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
    mode = 'world';
    $('attr-line').textContent = world.attribution || '';
    podium = board.podium || [];
    renderPodium();
    setupRequired = !!session.setupRequired;
    if (setupRequired) {
      showModal(`<div class="card"><h2>Almost ready</h2><p>The world map is here, but the score-keeping database still needs a one-time setup on the server (<code>install.php</code>). You can look around locally once a database is configured.</p></div>`);
    }
    if (session.user) {
      user = session.user;
      afterLogin();
    } else if (!setupRequired) {
      showAuth('signup');
    }
  } catch (err) {
    showModal(`<div class="card"><h2>Could not load World Domination</h2><p>${escapeHtml(err.message || 'Network error')}</p></div>`);
  }
}
