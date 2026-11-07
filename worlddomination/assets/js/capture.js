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
