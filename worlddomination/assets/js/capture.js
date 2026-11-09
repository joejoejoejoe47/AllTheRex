/** Grid territory capture. Keep in sync with app/src/Capture.php */

export const OOB = -2;
export const EMPTY = 0;

function cloneGrid(grid) {
  return grid.map((row) => row.slice());
}

function adjacentToOwner(grid, x, y, ownerId) {
  const rows = grid.length;
  const cols = rows ? grid[0].length : 0;
  const dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];
  for (const [dx, dy] of dirs) {
    const nx = x + dx;
    const ny = y + dy;
    if (nx >= 0 && ny >= 0 && nx < cols && ny < rows && grid[ny][nx] === ownerId) {
      return true;
    }
  }
  return false;
}

/**
 * @param {number[][]} grid
 * @param {number} ownerId
 * @param {Array<[number, number]>} trail
 */
export function attemptCapture(grid, ownerId, trail) {
  const rows = grid.length;
  const cols = rows ? grid[0].length : 0;
  const copied = cloneGrid(grid);
  if (!trail || trail.length === 0) {
    return { ok: false, reason: 'empty-trail', captured: 0, grid: copied };
  }
  for (const [x, y] of trail) {
    if (x < 0 || y < 0 || x >= cols || y >= rows || grid[y][x] === OOB) {
      return { ok: false, reason: 'out-of-bounds-trail', captured: 0, grid: copied };
    }
  }
  const first = trail[0];
  const last = trail[trail.length - 1];
  if (!adjacentToOwner(grid, first[0], first[1], ownerId)) {
    return { ok: false, reason: 'start-not-connected', captured: 0, grid: copied };
  }
  if (!adjacentToOwner(grid, last[0], last[1], ownerId)) {
    return { ok: false, reason: 'end-not-connected', captured: 0, grid: copied };
  }

  const trailSet = new Set(trail.map(([x, y]) => `${y},${x}`));
  const visited = Array.from({ length: rows }, () => Array(cols).fill(false));
  const queue = [];
  let head = 0;
  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      if (grid[y][x] === OOB) {
        visited[y][x] = true;
        queue.push([x, y]);
      }
    }
  }
  const dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];
  while (head < queue.length) {
    const [x, y] = queue[head++];
    for (const [dx, dy] of dirs) {
      const nx = x + dx;
      const ny = y + dy;
      if (nx < 0 || ny < 0 || nx >= cols || ny >= rows || visited[ny][nx]) continue;
      if (grid[ny][nx] === ownerId || trailSet.has(`${ny},${nx}`)) continue;
      visited[ny][nx] = true;
      queue.push([nx, ny]);
    }
  }

  let captured = 0;
  const next = cloneGrid(grid);
  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      if (grid[y][x] === OOB) continue;
      const isTrail = trailSet.has(`${y},${x}`);
      if (isTrail || !visited[y][x]) {
        if (next[y][x] !== ownerId) {
          next[y][x] = ownerId;
          captured++;
        }
      }
    }
  }
  return { ok: true, captured, grid: next };
}

/** @param {Array<[number, number]>} trail */
export function trailSelfCrosses(trail, ignoreTail = 3) {
  const seen = new Map();
  for (let i = 0; i < trail.length; i++) {
    const key = `${trail[i][1]},${trail[i][0]}`;
    if (seen.has(key)) {
      if (i - seen.get(key) > ignoreTail) return true;
    } else {
      seen.set(key, i);
    }
  }
  return false;
}

export function playableCount(grid) {
  let n = 0;
  for (const row of grid) {
    for (const cell of row) if (cell !== OOB) n++;
  }
  return n;
}

export function ownedCount(grid, ownerId) {
  let n = 0;
  for (const row of grid) {
    for (const cell of row) if (cell === ownerId) n++;
  }
  return n;
}

/**
 * Grid cells entered when moving from (x0, y0) to (x1, y1), in order and
 * always 4-connected: a diagonal step is split through a side cell (the one
 * that is inside the country when possible). Never includes the start cell.
 * @param {(x:number, y:number) => boolean} [isPlayable]
 * @returns {Array<[number, number]>}
 */
export function cellsAlong(x0, y0, x1, y1, isPlayable = () => true) {
  const out = [];
  const steps = Math.max(Math.abs(Math.floor(x1) - Math.floor(x0)), Math.abs(Math.floor(y1) - Math.floor(y0)), 1) * 2;
  let cx = Math.floor(x0);
  let cy = Math.floor(y0);
  for (let i = 1; i <= steps; i++) {
    const t = i / steps;
    const gx = Math.floor(x0 + (x1 - x0) * t);
    const gy = Math.floor(y0 + (y1 - y0) * t);
    if (gx === cx && gy === cy) continue;
    if (gx !== cx && gy !== cy) {
      if (isPlayable(gx, cy)) out.push([gx, cy]);
      else out.push([cx, gy]);
    }
    out.push([gx, gy]);
    cx = gx;
    cy = gy;
  }
  return out;
}
