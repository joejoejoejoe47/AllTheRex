// Real-browser regression test: drives headless Chromium with real input
// (page.mouse / page.keyboard / page.touchscreen), never by calling game
// functions. Game state is only *read* through the read-only __wdDebug()
// snapshot to find where things were drawn and to check results.
//
//   node worlddomination/tests/browser/pointer.e2e.mjs
//
// Env: CHROME_BIN (default /usr/bin/google-chrome), PHP_BIN (default php),
// PLAYWRIGHT_CORE (module path, default 'playwright-core'), BASE (use an
// already running server instead of starting an isolated one).
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import net from 'node:net';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const require = createRequire(join(process.cwd(), 'noop.js'));
let pw;
try {
  const spec = process.env.PLAYWRIGHT_CORE || 'playwright-core';
  pw = await import(spec.startsWith('/') ? pathToFileURL(spec).href : pathToFileURL(require.resolve(spec)).href);
} catch (e) {
  console.error('playwright-core is required (npm i --no-save playwright-core):', e.message);
  process.exit(2);
}
const { chromium } = pw.default || pw;

let pass = 0;
let fail = 0;
function ok(cond, name, extra = '') {
  if (cond) { pass++; console.log('  ok  ' + name); } else { fail++; console.log('  FAIL ' + name + (extra ? ' — ' + extra : '')); }
}

async function freePort() {
  return new Promise((res) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });
}

let server = null;
let tmp = null;
let BASE = process.env.BASE;
if (!BASE) {
  tmp = mkdtempSync(join(tmpdir(), 'wd-e2e-'));
  const port = await freePort();
  server = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', root, join(root, 'index.php')], {
    env: { ...process.env, DB_DRIVER: 'sqlite', DB_SQLITE: join(tmp, 'e2e.sqlite'), APP_DEBUG: '0' },
    stdio: 'ignore',
  });
  BASE = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 50; i++) {
    try { const r = await fetch(BASE + '/'); if (r.ok) break; } catch { /* starting */ }
    await new Promise((r) => setTimeout(r, 100));
  }
}

const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
let seq = 0;

async function signedInPage(opts, initScript = null) {
  const ctx = await browser.newContext(opts);
  if (initScript) await ctx.addInitScript(initScript);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('dialog', (d) => { errors.push('native dialog shown: ' + d.message()); d.dismiss(); });
  await page.goto(BASE + '/');
  await page.waitForSelector('#auth-form');
  const tapOrClick = async (sel) => {
    const box = await page.locator(sel).boundingBox();
    if (opts.hasTouch) await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
    else await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  };
  await tapOrClick('input[name=name]');
  await page.keyboard.type('Pointer Test');
  await tapOrClick('input[name=email]');
  await page.keyboard.type(`e2e.${Date.now()}.${seq++}@example.com`);
  await tapOrClick('input[name=password]');
  await page.keyboard.type('WdTest-Pass9!');
  await tapOrClick('#auth-form button[type=submit]');
  await page.waitForFunction(() => document.getElementById('modal').hidden, null, { timeout: 15000 });
  await page.waitForTimeout(500);
  return { ctx, page, errors };
}

