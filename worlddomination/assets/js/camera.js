/**
 * Perspective camera over a flat ground plane (y = 0 is the ground, y up).
 * World coordinates: x = east, z = north. The camera sits south of its
 * target and looks north, so north is at the top of the screen.
 */

export function cameraPos(c) {
  const cp = Math.cos(c.pitch);
  const sp = Math.sin(c.pitch);
  return {
    x: c.tx + Math.sin(c.yaw) * cp * c.dist,
    y: sp * c.dist + 6,
    z: c.tz - Math.cos(c.yaw) * cp * c.dist,
  };
}

export function cameraBasis(c) {
  const p = cameraPos(c);
  const lx = c.tx - p.x;
  const ly = 0 - p.y;
  const lz = c.tz - p.z;
  const fl = Math.hypot(lx, ly, lz) || 1;
  const fx = lx / fl;
  const fy = ly / fl;
  const fz = lz / fl;
  // Screen-right is the horizontal direction 90 degrees clockwise from
  // forward: east for a north-facing camera.
  let rx = fz;
  let rz = -fx;
  const rl = Math.hypot(rx, rz) || 1;
  rx /= rl;
  rz /= rl;
  const ry = 0;
  // Screen-up = forward x right, which has a positive world-y component.
  const ux = fy * rz - fz * ry;
  const uy = fz * rx - fx * rz;
  const uz = fx * ry - fy * rx;
  return { p, fx, fy, fz, rx, ry, rz, ux, uy, uz };
}

function focal(c, h) {
  return (h * 0.62) / Math.tan(c.fov * 0.5);
}

const CENTER_Y = 0.56;

export function project(c, x, y, z, w, h) {
  const b = cameraBasis(c);
  const dx = x - b.p.x;
  const dy = y - b.p.y;
  const dz = z - b.p.z;
  const vx = dx * b.rx + dy * b.ry + dz * b.rz;
  const vy = dx * b.ux + dy * b.uy + dz * b.uz;
  const vz = dx * b.fx + dy * b.fy + dz * b.fz;
  if (vz < 0.55) return null;
  const s = focal(c, h) / vz;
  return { x: w * 0.5 + vx * s, y: h * CENTER_Y - vy * s, z: vz };
}

export function unproject(c, sx, sy, w, h) {
  const b = cameraBasis(c);
  const s0 = focal(c, h);
  const vx = (sx - w * 0.5) / s0;
  const vy = (h * CENTER_Y - sy) / s0;
  const dx = vx * b.rx + vy * b.ux + b.fx;
  const dy = vx * b.ry + vy * b.uy + b.fy;
  const dz = vx * b.rz + vy * b.uz + b.fz;
  if (Math.abs(dy) < 1e-6) return null;
  const t = -b.p.y / dy;
  if (t <= 0) return null;
  return { x: b.p.x + dx * t, z: b.p.z + dz * t };
}

/** Horizontal forward/right unit vectors for keyboard panning. */
export function groundAxes(c) {
  return {
    fx: -Math.sin(c.yaw),
    fz: Math.cos(c.yaw),
    rx: Math.cos(c.yaw),
    rz: Math.sin(c.yaw),
  };
}

/**
 * Smallest camera distance (and matching target) so every point of a
 * ground rectangle lands inside the safe screen rectangle.
 */
export function fitGround(c, bounds, w, h, safe) {
  const pts = [];
  const n = 8;
  for (let i = 0; i <= n; i++) {
    const t = i / n;
    const x = bounds.minX + (bounds.maxX - bounds.minX) * t;
    const z = bounds.minZ + (bounds.maxZ - bounds.minZ) * t;
    pts.push([x, bounds.minZ], [x, bounds.maxZ], [bounds.minX, z], [bounds.maxX, z]);
  }
  const fits = (cam) => {
    for (const [x, z] of pts) {
      const p = project(cam, x, 0, z, w, h);
      if (!p) return false;
      if (p.x < safe.left || p.x > safe.right || p.y < safe.top || p.y > safe.bottom) return false;
    }
    return true;
  };
  const safeCx = (safe.left + safe.right) / 2;
  const safeCy = (safe.top + safe.bottom) / 2;
  // At a given distance, move the target until the projected world is
  // centred in the safe area.
  const centreAt = (dist) => {
    const probe = { ...c, dist, tx: (bounds.minX + bounds.maxX) / 2, tz: (bounds.minZ + bounds.maxZ) / 2 };
    for (let round = 0; round < 10; round++) {
      let x0 = Infinity;
      let x1 = -Infinity;
      let y0 = Infinity;
      let y1 = -Infinity;
      for (const [x, z] of pts) {
        const q = project(probe, x, 0, z, w, h);
        if (!q) continue;
        x0 = Math.min(x0, q.x);
        x1 = Math.max(x1, q.x);
        y0 = Math.min(y0, q.y);
        y1 = Math.max(y1, q.y);
      }
      const ex = safeCx - (x0 + x1) / 2;
      const ey = safeCy - (y0 + y1) / 2;
      if (Math.abs(ex) < 0.5 && Math.abs(ey) < 0.5) break;
      const a = project(probe, probe.tx, 0, probe.tz, w, h);
      const bx = project(probe, probe.tx + 1, 0, probe.tz, w, h);
      const bz = project(probe, probe.tx, 0, probe.tz + 1, w, h);
      if (!a || !bx || !bz) break;
      probe.tx -= ex / (bx.x - a.x || 1);
      probe.tz -= ey / (bz.y - a.y || -1);
    }
    return probe;
  };
  let lo = 20;
  let hi = 6000;
  let best = null;
  if (fits(centreAt(hi))) {
    for (let k = 0; k < 36; k++) {
      const mid = (lo + hi) / 2;
      if (fits(centreAt(mid))) hi = mid;
      else lo = mid;
    }
    best = centreAt(hi);
  }
  return best || { ...c, tx: (bounds.minX + bounds.maxX) / 2, tz: (bounds.minZ + bounds.maxZ) / 2, dist: 400 };
}
