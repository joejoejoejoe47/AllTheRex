// Arena terrain: one texture per match, built from the same playable grid
// the capture rules use, so the coastline you see is exactly where you can
// walk. Drawn onto the ground in perspective with small affine tiles.
import { project } from './camera.js';

const OOB = -2;
export const TEX_PX = 8; // texture pixels per grid cell

function hash2(x, y, seed) {
  let h = (x * 374761393 + y * 668265263 + seed * 2147483647) | 0;
  h = Math.imul(h ^ (h >>> 13), 1274126177);
  return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
}

function valueNoise(x, y, seed) {
  const xi = Math.floor(x);
  const yi = Math.floor(y);
  const xf = x - xi;
  const yf = y - yi;
  const s = (t) => t * t * (3 - 2 * t);
  const a = hash2(xi, yi, seed);
  const b = hash2(xi + 1, yi, seed);
  const c = hash2(xi, yi + 1, seed);
  const d = hash2(xi + 1, yi + 1, seed);
  const u = s(xf);
  const v = s(yf);
  return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
}

export function elevation(x, y, seed) {
  let e = 0;
  let amp = 1;
  let f = 1 / 26;
  for (let o = 0; o < 5; o++) {
    e += valueNoise(x * f, y * f, seed + o * 17) * amp;
    amp *= 0.5;
    f *= 2;
  }
  return e / 1.9375;
}

function hexToRgb(hex) {
  const v = parseInt(hex.replace('#', ''), 16);
  return [(v >> 16) & 255, (v >> 8) & 255, v & 255];
}

function lerp3(a, b, t) {
  return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t];
}

const LOW = [78, 140, 74];
const MID = [118, 158, 82];
const HIGH = [170, 150, 100];
const PEAK = [214, 206, 190];

