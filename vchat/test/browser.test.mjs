// Optional real-browser test (needs Playwright + Chromium): node test/browser.test.mjs
// Joins N fake-camera participants and checks every pair is connected and receiving moving video.
import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';
import assert from 'node:assert/strict';
import { hotp, b32decode } from '../lib/totp.js';
const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_PATH || 'playwright');

const N = Number(process.env.N || 4), PORT = 3998, BASE = `http://localhost:${PORT}`;
const srv = spawn('node', ['server.js'], { env: { ...process.env, PORT, SESSION_SECRET: 't', SMS_IP_LIMIT: '1000' }, cwd: new URL('..', import.meta.url).pathname });
let log = ''; srv.stdout.on('data', (d) => (log += d));
await new Promise((r) => { const i = setInterval(() => log.includes('listening') && (clearInterval(i), r()), 50); });

const post = async (path, body, cookie) => {
  const r = await fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(cookie && { Cookie: cookie }) }, body: JSON.stringify(body) });
  return { body: await r.json(), cookie: r.headers.get('set-cookie')?.split(';')[0] };
};
async function login(phone) {
  await post('/api/auth/phone/start', { phone });
  let code;
  for (let i = 0; i < 50 && !code; i++) {
    code = [...log.matchAll(new RegExp(`to ${phone.replace('+', '\\+')}: Your vchat code is (\\d{6})`, 'g'))].pop()?.[1];
    if (!code) await new Promise((r) => setTimeout(r, 20));
  }
  const v = await post('/api/auth/phone/verify', { phone, code });
  const r = await post('/api/auth/phone/2fa', { ticket: v.body.ticket, code: hotp(b32decode(v.body.secret), Math.floor(Date.now() / 30000)) });
  return r.cookie.split('=')[1];
}

const browser = await chromium.launch({ args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
try {
  const hostTok = await login('+15551110000');
  const m = await post('/api/meetings', { title: 'E2E' }, `vchat_sid=${hostTok}`);
  const pages = [];
  for (let i = 0; i < N; i++) {
    const tok = i === 0 ? hostTok : await login(`+1555111${String(1000 + i)}`);
    const ctx = await browser.newContext({ permissions: ['camera', 'microphone'] });
    await ctx.addCookies([{ name: 'vchat_sid', value: tok, url: BASE }]);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log('pageerror', e.message)); page.on('console', (m) => ['warning','error'].includes(m.type()) && console.log('console', i, m.text()));
    await page.goto(`${BASE}/#/m/${m.body.code}`);
    await page.getByRole('button', { name: 'Join now' }).click();
    await page.waitForSelector('.tile');
    pages.push(page);
  }
  for (const [i, p] of pages.entries()) {
    await p.waitForFunction((n) => document.querySelectorAll('.tile').length === n, N, { timeout: 15000 });
    await p.waitForFunction((n) => [...document.querySelectorAll('.tile')].filter((t) => !t.classList.contains('mirror'))
      .every((t) => !t.classList.contains('connecting') && t.querySelector('video').videoWidth > 0 && t.querySelector('video').readyState >= 2) , N, { timeout: 30000 })
      .catch(async () => { throw new Error(`page ${i} did not get all remote video; states: ` + await p.evaluate(() => [...document.querySelectorAll('.tile')].map((t) => t.className + ':' + t.querySelector('video').videoWidth + ':' + t.querySelector('video').srcObject.getTracks().map((x) => x.kind + x.readyState + (x.muted ? 'M' : '')).join(',')).join(' | '))); });
  }
  console.log(`${N} participants: all connected with video`);
  // chat + mute state propagate
  await pages[0].getByRole('button', { name: 'Chat' }).click();
  await pages[0].fill('.chatform input', 'hi all'); await pages[0].keyboard.press('Enter');
  await pages[1].getByRole('button', { name: 'Chat' }).click();
  await pages[1].waitForSelector('.msg:has-text("hi all")');
  await pages[2].getByRole('button', { name: 'Mute' }).click();
  await pages[0].waitForFunction(() => document.querySelectorAll('.tile.muted').length >= 1);
  await pages[0].screenshot({ path: process.env.SHOT || '/tmp/vchat-meeting.png' });
  console.log('browser test passed');
} catch (e) { console.error('FAILED', e); process.exitCode = 1; }
finally { await browser.close(); srv.kill(); setTimeout(() => process.exit(), 100); }
