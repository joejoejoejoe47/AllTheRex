// Optional real-browser test (needs Playwright + Chromium): node tests/browser.mjs   (N=8 for 8 people)
// Joins N fake-camera participants and checks every pair is connected and receiving video.
import { createRequire } from 'node:module';
import { spawn, execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_PATH || 'playwright');

const here = path.dirname(fileURLToPath(import.meta.url)), root = path.join(here, '..');
const N = Number(process.env.N || 4), PORT = 3996, BASE = `http://localhost:${PORT}`;
const db = `/tmp/vchat-browser-${process.pid}.sqlite`, logFile = `/tmp/vchat-browser-${process.pid}.log`;
const env = { ...process.env, VCHAT_CONFIG: path.join(here, 'config.test.php'), VCHAT_TEST_DB: db, PHP_CLI_SERVER_WORKERS: '16' };
execFileSync('php', ['-r', `require "${root}/src/bootstrap.php"; foreach (vchat_schema("sqlite") as $s) db()->exec($s);`], { env });
const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', root], { env, stdio: ['ignore', 'ignore', fs.openSync(logFile, 'w')] });
await new Promise((r) => setTimeout(r, 800));

const hotp = (secret, step) => execFileSync('php', ['-r', `require "${root}/src/totp.php"; echo hotp(b32_decode("${secret}"), ${step});`]).toString();
const post = async (action, body, cookie) => {
  const r = await fetch(`${BASE}/api.php?a=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(cookie && { Cookie: cookie }) }, body: JSON.stringify(body) });
  return { body: await r.json(), cookie: r.headers.get('set-cookie')?.split(';')[0] };
};
async function login(phone) {
  await post('auth.phone.start', { phone });
  let code;
  for (let i = 0; i < 50 && !code; i++) {
    code = [...fs.readFileSync(logFile, 'utf8').matchAll(new RegExp(`to ${phone.replace('+', '\\+')}: Your vchat code is (\\d{6})`, 'g'))].pop()?.[1];
    if (!code) await new Promise((r) => setTimeout(r, 40));
  }
  const v = await post('auth.phone.verify', { phone, code });
  const r = await post('auth.phone.2fa', { ticket: v.body.ticket, code: hotp(v.body.secret, Math.floor(Date.now() / 30000)) });
  return r.cookie.split('=')[1];
}

const browser = await chromium.launch({ args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
try {
  const hostTok = await login('+15551110000');
  const m = await post('meeting.create', { title: 'E2E' }, `vchat_sid=${hostTok}`);
  const pages = [];
  for (let i = 0; i < N; i++) {
    const tok = i === 0 ? hostTok : await login(`+1555111${1000 + i}`);
    const ctx = await browser.newContext({ permissions: ['camera', 'microphone'] });
    await ctx.addCookies([{ name: 'vchat_sid', value: tok, url: BASE }]);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log('pageerror', i, e.message));
    page.on('console', (c) => ['warning', 'error'].includes(c.type()) && console.log('console', i, c.text()));
    await page.goto(`${BASE}/index.php#/m/${m.body.code}`);
    await page.getByRole('button', { name: 'Join now' }).click();
    await page.waitForSelector('.tile');
    pages.push(page);
  }
  for (const [i, p] of pages.entries()) {
    await p.waitForFunction((n) => document.querySelectorAll('.tile').length === n, N, { timeout: 30000 });
    await p.waitForFunction(() => [...document.querySelectorAll('.tile')].filter((t) => !t.classList.contains('mirror'))
      .every((t) => !t.classList.contains('connecting') && t.querySelector('video').videoWidth > 0), null, { timeout: 60000 })
      .catch(async () => { throw new Error(`page ${i} missing remote video: ` + await p.evaluate(() => [...document.querySelectorAll('.tile')].map((t) => t.className + ':' + t.querySelector('video').videoWidth).join(' | '))); });
  }
  console.log(`${N} participants: all connected with video`);
  await pages[0].getByRole('button', { name: 'Chat' }).click();
  await pages[0].fill('.chatform input', 'hi all'); await pages[0].keyboard.press('Enter');
  await pages[1].getByRole('button', { name: 'Chat' }).click();
  await pages[1].waitForSelector('.msg:has-text("hi all")', { timeout: 40000 });
  await pages[2 % N].getByRole('button', { name: 'Mute' }).click();
  await pages[0].waitForFunction(() => document.querySelectorAll('.tile.muted').length >= 1, null, { timeout: 40000 });
  // host removes the last person; they are returned to the lobby
  if (N > 2) {
    await pages[0].locator('.side header button').first().click();
    pages[0].once('dialog', (d) => d.accept());
    await pages[0].locator('.person', { hasText: 'Remove' }).last().getByRole('button', { name: 'Remove' }).click();
    await pages[N - 1].waitForSelector('text=New meeting', { timeout: 15000 });
    console.log('kick returns the removed person to the lobby');
  }
  if (process.env.SHOT) await pages[0].screenshot({ path: process.env.SHOT });
  console.log('browser test passed');
} catch (e) { console.error('FAILED', e); process.exitCode = 1; }
finally { await browser.close(); srv.kill(); for (const f of [db, db + '-wal', db + '-shm', logFile]) fs.rmSync(f, { force: true }); process.exit(); }
