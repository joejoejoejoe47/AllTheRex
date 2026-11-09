function base() {
  const b = typeof window !== 'undefined' && window.WD_BASE ? String(window.WD_BASE) : '';
  return b.endsWith('/') ? b.slice(0, -1) : b;
}

export function url(path) {
  const p = String(path).replace(/^\//, '');
  const b = base();
  return (b ? `${b}/` : '') + p;
}

let csrf = '';

export function getCsrf() {
  return csrf;
}

export async function api(path, opts = {}) {
  const headers = { Accept: 'application/json', ...(opts.headers || {}) };
  const method = (opts.method || 'GET').toUpperCase();
  if (opts.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;
  const res = await fetch(url(path), {
    credentials: 'same-origin',
    ...opts,
    method,
    headers,
    body: opts.body && typeof opts.body !== 'string' ? JSON.stringify(opts.body) : opts.body,
  });
  let data = {};
  try {
    data = await res.json();
  } catch {
    data = {};
  }
  if (data && typeof data.csrf === 'string') csrf = data.csrf;
  if (!res.ok) {
    const err = new Error(data.error || `Request failed (${res.status})`);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

export async function loadSession() {
  return api('api/auth/session');
}

export async function signup(name, email, password) {
  return api('api/auth/signup', { method: 'POST', body: { name, email, password } });
}

export async function login(email, password) {
  return api('api/auth/login', { method: 'POST', body: { email, password } });
}

export async function logout() {
  return api('api/auth/logout', { method: 'POST', body: {} });
}

export async function loadPodium() {
  return api('api/leaderboard');
}

export async function startMatch(country) {
  return api('api/match/start', { method: 'POST', body: { country } });
}

export async function finishMatch(payload) {
  return api('api/match/finish', { method: 'POST', body: payload });
}

export async function loadCountries() {
  const res = await fetch(url('assets/data/countries.json'), { credentials: 'same-origin' });
  if (!res.ok) throw new Error('Could not load the world map.');
  return res.json();
}
