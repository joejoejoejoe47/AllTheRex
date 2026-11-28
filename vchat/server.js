import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { config, ROOT } from './lib/config.js';
import { store } from './db/index.js';
import { createAuth, HttpError, publicUser } from './lib/auth.js';
import { sessionCookie, clearCookie, userIdFromRequest } from './lib/session.js';
import { acceptWebSocket } from './lib/ws.js';
import { createRooms } from './lib/rooms.js';

const auth = createAuth(store);
const rooms = createRooms({ store, maxParticipants: config.maxParticipants });
const PUBLIC = path.join(ROOT, 'public');

const SECURITY_HEADERS = {
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'DENY',
  'Referrer-Policy': 'no-referrer',
  'Permissions-Policy': 'camera=(self), microphone=(self), display-capture=(self)',
  'Content-Security-Policy': [
    "default-src 'self'",
    "script-src 'self' https://accounts.google.com/gsi/client",
    "style-src 'self' https://accounts.google.com/gsi/style",
    "img-src 'self' https: data:",
    "media-src 'self' blob:",
    "connect-src 'self' ws: wss: https://accounts.google.com/gsi/",
    "frame-src https://accounts.google.com/gsi/",
    "base-uri 'none'", "form-action 'self'", "frame-ancestors 'none'",
  ].join('; '),
};

const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };

const clientIp = (req) =>
  (config.trustProxy && req.headers['x-forwarded-for']?.split(',')[0].trim()) || req.socket.remoteAddress || 'unknown';

function json(res, status, body, headers = {}) {
  const s = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...SECURITY_HEADERS, ...headers });
  res.end(s);
}

async function readJson(req) {
  if (!/^application\/json\b/i.test(req.headers['content-type'] || '')) throw new HttpError(415, 'Expected JSON');
  let size = 0; const chunks = [];
  for await (const c of req) { size += c.length; if (size > 16 * 1024) throw new HttpError(413, 'Too large'); chunks.push(c); }
  try { return JSON.parse(Buffer.concat(chunks).toString() || '{}'); } catch { throw new HttpError(400, 'Bad JSON'); }
}

function iceServers(userId) {
  const list = [{ urls: config.stunUrls }];
  if (config.turnUrls.length) {
    if (config.turnSecret) { // coturn "use-auth-secret": short-lived credentials
      const username = `${Math.floor(Date.now() / 1000) + 6 * 3600}:${userId}`;
      const credential = crypto.createHmac('sha1', config.turnSecret).update(username).digest('base64');
      list.push({ urls: config.turnUrls, username, credential });
    } else if (config.turnUsername) {
      list.push({ urls: config.turnUrls, username: config.turnUsername, credential: config.turnCredential });
    }
  }
  return list;
}

const newCode = () => {
  const a = 'abcdefghjkmnpqrstuvwxyz';
  const part = (n) => Array.from({ length: n }, () => a[crypto.randomInt(a.length)]).join('');
  return `${part(3)}-${part(4)}-${part(3)}`;
};

async function currentUser(req) {
  const id = userIdFromRequest(req);
  return id ? store.findUserById(id) : null;
}

