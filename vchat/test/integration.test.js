// Spawns the server and exercises phone+2-step login, meetings, and the 20-person cap.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { hotp, b32decode } from '../lib/totp.js';

const PORT = 3999, BASE = `http://127.0.0.1:${PORT}`;
const srv = spawn('node', ['server.js'], { env: { ...process.env, PORT, SMS_MODE: 'console', SESSION_SECRET: 'test-secret', SMS_IP_LIMIT: '1000' }, cwd: new URL('..', import.meta.url).pathname });
let log = '';
srv.stdout.on('data', (d) => (log += d)); srv.stderr.on('data', (d) => (log += d));
await new Promise((r) => { const i = setInterval(() => log.includes('listening') && (clearInterval(i), r()), 50); });

const post = async (path, body, cookie) => {
  const r = await fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(cookie && { Cookie: cookie }) }, body: JSON.stringify(body) });
  return { status: r.status, body: await r.json(), cookie: r.headers.get('set-cookie')?.split(';')[0] };
};
const lastCode = (phone) => [...log.matchAll(new RegExp(`to ${phone.replace('+', '\\+')}: Your vchat code is (\\d{6})`, 'g'))].pop()[1];

async function login(phone, totpSecret) {
  assert.equal((await post('/api/auth/phone/start', { phone })).status, 200);
  const bad = await post('/api/auth/phone/verify', { phone, code: '000000' });
  assert.equal(bad.status, 401);
  const v = await post('/api/auth/phone/verify', { phone, code: lastCode(phone) });
  assert.equal(v.status, 200);
  const secret = v.body.secret || totpSecret;
  assert.equal(v.body.step, totpSecret ? 'totp' : 'enroll');
  // wrong 2nd step rejected, no cookie
  const wrong = await post('/api/auth/phone/2fa', { ticket: v.body.ticket, code: '123456' });
  assert.equal(wrong.status, 401); assert.ok(!wrong.cookie);
  // a code from an older time-step is accepted (±1 window) but we use the current one
  const ok = await post('/api/auth/phone/2fa', { ticket: v.body.ticket, code: hotp(b32decode(secret), Math.floor(Date.now() / 30000)) });
  assert.equal(ok.status, 200, JSON.stringify(ok.body));
  return { cookie: ok.cookie, secret, recovery: ok.body.recoveryCodes, user: ok.body.user };
}

try {
  // unauthenticated access is refused
  assert.equal((await fetch(BASE + '/api/meetings/abc-defg-hij')).status, 401);
  assert.equal((await post('/api/meetings', {})).status, 401);
  // CSRF-ish: non-JSON content type rejected
  assert.equal((await fetch(BASE + '/api/auth/phone/start', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: '{}' })).status, 415);
  // path traversal
  assert.notEqual((await fetch(BASE + '/..%2f..%2fpackage.json')).status, 200);
  assert.equal((await fetch(BASE + '/')).status, 200);

  const host = await login('+15550000001');
  assert.equal(host.recovery.length, 8);
  // second login for same number needs the authenticator (not enrol again)
  const again = await post('/api/auth/phone/start', { phone: '+15550000001' });
  await new Promise((r) => setTimeout(r, 100));
  const v2 = await post('/api/auth/phone/verify', { phone: '+15550000001', code: lastCode('+15550000001') });
  assert.equal(v2.body.step, 'totp');
  // recovery code works once
  const rc = await post('/api/auth/phone/2fa', { ticket: v2.body.ticket, code: host.recovery[0] });
  assert.equal(rc.status, 200);
  const v3 = await post('/api/auth/phone/start', { phone: '+15550000001' });
  const v3v = await post('/api/auth/phone/verify', { phone: '+15550000001', code: lastCode('+15550000001') });
  assert.equal((await post('/api/auth/phone/2fa', { ticket: v3v.body.ticket, code: host.recovery[0] })).status, 401, 'recovery code is single-use');

  const m = await post('/api/meetings', { title: 'Test' }, host.cookie);
  assert.equal(m.status, 201);
  const code = m.body.code;

  // 20 people join, 21st is refused
  const clients = [];
  const open = (cookie) => new Promise((res, rej) => {
    const ws = new WebSocket(`ws://127.0.0.1:${PORT}/ws`, { headers: { Cookie: cookie } });
    const c = { ws, msgs: [] };
    ws.onmessage = (e) => c.msgs.push(JSON.parse(e.data));
    ws.onopen = () => res(c); ws.onerror = () => rej(new Error('ws failed'));
  });
  const waitFor = async (c, type) => { for (let i = 0; i < 100; i++) { const m = c.msgs.find((x) => x.type === type); if (m) return m; await new Promise((r) => setTimeout(r, 20)); } throw new Error('timeout ' + type); };

  for (let i = 0; i < 21; i++) {
    const phone = `+1555000${String(100 + i)}`;
    const u = i === 0 ? host : await login(phone);
    const c = await open(u.cookie); c.ws.send(JSON.stringify({ type: 'join', code })); clients.push(c);
    if (i < 20) { const j = await waitFor(c, 'joined'); assert.equal(j.peers.length, i); }
    else { const e = await waitFor(c, 'error'); assert.equal(e.code, 'full'); }
  }
  // signalling relays only to room members; chat broadcasts
  const [a, b] = clients;
  const bId = (await waitFor(b, 'joined')).you, aId = (await waitFor(a, 'joined')).you;
  a.ws.send(JSON.stringify({ type: 'signal', to: bId, data: { sdp: { type: 'offer', sdp: 'x' } } }));
  assert.equal((await waitFor(b, 'signal')).from, aId);
  a.ws.send(JSON.stringify({ type: 'chat', text: 'hello' }));
  assert.equal((await waitFor(b, 'chat')).text, 'hello');
  // non-host cannot kick
  b.ws.send(JSON.stringify({ type: 'kick', id: aId })); await new Promise((r) => setTimeout(r, 150));
  assert.ok(!a.msgs.find((x) => x.type === 'kicked'));
  // host can kick, and kicked user cannot rejoin
  a.ws.send(JSON.stringify({ type: 'kick', id: bId }));
  assert.ok(await waitFor(b, 'kicked'));
  // a slot freed: the previously refused person can now join
  const last = clients[20]; const fresh = await open((await login('+15550000999')).cookie);
  fresh.ws.send(JSON.stringify({ type: 'join', code })); assert.ok(await waitFor(fresh, 'joined'));
  // unauthenticated websocket refused
  await assert.rejects(new Promise((res, rej) => { const w = new WebSocket(`ws://127.0.0.1:${PORT}/ws`); w.onopen = res; w.onerror = () => rej(new Error('refused')); }));
  // cross-origin websocket refused
  await assert.rejects(new Promise((res, rej) => { const w = new WebSocket(`ws://127.0.0.1:${PORT}/ws`, { headers: { Cookie: host.cookie, Origin: 'https://evil.example' } }); w.onopen = res; w.onerror = () => rej(new Error('refused')); }));
  console.log('integration tests passed');
} catch (e) {
  console.error('FAILED', e); process.exitCode = 1;
} finally { srv.kill(); setTimeout(() => process.exit(), 100); }
