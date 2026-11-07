/** Geographic helpers for the Natural Earth country outlines. */

export function pointInRing(x, y, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; i++) {
    const xi = ring[i][0];
    const yi = ring[i][1];
    const xj = ring[j][0];
    const yj = ring[j][1];
    const denom = yj - yi || 1e-12;
    if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / denom + xi) {
      inside = !inside;
    }
    j = i;
  }
  return inside;
}

export function pointInArena(lng, lat, arena) {
  if (!arena || !pointInRing(lng, lat, arena.outer)) return false;
  const holes = arena.holes || [];
  for (const hole of holes) {
    if (pointInRing(lng, lat, hole)) return false;
  }
  return true;
}

export function ringArea(ring) {
  let a = 0;
  for (let i = 0; i < ring.length - 1; i++) {
    a += ring[i][0] * ring[i + 1][1] - ring[i + 1][0] * ring[i][1];
  }
  return Math.abs(a) * 0.5;
}

/** Smallest containing country so nested/coastal clicks pick the tighter outline. */
export function pickCountry(lng, lat, countries) {
  let best = null;
  let bestArea = Infinity;
  for (const country of countries) {
    for (const ring of country.overview) {
      if (pointInRing(lng, lat, ring)) {
        const area = ringArea(ring);
        if (area < bestArea) {
          bestArea = area;
          best = country;
        }
      }
    }
  }
  return best;
}

export function countryCentroid(country) {
  let sx = 0;
  let sy = 0;
  let n = 0;
  const rings = country.overview || [];
  for (const ring of rings) {
    for (const [x, y] of ring) {
      sx += x;
      sy += y;
      n++;
    }
  }
  if (!n && country.arena) {
    for (const [x, y] of country.arena.outer) {
      sx += x;
      sy += y;
      n++;
    }
  }
  return { lng: n ? sx / n : 0, lat: n ? sy / n : 0 };
}

/**
 * Rasterize the largest land polygon of a country into a grid.
 * Out-of-country cells are -2. Returns {grid, cols, rows, toLngLat, toCell}.
 */
export function rasterizeArena(arena, size) {
  const { OOB, EMPTY } = { OOB: -2, EMPTY: 0 };
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
  const inner = size - 4;
  const scale = inner / Math.max(bw, bh);
  const ox = 2 + (inner - bw * scale) / 2;
  const oy = 2 + (inner - bh * scale) / 2;
  const grid = [];
  for (let y = 0; y < size; y++) {
    const row = new Array(size);
    for (let x = 0; x < size; x++) {
      const lng = minX + (x + 0.5 - ox) / scale;
      const lat = maxY - (y + 0.5 - oy) / scale;
      row[x] = pointInArena(lng, lat, arena) ? EMPTY : OOB;
    }
    grid.push(row);
  }
  return {
    grid,
    size,
    toWorld(x, y) {
      return { x, z: y };
    },
  };
}
