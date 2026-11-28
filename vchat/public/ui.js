// tiny DOM helpers. Text is always set via textContent/createTextNode, never innerHTML (XSS-safe).
export function h(tag, props = {}, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(props || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k in el && k !== 'list') el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat()) if (kid != null && kid !== false) el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
  return el;
}

export async function api(path, method = 'GET', body) {
  const r = await fetch('/api' + path, {
    method, credentials: 'same-origin',
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  let data = null;
  try { data = await r.json(); } catch { /* empty */ }
  if (!r.ok) throw Object.assign(new Error(data?.error || `Request failed (${r.status})`), { status: r.status });
  return data;
}

export function avatar(user, big) {
  const a = h('div', { class: 'ava' });
  if (user.picture && /^https:\/\//.test(user.picture)) {
    a.append(h('img', { src: user.picture, alt: '', referrerPolicy: 'no-referrer' }));
  } else {
    a.textContent = (user.name || '?').trim().charAt(0).toUpperCase() || '?';
  }
  return a;
}

export function toast(msg, ms = 3500) {
  const t = h('div', { class: 'toast', role: 'status' }, msg);
  document.body.append(t);
  setTimeout(() => t.remove(), ms);
}
