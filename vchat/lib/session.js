import crypto from 'node:crypto';
import { config } from './config.js';

const b64u = (b) => Buffer.from(b).toString('base64url');

/** Signed, expiring token: base64url(json).hmac. Used for session cookies and 2-step tickets. */
export function sign(payload, ttlMs) {
  const body = b64u(JSON.stringify({ ...payload, exp: Date.now() + ttlMs }));
  const mac = crypto.createHmac('sha256', config.sessionSecret).update(body).digest('base64url');
  return `${body}.${mac}`;
}

export function verify(token) {
  if (typeof token !== 'string') return null;
  const [body, mac] = token.split('.');
  if (!body || !mac) return null;
  const good = crypto.createHmac('sha256', config.sessionSecret).update(body).digest('base64url');
  const a = Buffer.from(mac), b = Buffer.from(good);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  try {
    const p = JSON.parse(Buffer.from(body, 'base64url').toString());
    return p.exp > Date.now() ? p : null;
  } catch { return null; }
}

export const COOKIE = 'vchat_sid';

export function parseCookies(header = '') {
  const out = {};
  for (const part of header.split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

export function sessionCookie(userId) {
  const t = sign({ uid: userId, k: 'session' }, config.sessionTtlMs);
  return `${COOKIE}=${t}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${Math.floor(config.sessionTtlMs / 1000)}${config.cookieSecure ? '; Secure' : ''}`;
}
export const clearCookie = () =>
  `${COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0${config.cookieSecure ? '; Secure' : ''}`;

export function userIdFromRequest(req) {
  const p = verify(parseCookies(req.headers.cookie)[COOKIE]);
  return p && p.k === 'session' ? p.uid : null;
}