// Screen point well inside the country as it is drawn right now.
async function countryPixel(page, id) {
  return page.evaluate(async (cid) => {
    const m = await import('./assets/js/camera.js');
    const cam = window.__wdDebug().cam;
    const data = await (await fetch('assets/data/countries.json')).json();
    const c = data.countries.find((x) => x.id === cid);
    let ring = c.overview[0];
    let bestA = 0;
    for (const r of c.overview) {
      let a = 0;
      for (let i = 0, j = r.length - 1; i < r.length; j = i++) a += (r[j][0] - r[i][0]) * (r[j][1] + r[i][1]);
      if (Math.abs(a) > bestA) { bestA = Math.abs(a); ring = r; }
    }
    const pts = ring.map(([x, z]) => m.project(cam, x, 0, z, innerWidth, innerHeight)).filter(Boolean);
    const inside = (x, y) => {
      let s = false;
      for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const a = pts[i]; const b = pts[j];
        if ((a.y > y) !== (b.y > y) && x < ((b.x - a.x) * (y - a.y)) / (b.y - a.y) + a.x) s = !s;
      }
      return s;
    };
    const edge = (x, y) => {
      let d = Infinity;
      for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const a = pts[i]; const b = pts[j];
        const dx = b.x - a.x; const dy = b.y - a.y; const L = dx * dx + dy * dy || 1;
        const t = Math.max(0, Math.min(1, ((x - a.x) * dx + (y - a.y) * dy) / L));
        d = Math.min(d, Math.hypot(a.x + dx * t - x, a.y + dy * t - y));
      }
      return d;
    };
    let x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity;
    for (const p of pts) { x0 = Math.min(x0, p.x); x1 = Math.max(x1, p.x); y0 = Math.min(y0, p.y); y1 = Math.max(y1, p.y); }
    let best = null; let bestD = -1;
    for (let gy = 0; gy <= 24; gy++) for (let gx = 0; gx <= 24; gx++) {
      const x = x0 + ((x1 - x0) * gx) / 24; const y = y0 + ((y1 - y0) * gy) / 24;
      if (!inside(x, y)) continue;
      const d = edge(x, y);
      if (d > bestD) { bestD = d; best = { x, y }; }
    }
    if (!best) return null;
    const el = document.elementFromPoint(best.x, best.y);
    return { x: best.x, y: best.y, margin: bestD, name: c.name, topElement: el ? el.id || el.tagName : null };
  }, id);
}

async function playVisible(page) {
  return page.evaluate(() => {
    const b = document.getElementById('play-btn');
    if (!b) return false;
    const r = b.getBoundingClientRect();
    const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
    return r.width > 0 && r.height > 0 && r.bottom <= innerHeight && r.right <= innerWidth && (top === b || b.contains(top));
  });
}

async function closeCard(page) {
  // pick something else is not needed; a reload-free reset: press Escape is
  // not a world action, so just check the next selection replaces the card.
  await page.waitForTimeout(50);
}

const COUNTRIES = [['US', 'United States'], ['FRA', 'France'], ['BR', 'Brazil']];

for (const [w, h] of [[1280, 800], [1920, 1080]]) {
  console.log(`Mouse clicks at ${w}x${h}`);
  const { ctx, page, errors } = await signedInPage({ viewport: { width: w, height: h } });
  for (const [id, label] of COUNTRIES) {
    const p = await countryPixel(page, id);
    ok(p && p.topElement === 'stage', `${label}: drawn on screen and not covered (${p ? `${Math.round(p.x)},${Math.round(p.y)} top=${p.topElement}` : 'off-screen'})`);
    if (!p) continue;
    await page.mouse.move(p.x - 40, p.y - 30, { steps: 4 });
    await page.mouse.move(p.x, p.y, { steps: 6 });
    await page.mouse.click(p.x, p.y);
    await page.waitForTimeout(250);
    const s = await page.evaluate(() => ({ country: window.__wdDebug().country, title: (document.querySelector('#select-card h2') || {}).textContent }));
    ok(s.country === id && s.title === p.name, `${label}: plain mouse click selects it`, JSON.stringify(s));
    ok(await playVisible(page), `${label}: Play button is visible and clickable`);
    await closeCard(page);
  }
  // A slightly shaky, slow click (real hands / remote desktops) still selects.
  const p = await countryPixel(page, 'BR');
  await page.mouse.click(10, 10); // sky, nothing
  const us = await countryPixel(page, 'US');
  await page.mouse.move(us.x, us.y);
  await page.mouse.down();
  await page.mouse.move(us.x + 3, us.y + 2, { steps: 3 });
  await page.waitForTimeout(450);
  await page.mouse.up();
  await page.waitForTimeout(200);
  ok((await page.evaluate(() => window.__wdDebug().country)) === 'US', 'slow click with 3px jitter still selects (not treated as a drag)');
  // A real drag pans and does not select.
  await page.mouse.move(p.x, p.y);
  await page.mouse.down();
  await page.mouse.move(p.x + 120, p.y + 60, { steps: 10 });
  await page.mouse.up();
  await page.waitForTimeout(200);
  ok((await page.evaluate(() => window.__wdDebug().country)) === 'US', 'a drag pans instead of selecting Brazil');
  // After panning and zooming, clicks still hit what is drawn.
  await page.mouse.move(w * 0.4, h * 0.5);
  await page.mouse.wheel(0, -400);
  await page.waitForTimeout(250);
  for (const [id, label] of COUNTRIES) {
    const q = await countryPixel(page, id);
    if (!q || q.topElement !== 'stage') continue;
    await page.mouse.click(q.x, q.y);
    await page.waitForTimeout(200);
    ok((await page.evaluate(() => window.__wdDebug().country)) === id, `${label}: click after pan + zoom selects it`);
  }
  ok(errors.length === 0, 'no page errors', errors.join(' | '));
  await ctx.close();
}