/** Mapping from lng/lat to grid coordinates — identical to rasterizeArena. */
export function arenaTransform(arena, size) {
  let minX = Infinity;
  let minY = Infinity;
  let maxX = -Infinity;
  let maxY = -Infinity;
  for (const [x, y] of arena.outer) {
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
  const inner = size - 4;
  const scale = inner / Math.max(bw, bh);
  const ox = 2 + (inner - bw * scale) / 2;
  const oy = 2 + (inner - bh * scale) / 2;
  return {
    toGrid: (lng, lat) => [ox + (lng - minX) * scale, oy + (maxY - lat) * scale],
    bbox: { minX: minX - bw, maxX: maxX + bw, minY: minY - bh, maxY: maxY + bh },
  };
}

/**
 * @param {number[][]} grid playable grid (OOB = outside)
 * @param {object} country selected country (arena, color, id)
 * @param {object[]} countries all countries (for neighbours)
 */
export function buildArenaTexture(grid, country, countries) {
  const rows = grid.length;
  const cols = grid[0].length;
  const S = TEX_PX;
  const seed = [...String(country.id)].reduce((a, c) => a * 31 + c.charCodeAt(0), 7) & 0xffff;
  const tex = document.createElement('canvas');
  tex.width = cols * S;
  tex.height = rows * S;
  const g = tex.getContext('2d');

  // 1. sea with gentle depth variation and ripples
  const sea = g.createLinearGradient(0, 0, 0, tex.height);
  sea.addColorStop(0, '#2a86c9');
  sea.addColorStop(1, '#1b6aa8');
  g.fillStyle = sea;
  g.fillRect(0, 0, tex.width, tex.height);
  g.strokeStyle = 'rgba(255,255,255,0.08)';
  g.lineWidth = 1.2;
  for (let i = 0; i < 260; i++) {
    const x = hash2(i, 1, seed) * tex.width;
    const y = hash2(i, 2, seed) * tex.height;
    const l = 8 + hash2(i, 3, seed) * 18;
    g.beginPath();
    g.moveTo(x, y);
    g.quadraticCurveTo(x + l / 2, y - 3, x + l, y);
    g.stroke();
  }

  // 2. neighbouring countries as muted, unplayable land
  const tf = arenaTransform(country.arena, cols);
  const bb = tf.bbox;
  g.lineJoin = 'round';
  for (const other of countries) {
    if (other.id === country.id) continue;
    for (const ring of other.overview || []) {
      let hit = false;
      for (const [x, y] of ring) {
        if (x >= bb.minX && x <= bb.maxX && y >= bb.minY && y <= bb.maxY) { hit = true; break; }
      }
      if (!hit) continue;
      g.beginPath();
      ring.forEach(([lng, lat], i) => {
        const [gx, gy] = tf.toGrid(lng, lat);
        if (i) g.lineTo(gx * S, gy * S); else g.moveTo(gx * S, gy * S);
      });
      g.closePath();
      g.fillStyle = '#b9ad8c';
      g.fill();
      g.strokeStyle = 'rgba(70,55,30,0.55)';
      g.lineWidth = 1.5;
      g.stroke();
    }
  }

  // 3. playable land: shaded terrain, clipped to the exact grid cells
  const small = document.createElement('canvas');
  small.width = cols;
  small.height = rows;
  const sg = small.getContext('2d');
  const img = sg.createImageData(cols, rows);
  const tint = hexToRgb(country.color || '#7fb069');
  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      const e = elevation(x, y, seed);
      const ex = elevation(x + 1, y, seed) - elevation(x - 1, y, seed);
      const ey = elevation(x, y + 1, seed) - elevation(x, y - 1, seed);
      const shade = Math.max(-1, Math.min(1, (-ex + ey) * 9));
      let c = e < 0.5 ? lerp3(LOW, MID, e / 0.5) : e < 0.68 ? lerp3(MID, HIGH, (e - 0.5) / 0.18) : lerp3(HIGH, PEAK, Math.min(1, (e - 0.68) / 0.16));
      c = lerp3(c, tint, 0.12);
      const k = 1 + shade * 0.16;
      const i = (y * cols + x) * 4;
      img.data[i] = Math.min(255, c[0] * k);
      img.data[i + 1] = Math.min(255, c[1] * k);
      img.data[i + 2] = Math.min(255, c[2] * k);
      img.data[i + 3] = 255;
    }
  }
  sg.putImageData(img, 0, 0);

  const land = new Path2D();
  for (let y = 0; y < rows; y++) {
    let x = 0;
    while (x < cols) {
      if (grid[y][x] === OOB) { x++; continue; }
      let x2 = x + 1;
      while (x2 < cols && grid[y][x2] !== OOB) x2++;
      land.rect(x * S, y * S, (x2 - x) * S, S);
      x = x2;
    }
  }
  const coast = new Path2D();
  for (let y = 0; y <= rows; y++) {
    for (let x = 0; x <= cols; x++) {
      const here = y < rows && x < cols && grid[y][x] !== OOB;
      const up = y > 0 && x < cols && grid[y - 1][x] !== OOB;
      const left = x > 0 && y < rows && grid[y][x - 1] !== OOB;
      if (x < cols && here !== up) { coast.moveTo(x * S, y * S); coast.lineTo((x + 1) * S, y * S); }
      if (y < rows && here !== left) { coast.moveTo(x * S, y * S); coast.lineTo(x * S, (y + 1) * S); }
    }
  }
  // shallow water glow, then the land itself
  g.strokeStyle = 'rgba(150,215,255,0.45)';
  g.lineWidth = S * 2.2;
  g.stroke(coast);
  g.save();
  g.clip(land);
  g.imageSmoothingEnabled = true;
  g.drawImage(small, 0, 0, cols * S, rows * S);
  // fine grain: grass tufts and pebbles so the ground never looks flat
  for (let i = 0; i < cols * rows * 0.9; i++) {
    const x = hash2(i, 11, seed) * cols * S;
    const y = hash2(i, 12, seed) * rows * S;
    const r = hash2(i, 13, seed);
    g.fillStyle = r < 0.5 ? 'rgba(20,60,20,0.16)' : 'rgba(255,255,220,0.12)';
    g.fillRect(x, y, 1 + (r * 2.2 | 0), 1 + (r * 1.6 | 0));
  }
  // faint cell grid so distances read at a glance
  g.strokeStyle = 'rgba(0,0,0,0.05)';
  g.lineWidth = 1;
  g.beginPath();
  for (let x = 0; x <= cols; x += 2) { g.moveTo(x * S + 0.5, 0); g.lineTo(x * S + 0.5, rows * S); }
  for (let y = 0; y <= rows; y += 2) { g.moveTo(0, y * S + 0.5); g.lineTo(cols * S, y * S + 0.5); }
  g.stroke();
  g.restore();
  // beach + border line exactly on the playable edge
  g.strokeStyle = '#efe1b0';
  g.lineWidth = 3;
  g.stroke(coast);
  g.strokeStyle = 'rgba(52,36,12,0.85)';
  g.lineWidth = 1.4;
  g.stroke(coast);

  return { canvas: tex, small, S, cols, rows };
}