async function api(req, res, url) {
  const route = `${req.method} ${url.pathname}`;
  const ip = clientIp(req);
  const login = (r) => json(res, 200, { user: publicUser(r.user), ...(r.recoveryCodes && { recoveryCodes: r.recoveryCodes }),
    ...(r.usedRecovery && { recoveryLeft: r.recoveryLeft }) }, { 'Set-Cookie': sessionCookie(r.user.id) });

  switch (route) {
    case 'GET /api/config': {
      const user = await currentUser(req);
      return json(res, 200, { googleClientId: config.googleClientId, maxParticipants: config.maxParticipants,
        iceServers: user ? iceServers(user.id) : [], user: user ? publicUser(user) : null });
    }
    case 'POST /api/auth/google': return login(await auth.google(await readJson(req)));
    case 'POST /api/auth/phone/start': return json(res, 200, await auth.phoneStart(await readJson(req), ip));
    case 'POST /api/auth/phone/verify': return json(res, 200, await auth.phoneVerify(await readJson(req)));
    case 'POST /api/auth/phone/2fa': return login(await auth.phoneSecondStep(await readJson(req)));
    case 'POST /api/auth/logout': return json(res, 200, { ok: true }, { 'Set-Cookie': clearCookie() });
  }

  // everything below needs a signed-in user
  const user = await currentUser(req);
  if (!user) throw new HttpError(401, 'Sign in required');

  if (route === 'PATCH /api/me') {
    const { name } = await readJson(req);
    const clean = String(name || '').replace(/[\u0000-\u001f<>]/g, '').trim().slice(0, 60);
    if (!clean) throw new HttpError(400, 'Name required');
    return json(res, 200, { user: publicUser(await store.updateUser(user.id, { name: clean })) });
  }
  if (route === 'POST /api/meetings') {
    const body = await readJson(req);
    const title = String(body.title || '').replace(/[\u0000-\u001f<>]/g, '').trim().slice(0, 120) || `${user.name}'s meeting`;
    const m = await store.createMeeting({ code: newCode(), hostId: user.id, title });
    return json(res, 201, { code: m.code, title: m.title });
  }
  const mm = url.pathname.match(/^\/api\/meetings\/([a-z-]{1,16})$/);
  if (mm && req.method === 'GET') {
    const m = await store.getMeeting(mm[1]);
    if (!m || m.ended) throw new HttpError(404, 'Meeting not found or already ended');
    const count = rooms.count(m.code);
    return json(res, 200, { code: m.code, title: m.title, isHost: m.hostId === user.id, participants: count,
      full: count >= config.maxParticipants, max: config.maxParticipants });
  }
  throw new HttpError(404, 'Not found');
}

function serveStatic(req, res, url) {
  if (req.method !== 'GET' && req.method !== 'HEAD') { res.writeHead(405); return res.end(); }
  let p = decodeURIComponent(url.pathname);
  if (p === '/') p = '/index.html';
  const file = path.normalize(path.join(PUBLIC, p));
  if (!file.startsWith(PUBLIC + path.sep)) { res.writeHead(403); return res.end(); }
  fs.readFile(file, (err, data) => {
    if (err) { res.writeHead(404, SECURITY_HEADERS); return res.end('Not found'); }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-cache', ...SECURITY_HEADERS });
    res.end(req.method === 'HEAD' ? undefined : data);
  });
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://x');
    if (url.pathname.startsWith('/api/')) return await api(req, res, url);
    return serveStatic(req, res, url);
  } catch (e) {
    if (e instanceof HttpError) return json(res, e.status, { error: e.message });
    console.error('[http]', e);
    json(res, 500, { error: 'Server error' });
  }
});

// ---- WebSocket (/ws) ----
const sockets = new Set();
server.on('upgrade', async (req, socket) => {
  const reject = (code, text) => socket.end(`HTTP/1.1 ${code} ${text}\r\nConnection: close\r\n\r\n`);
  try {
    const url = new URL(req.url, 'http://x');
    if (url.pathname !== '/ws') return reject(404, 'Not Found');
    const origin = req.headers.origin; // browsers always send it; blocks cross-site websocket hijacking
    if (origin) {
      let host; try { host = new URL(origin).host; } catch { return reject(403, 'Forbidden'); }
      if (host !== req.headers.host && !config.allowedOrigins.includes(origin)) return reject(403, 'Forbidden');
    }
    const user = await currentUser(req);
    if (!user) return reject(401, 'Unauthorized');
    const ws = acceptWebSocket(req, socket, 64 * 1024);
    if (!ws) return;
    sockets.add(ws);
    ws.on('close', () => sockets.delete(ws));
    rooms.attach(ws, user);
  } catch (e) { console.error('[ws]', e); socket.destroy(); }
});

// drop dead connections
setInterval(() => {
  for (const ws of sockets) { if (!ws.alive) ws.terminate(); else ws.ping(); }
}, 25_000).unref();

server.listen(config.port, config.host, () => {
  console.log(`vchat listening on http://${config.host}:${config.port}  (max ${config.maxParticipants}/meeting, sms=${config.smsMode}, google=${config.googleClientId ? 'on' : 'off'})`);
});
