/**
 * First-capture tutorial: find a small rectangular loop that starts and ends
 * on the player's starting land and stays inside the country, so a new
 * player can follow it and see the enclosed area become theirs.
 */

const OOB = -2;

function playable(grid, x, y) {
  return y >= 0 && x >= 0 && y < grid.length && x < grid[0].length && grid[y][x] !== OOB;
}

/**
 * @param {number[][]} grid
 * @param {number} sx spawn cell x
 * @param {number} sy spawn cell y
 * @param {number} radius starting-land radius
 * @returns {null | {waypoints: Array<{x:number,y:number,key:string,label:string}>, rect: {x0:number,y0:number,x1:number,y1:number}, area:number}}
 */
export function findGuideLoop(grid, sx, sy, radius = 3) {
  const orientations = [
    { vy: -1, hx: 1 }, // north then east
    { vy: -1, hx: -1 }, // north then west
    { vy: 1, hx: 1 }, // south then east
    { vy: 1, hx: -1 }, // south then west
  ];
  for (let size = 14; size >= 3; size--) {
    for (const o of orientations) {
      const xA = sx;
      const xB = sx + o.hx * size;
      const yA = sy;
      const yB = sy + o.vy * (radius + size);
      const x0 = Math.min(xA, xB);
      const x1 = Math.max(xA, xB);
      const y0 = Math.min(yA, yB);
      const y1 = Math.max(yA, yB);
      let ok = true;
      for (let y = y0; y <= y1 && ok; y++) {
        for (let x = x0; x <= x1; x++) {
          if (!playable(grid, x, y)) {
            ok = false;
            break;
          }
        }
      }
      if (!ok) continue;
      const vKey = o.vy < 0 ? 'W / \u2191' : 'S / \u2193';
      const backKey = o.vy < 0 ? 'S / \u2193' : 'W / \u2191';
      const hKey = o.hx > 0 ? 'D / \u2192' : 'A / \u2190';
      const homeKey = o.hx > 0 ? 'A / \u2190' : 'D / \u2192';
      const vWord = o.vy < 0 ? 'north' : 'south';
      const hWord = o.hx > 0 ? 'east' : 'west';
      return {
        waypoints: [
          { x: xA + 0.5, y: yB + 0.5, key: vKey, screen: o.vy < 0 ? 'up' : 'down', label: `Hold ${vKey} to walk ${vWord} off your land` },
          { x: xB + 0.5, y: yB + 0.5, key: hKey, screen: o.hx > 0 ? 'right' : 'left', label: `Hold ${hKey} to walk ${hWord}` },
          { x: xB + 0.5, y: yA + 0.5, key: backKey, screen: o.vy < 0 ? 'down' : 'up', label: `Hold ${backKey} to come back level with your land` },
          { x: xA + 0.5, y: yA + 0.5, key: homeKey, screen: o.hx > 0 ? 'left' : 'right', label: `Hold ${homeKey} to walk home and close the loop` },
        ],
        rect: { x0, y0, x1, y1 },
        area: (x1 - x0 + 1) * (y1 - y0 + 1),
      };
    }
  }
  return null;
}
