// Small JSON API client. Every POST carries the CSRF token from /api/session.
const base = () => (window.SQ_BASE || '');
let csrf = null;

export class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; }
}

async function handle(res) {
  let data = null;
  try { data = await res.json(); } catch { data = null; }
  if (!res.ok || !data || data.ok === false) {
    throw new ApiError((data && data.error) || `Something went wrong (${res.status}).`, res.status);
  }
  if (data.csrf) csrf = data.csrf;
  return data;
}

export async function get(path, params) {
  const q = params ? '?' + new URLSearchParams(params) : '';
  return handle(await fetch(base() + '/api/' + path + q, { credentials: 'same-origin', headers: { Accept: 'application/json' } }));
}

export async function post(path, body = {}) {
  const isForm = body instanceof FormData;
  const res = await fetch(base() + '/api/' + path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: isForm ? { 'X-CSRF-Token': csrf || '', Accept: 'application/json' } : { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '', Accept: 'application/json' },
    body: isForm ? body : JSON.stringify(body),
  });
  return handle(res);
}

export async function session() {
  const data = await get('session');
  csrf = data.csrf;
  return data;
}

export const imageUrl = (id) => base() + '/api/image?id=' + encodeURIComponent(id);