function affineTri(ctx, img, s0, s1, s2, t0, t1, t2) {
  const x0 = t0[0], y0 = t0[1], x1 = t1[0], y1 = t1[1], x2 = t2[0], y2 = t2[1];
  const det = (x1 - x0) * (y2 - y0) - (x2 - x0) * (y1 - y0);
  if (Math.abs(det) < 1e-9) return;
  const a = ((s1.x - s0.x) * (y2 - y0) - (s2.x - s0.x) * (y1 - y0)) / det;
  const c = ((s2.x - s0.x) * (x1 - x0) - (s1.x - s0.x) * (x2 - x0)) / det;
  const b = ((s1.y - s0.y) * (y2 - y0) - (s2.y - s0.y) * (y1 - y0)) / det;
  const d = ((s2.y - s0.y) * (x1 - x0) - (s1.y - s0.y) * (x2 - x0)) / det;
  const e = s0.x - a * x0 - c * y0;
  const f = s0.y - b * x0 - d * y0;
  // grow the clip a little so neighbouring tiles overlap (no seams)
  const cx = (s0.x + s1.x + s2.x) / 3;
  const cy = (s0.y + s1.y + s2.y) / 3;
  const grow = (p) => {
    const dx = p.x - cx;
    const dy = p.y - cy;
    const l = Math.hypot(dx, dy) || 1;
    return [p.x + (dx / l) * 0.9, p.y + (dy / l) * 0.9];
  };
  ctx.save();
  ctx.beginPath();
  ctx.moveTo(...grow(s0));
  ctx.lineTo(...grow(s1));
  ctx.lineTo(...grow(s2));
  ctx.closePath();
  ctx.clip();
  ctx.transform(a, b, c, d, e, f);
  ctx.drawImage(img, 0, 0);
  ctx.restore();
}

/**
 * Draw the texture on the ground plane. zOf maps grid row -> world z.
 * Returns the number of tiles drawn (for perf checks).
 */
export function drawArenaTexture(ctx, cam, tex, w, h, zOf) {
  const { canvas, S, cols, rows } = tex;
  let drawn = 0;
  const T = 8;
  const corner = (x, y) => project(cam, x, 0, zOf(y), w, h);
  const visit = (x0, y0, size, depth) => {
    const x1 = Math.min(cols, x0 + size);
    const y1 = Math.min(rows, y0 + size);
    const p00 = corner(x0, y0);
    const p10 = corner(x1, y0);
    const p11 = corner(x1, y1);
    const p01 = corner(x0, y1);
    if (!p00 || !p10 || !p11 || !p01) {
      if (depth < 2 && size > 2) {
        const hs = size / 2;
        visit(x0, y0, hs, depth + 1); visit(x0 + hs, y0, hs, depth + 1);
        visit(x0, y0 + hs, hs, depth + 1); visit(x0 + hs, y0 + hs, hs, depth + 1);
      }
      return;
    }
    const minX = Math.min(p00.x, p10.x, p11.x, p01.x);
    const maxX = Math.max(p00.x, p10.x, p11.x, p01.x);
    const minY = Math.min(p00.y, p10.y, p11.y, p01.y);
    const maxY = Math.max(p00.y, p10.y, p11.y, p01.y);
    if (maxX < -4 || minX > w + 4 || maxY < -4 || minY > h + 4) return;
    if ((maxX - minX > 260 || maxY - minY > 200) && depth < 2 && size > 2) {
      const hs = size / 2;
      visit(x0, y0, hs, depth + 1); visit(x0 + hs, y0, hs, depth + 1);
      visit(x0, y0 + hs, hs, depth + 1); visit(x0 + hs, y0 + hs, hs, depth + 1);
      return;
    }
    const t00 = [x0 * S, y0 * S];
    const t10 = [x1 * S, y0 * S];
    const t11 = [x1 * S, y1 * S];
    const t01 = [x0 * S, y1 * S];
    affineTri(ctx, canvas, p00, p10, p11, t00, t10, t11);
    affineTri(ctx, canvas, p00, p11, p01, t00, t11, t01);
    drawn++;
  };
  for (let y = 0; y < rows; y += T) {
    for (let x = 0; x < cols; x += T) visit(x, y, T, 0);
  }
  return drawn;
}