console.log('Clicks with a transparent layer above the map from page load (1280x800)');
{
  // Browser extensions (password managers, remote-desktop / automation
  // cursor overlays) can put a transparent full-page element above the
  // canvas from document_start. The map must still get plain clicks.
  const { ctx, page, errors } = await signedInPage({ viewport: { width: 1280, height: 800 } }, () => {
    const add = () => {
      if (document.getElementById('ext-overlay')) return;
      const d = document.createElement('div');
      d.id = 'ext-overlay';
      d.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:transparent;pointer-events:auto';
      document.documentElement.appendChild(d);
    };
    // only cover the map once signed in, so the sign-up form stays usable
    const timer = setInterval(() => {
      const m = document.getElementById('modal');
      if (m && m.hidden && document.getElementById('user-slot') && document.getElementById('user-slot').textContent.trim()) { add(); clearInterval(timer); }
    }, 50);
  });
  await page.waitForSelector('#ext-overlay', { state: 'attached' });
  for (const [id, label] of COUNTRIES) {
    const q = await countryPixel(page, id);
    if (!q) { ok(false, `${label}: on screen`); continue; }
    await page.mouse.click(q.x, q.y);
    await page.waitForTimeout(250);
    ok((await page.evaluate(() => window.__wdDebug().country)) === id, `${label}: click through a transparent overlay selects it (top element: ${q.topElement})`);
  }
  ok(errors.length === 0, 'no page errors (overlay)', errors.join(' | '));
  await ctx.close();
}

console.log('Touch taps at 390x844');
{
  const { ctx, page, errors } = await signedInPage({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 });
  for (const [id, label] of [['US', 'United States'], ['BR', 'Brazil']]) {
    const p = await countryPixel(page, id);
    if (!p || p.topElement !== 'stage') { ok(false, `${label}: tappable on phone`, JSON.stringify(p)); continue; }
    await page.touchscreen.tap(p.x, p.y);
    await page.waitForTimeout(250);
    ok((await page.evaluate(() => window.__wdDebug().country)) === id, `${label}: touch tap selects it`);
    ok(await playVisible(page), `${label}: Play button visible on phone`);
  }
  ok(errors.length === 0, 'no page errors (touch)', errors.join(' | '));
  await ctx.close();
}

