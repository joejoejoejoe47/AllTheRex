/** Fixed-window in-memory rate limiter. Swap for Redis if you run several server processes. */
export function makeLimiter(max, windowMs) {
  const m = new Map();
  const entry = (k) => {
    const now = Date.now();
    let e = m.get(k);
    if (!e || e.reset < now) { e = { n: 0, reset: now + windowMs }; m.set(k, e); }
    return e;
  };
  setInterval(() => { const now = Date.now(); for (const [k, e] of m) if (e.reset < now) m.delete(k); }, 60_000).unref();
  return {
    /** count one attempt; false when over the limit */
    allow(k) { const e = entry(k); e.n++; return e.n <= max; },
    blocked(k) { return entry(k).n >= max; },
    fail(k) { entry(k).n++; },
    clear(k) { m.delete(k); },
  };
}
