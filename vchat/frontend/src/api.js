const BASE = 'api.php';

export async function api(action, body, query = {}) {
  const qs = new URLSearchParams({ a: action, ...query });
  const r = await fetch(`${BASE}?${qs}`, {
    method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin',
    headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let data = null;
  try { data = await r.json(); } catch { /* not JSON */ }
  if (!r.ok) throw Object.assign(new Error(data?.error || `Request failed (${r.status})`), { status: r.status });
  return data;
}

export const beacon = (action, body) =>
  navigator.sendBeacon?.(`${BASE}?a=${action}`, new Blob([JSON.stringify(body)], { type: 'application/json' }));