console.log('Guided first loop with pauses, blur and the in-game leave dialog (1280x800)');
{
  const { ctx, page, errors } = await signedInPage({ viewport: { width: 1280, height: 800 } });
  const p = await countryPixel(page, 'US');
  await page.mouse.click(p.x, p.y);
  await page.waitForTimeout(200);
  const pb = await page.locator('#play-btn').boundingBox();
  await page.mouse.click(pb.x + pb.width / 2, pb.y + pb.height / 2);
  await page.waitForFunction(() => window.__wdDebug().mode === 'arena' && window.__wdDebug().guide, null, { timeout: 15000 });
  await page.waitForTimeout(600);
  const look = await page.evaluate(() => ({ t: window.__wdDebug().terrain, mm: !document.getElementById('minimap-wrap').hidden, podium: getComputedStyle(document.getElementById('podium')).display }));
  ok(look.t && look.t.tiles > 0, `arena draws the country terrain texture (${look.t ? look.t.tiles : 0} tiles)`);
  ok(look.mm, 'country minimap is shown in the arena');
  const KEY = { up: 'KeyW', down: 'KeyS', left: 'KeyA', right: 'KeyD' };
  const start = await page.evaluate(() => window.__wdDebug());
  const before = start.player.pct;
  const botStart = Math.max(...start.bots.map((b) => b.pct));

  // 20 s idle with the tab hidden (another tab in front), like a slow tester
  const other = await ctx.newPage();
  await other.goto('about:blank');
  await other.bringToFront();
  await other.waitForTimeout(20000);
  await page.bringToFront();
  await page.waitForTimeout(500);
  let botPeak = 0;
  const sampleBots = async () => {
    const s = await page.evaluate(() => window.__wdDebug().bots);
    botPeak = Math.max(botPeak, ...s.map((b) => b.pct));
  };
  await sampleBots();

  for (let i = 0; i < start.guide.waypoints.length; i++) {
    const wp = start.guide.waypoints[i];
    const key = KEY[wp.screen];
    await page.keyboard.down(key);
    const t0 = Date.now();
    for (;;) {
      const s = await page.evaluate(() => window.__wdDebug().player);
      const axis = key === 'KeyW' || key === 'KeyS' ? 'y' : 'x';
      const dir = key === 'KeyW' || key === 'KeyA' ? -1 : 1;
      if (s.dead || (s[axis] - wp[axis]) * dir >= -0.15 || Date.now() - t0 > 8000) break;
      await page.waitForTimeout(8);
    }
    await page.keyboard.up(key);
    if (i === 1) {
      // mid-trail: window loses focus for a while, then comes back
      await page.evaluate(() => window.dispatchEvent(new Event('blur')));
      await page.waitForTimeout(4000);
      await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    }
    await page.waitForTimeout(150);
    await sampleBots();
  }
  await page.waitForTimeout(400);
  const after = await page.evaluate(() => ({ d: window.__wdDebug(), banner: document.getElementById('tutorial-banner').textContent }));
  ok(after.d.captures >= 1, 'closing the guided loop registers a capture', JSON.stringify(after.d.player));
  ok(after.d.player.pct > before * 3, `land % jumps clearly (${before.toFixed(2)}% → ${after.d.player.pct.toFixed(2)}%)`);
  ok(/Loop closed!/.test(after.banner), 'banner announces the claimed land', after.banner);
  ok(botPeak > botStart * 1.5, `practice bots keep and grow land (${botStart.toFixed(2)}% → peak ${botPeak.toFixed(2)}%)`);

  // Esc opens the in-game dialog; "Keep playing" closes it without leaving
  await page.keyboard.press('Escape');
  await page.waitForSelector('.leave-card');
  const keep = await page.locator('[data-keep]').boundingBox();
  await page.mouse.click(keep.x + keep.width / 2, keep.y + keep.height / 2);
  await page.waitForTimeout(300);
  ok((await page.evaluate(() => window.__wdDebug().mode)) === 'arena' && !(await page.$('.leave-card')), 'Keep playing returns to the match');
  // leave dialog open for a while (a human reading it), then Leave
  await page.locator('#leave-btn').click();
  await page.waitForSelector('.leave-card');
  await page.waitForTimeout(6000);
  const leave = await page.locator('[data-leave]').boundingBox();
  await page.mouse.click(leave.x + leave.width / 2, leave.y + leave.height / 2);
  await page.waitForSelector('.end-card [data-close-world]', { timeout: 20000 });
  const end = await page.evaluate(() => document.querySelector('.end-card').innerText);
  const m = end.match(/Official score \(server-checked\): ([\d.]+)%/);
  ok(/Result saved/.test(end) && !/invalid|not recorded/i.test(end), 'result is saved by the server (no heartbeat error)', end.replace(/\s+/g, ' ').slice(0, 220));
  ok(m && +m[1] > before * 3, `server-checked score keeps the jump (${m ? m[1] : '?'}%)`);
  const back = await page.locator('[data-close-world]').boundingBox();
  await page.mouse.click(back.x + back.width / 2, back.y + back.height / 2);
  await page.waitForTimeout(500);
  const pod = await page.evaluate(() => ({ text: document.getElementById('podium').innerText, shown: getComputedStyle(document.getElementById('podium')).display !== 'none' }));
  ok(pod.shown && pod.text.includes('Pointer Test'), 'the saved score appears on the podium after leaving', pod.text.replace(/\s+/g, ' '));
  const board = await page.evaluate(async () => (await (await fetch('api/leaderboard', { credentials: 'same-origin' })).json()));
  ok(Array.isArray(board.podium) && board.podium.some((r) => r.displayName === 'Pointer Test' && r.points > 0 && !r.isBot), 'leaderboard API lists the saved score', JSON.stringify(board.podium));
  ok(errors.length === 0, 'no page errors or native dialogs (loop)', errors.join(' | '));
  await ctx.close();
}

await browser.close();
if (server) server.kill();
if (tmp) rmSync(tmp, { recursive: true, force: true });
console.log(`${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
