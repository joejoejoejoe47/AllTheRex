// Real-browser end-to-end test for Saint Quest. Drives headless Chrome with
// real pointer and keyboard input (Playwright clicks = real mouse events);
// game state is only *read* (window.__sq, __sqHome, __sqBattle.debug()).
//
//   node saintquest/tests/browser/saintquest.e2e.mjs
//
// Env: CHROME_BIN (default /usr/bin/google-chrome), PHP_BIN (default php),
// PLAYWRIGHT_CORE (module path, default 'playwright-core'), BASE (use an
// already running server instead of an isolated SQLite one), SHOTS (folder
// for screenshots).
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync, mkdirSync } from 'node:fs';
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
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function freePort() {
  return new Promise((res) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });
}

let server = null;
let tmp = mkdtempSync(join(tmpdir(), 'sq-e2e-'));
let BASE = process.env.BASE;
if (!BASE) {
  const port = await freePort();
  mkdirSync(join(tmp, 'storage'));
  server = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', root, join(root, 'index.php')], {
    env: { ...process.env, DB_DRIVER: 'sqlite', DB_SQLITE: join(tmp, 'e2e.sqlite'), SQ_STORAGE: join(tmp, 'storage'), APP_DEBUG: '0' },
    stdio: 'ignore',
  });
  BASE = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 80; i++) {
    try { const r = await fetch(BASE + '/api/health'); if (r.ok) break; } catch { /* starting */ }
    await sleep(100);
  }
}
const SHOTS = process.env.SHOTS || '';
if (SHOTS) mkdirSync(SHOTS, { recursive: true });
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: join(SHOTS, name + '.png') }); };

const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome',
  headless: true,
  args: ['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
});
const errors = [];
const stamp = Date.now().toString(36).slice(-5);

async function newPlayer(label, viewport = { width: 1280, height: 720 }, extra = {}) {
  const ctx = await browser.newContext({ viewport, ...extra });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => errors.push(`${label}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource: the server responded with a status of 4\d\d/.test(m.text())) errors.push(`${label} console: ${m.text()}`); });
  page.on('dialog', (d) => d.dismiss());
  return { ctx, page };
}

async function signup(page, name, pass = 'Holy-Password-7') {
  await page.goto(BASE + '/');
  await page.waitForSelector('#auth-form', { timeout: 20000 });
  const tab = page.locator('#auth [data-mode=signup]');
  if (await tab.count()) await tab.click();
  await page.locator('input[name=username]').click();
  await page.keyboard.type(name);
  await page.locator('input[name=password]').click();
  await page.keyboard.type(pass);
  await page.locator('#auth-form button[type=submit]').click();
  await page.waitForSelector('#profile:not([hidden])', { timeout: 15000 });
}
const me = (page) => page.evaluate(() => JSON.parse(JSON.stringify(window.__sq.me)));
const closeModal = async (page) => { while (await page.locator('.modal-close').count()) { await page.locator('.modal-close').last().click(); await sleep(150); } };
const waitFor = async (fn, ms = 15000, step = 200) => { const t = Date.now(); while (Date.now() - t < ms) { if (await fn()) return true; await sleep(step); } return false; };

try {
  // ---------- health & private paths ----------
  const h = await (await fetch(BASE + '/api/health')).json();
  ok(h.ok === true && h.db === true, 'health reports ok with the database', JSON.stringify(h));
  for (const p of ['/app/.env', '/app/src/Auth.php', '/tests/AuthTest.php', '/app/sql/schema.sql']) {
    const r = await fetch(BASE + p);
    ok(r.status === 403 || r.status === 404, `private path ${p} is blocked (${r.status})`);
  }

  // ---------- A: sign up, home ----------
  const A = await newPlayer('A');
  const nameA = 'Ann' + stamp;
  await signup(A.page, nameA);
  let mA = await me(A.page);
  ok(mA.username === nameA && mA.saints.length === 1 && mA.saints[0].key === 'francis', 'signup gives a starter saint (St. Francis)');
  ok(mA.virtue === 200, 'new player starts with 200 virtue points', String(mA.virtue));
  ok(await waitFor(() => A.page.evaluate(() => window.__sqHome.loaded && window.__sqHome.key === 'francis')), 'home shows the 3D starter saint');
  await sleep(800);
  const box = await A.page.locator('#saint-canvas').boundingBox();
  const withSaint = await A.page.screenshot({ clip: box });
  await A.page.evaluate(() => { document.querySelector('#saint-canvas').style.visibility = 'hidden'; });
  const withoutSaint = await A.page.screenshot({ clip: box });
  await A.page.evaluate(() => { document.querySelector('#saint-canvas').style.visibility = ''; });
  ok(!withSaint.equals(withoutSaint), 'the 3D saint is actually drawn on the path');
  ok((await A.page.textContent('#saint-plate')).includes('Francis'), 'name plate shows the home saint');
  ok(await A.page.locator('#store-btn').isVisible() && await A.page.locator('#battle-btn').isVisible(), 'Store and Holy Battle buttons are visible');
  ok((await A.page.textContent('#leaderboard')).includes(nameA), 'leaderboard lists the new player');
  await shot(A.page, 'home');

  // ---------- good deeds (villagers) ----------
  for (const deed of ['apples', 'lamb', 'chapel']) {
    await A.page.locator(`#villager-${deed}`).click();
    await A.page.waitForSelector('#deed-play .target');
    for (let i = 0; i < 12; i++) {
      const t = A.page.locator('#deed-play .target').first();
      if (!(await t.count())) break;
      await t.click({ force: true });
      await sleep(120);
      if (await A.page.locator('.modal h2:has-text("Good deed done")').count()) break;
    }
    await A.page.waitForSelector('.modal h2:has-text("Good deed done")', { timeout: 8000 });
    await closeModal(A.page);
  }
  mA = await me(A.page);
  ok(mA.goodDeeds === 3 && mA.virtue === 275, 'three good deeds give +75 virtue and 3 Good Deeds', `${mA.goodDeeds} / ${mA.virtue}`);
  ok((await A.page.textContent('#deeds-pill')).includes('3'), 'Good Deeds counter shows 3');

  // ---------- store purchase ----------
  await A.page.locator('#store-btn').click();
  await A.page.waitForSelector('.modal .grid .card');
  await shot(A.page, 'store');
  await A.page.locator('.modal .card button.open', { hasText: 'Isidore' }).click();
  await A.page.locator('.modal .btn.green', { hasText: 'Buy for' }).click();
  ok(await waitFor(async () => (await me(A.page)).saints.length === 2), 'buying St. Isidore in the store adds a saint');
  mA = await me(A.page);
  ok(mA.virtue === 25, 'store purchase debits 250 virtue', String(mA.virtue));
  await closeModal(A.page);

  // ---------- signpost switches saints ----------
  const before = await A.page.evaluate(() => window.__sqHome.key);
  await A.page.locator('#signpost').click();
  ok(await waitFor(() => A.page.evaluate((b) => window.__sqHome.loaded && window.__sqHome.key !== b, before)), 'signpost arrow switches to the other saint');
  ok(!(await A.page.textContent('#saint-plate')).includes(before === 'francis' ? 'Francis' : 'Isidore'), 'name plate follows the signpost');

  // ---------- trade listing ----------
  const isidore = mA.saints.find((s) => s.key === 'isidore');
  await A.page.locator('#trade-btn').click();
  await A.page.waitForSelector('#trade-drop .plate');
  await A.page.locator('#trade-drop .saint-strip button', { hasText: 'Isidore' }).click();
  await A.page.locator('#trade-price').fill('');
  await A.page.locator('#trade-price').click();
  await A.page.keyboard.type('20');
  await A.page.locator('#trade-story').click();
  await A.page.keyboard.type('He helped me plant my garden with patience.');
  await shot(A.page, 'trade');
  await A.page.locator('#trade-submit').click();
  ok(await waitFor(async () => (await me(A.page)).saints.find((s) => s.id === isidore.id).status === 'listed'), 'trade plate lists St. Isidore for 20 virtue');
  await A.page.locator('#trade-drop [data-tab=market]').click();
  await A.page.waitForSelector('#trade-drop .listing');
  ok((await A.page.textContent('#trade-drop .market-list')).includes('plant my garden'), 'market shows the benefit story');
  await A.page.locator('#trade-drop .btn.ghost', { hasText: 'Close' }).click();

  // ---------- B: buys the listing, comments with a picture ----------
  const B = await newPlayer('B', { width: 390, height: 844 }, { isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const nameB = 'Ben' + stamp;
  await signup(B.page, nameB);
  ok(await waitFor(() => B.page.evaluate(() => window.__sqHome.loaded)), 'phone layout shows the home saint');
  await shot(B.page, 'home-phone');
  await B.page.locator('#trade-btn').tap();
  await B.page.locator('#trade-drop [data-tab=market]').tap();
  await B.page.locator('#trade-drop .listing', { hasText: 'Isidore' }).tap();
  await B.page.locator('#buy-listing').tap();
  ok(await waitFor(async () => (await me(B.page)).saints.some((s) => s.id === isidore.id)), 'B buys the listing (saint moves atomically)');
  const mB = await me(B.page);
  ok(mB.virtue === 180, 'buyer is debited the price', String(mB.virtue));
  await A.page.evaluate(() => window.__sqRefresh(true));
  mA = await me(A.page);
  ok(mA.virtue === 45 && !mA.saints.some((s) => s.id === isidore.id), 'seller is credited and loses the saint', String(mA.virtue));
  await closeModal(B.page);
  if (await B.page.locator('#trade-drop:not([hidden])').count()) await B.page.locator('#trade-drop .btn.ghost', { hasText: 'Close' }).tap();

  // B finds A with the search bar and comments on A's Francis with a picture.
  await B.page.locator('#search').tap();
  await B.page.keyboard.type(nameA);
  await B.page.locator('#search-results button', { hasText: nameA }).first().tap();
  await B.page.locator('.modal .card button.open', { hasText: 'Francis' }).tap();
  await B.page.locator('#comment-btn').tap();
  await B.page.locator('#comment-text').tap();
  await B.page.keyboard.type('What a kind saint! Here is my drawing.');
  const png = join(tmp, 'drawing.png');
  writeFileSync(png, await B.page.screenshot({ clip: { x: 0, y: 0, width: 120, height: 90 } }));
  const [chooser] = await Promise.all([B.page.waitForEvent('filechooser'), B.page.locator('label[for=comment-file]').tap()]);
  await chooser.setFiles(png);
  await B.page.locator('#comment-send').tap();
  ok(await waitFor(() => B.page.locator('.comment img').count().then((n) => n > 0)), 'comment with an uploaded picture appears');
  const imgSrc = await B.page.locator('.comment img').first().getAttribute('src');
  const ir = await fetch(new URL(imgSrc, BASE));
  ok(ir.status === 200 && ir.headers.get('content-type') === 'image/jpeg' && ir.headers.get('x-content-type-options') === 'nosniff', 'comment picture is served as a re-encoded JPEG with nosniff');
  await closeModal(B.page);
  await A.page.evaluate(() => window.__sqRefresh(true));
  await A.page.locator('#bell-btn').click();
  ok(await waitFor(() => A.page.locator('.note', { hasText: nameB }).count().then((n) => n > 0)), 'saint owner is notified about the comment', await A.page.locator('.modal').last().textContent().catch(() => 'no modal'));
  await closeModal(A.page);

  // ---------- cards: print, add by typed code, add by QR picture ----------
  await A.page.locator('#add-btn').click();
  await A.page.locator('.modal a', { hasText: 'Print some saint cards' }).click();
  await A.page.locator('#print-count').selectOption('2');
  await A.page.locator('#print-make').click();
  await A.page.waitForSelector('.saint-card .sc-qr');
  await sleep(300);
  const codes = await A.page.$$eval('.saint-card', (els) => els.map((e) => e.dataset.code));
  ok(codes.length === 2 && codes.every((c) => /^SQ-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/.test(c)), 'printing makes cards with unguessable codes');
  const qrPng = join(tmp, 'card-qr.png');
  writeFileSync(qrPng, await A.page.locator(`.saint-card[data-code="${codes[1]}"]`).screenshot());
  await closeModal(A.page);

  const nA0 = (await me(A.page)).saints.length;
  await A.page.locator('#add-btn').click();
  await A.page.locator('.modal [data-mode=type]').click();
  await A.page.locator('#card-code').click();
  await A.page.keyboard.type(codes[0].toLowerCase().replace(/-/g, ' '));
  await A.page.locator('#card-submit').click();
  ok(await waitFor(async () => (await me(A.page)).saints.length === nA0 + 1), 'card added by typing its code');
  await closeModal(A.page);

  await A.page.locator('#add-btn').click();
  await A.page.locator('.modal [data-mode=photo]').click();
  const [qrChooser] = await Promise.all([A.page.waitForEvent('filechooser'), A.page.locator('label[for=card-photo]').click()]);
  await qrChooser.setFiles(qrPng);
  ok(await waitFor(async () => (await me(A.page)).saints.length === nA0 + 2), 'card added by reading its QR code from a photo');
  await closeModal(A.page);

  // Same account re-scan does nothing.
  await A.page.locator('#add-btn').click();
  await A.page.locator('.modal [data-mode=type]').click();
  await A.page.locator('#card-code').click();
  await A.page.keyboard.type(codes[0]);
  await A.page.locator('#card-submit').click();
  await sleep(800);
  ok((await A.page.textContent('.modal')).match(/already yours|already have/i) && (await me(A.page)).saints.length === nA0 + 2, 'scanning your own card again does not duplicate it');
  await closeModal(A.page);

  // ---------- dispute: B scans A's card, A says No and sends a photo ----------
  await B.page.locator('#add-btn').tap();
  await B.page.locator('.modal [data-mode=type]').tap();
  await B.page.locator('#card-code').tap();
  await B.page.keyboard.type(codes[0]);
  await B.page.locator('#card-submit').tap();
  ok(await waitFor(() => B.page.locator('.modal h2:has-text("Card check started")').count().then((n) => n > 0)), 'a different account scanning a registered card starts a card check');
  await closeModal(B.page);

  await A.page.reload();
  await A.page.waitForSelector('#profile:not([hidden])');
  ok(await waitFor(() => A.page.locator('.question .q-no').count().then((n) => n > 0)), 'the first owner is asked "Did you trade this card?"');
  await A.page.locator('.question .q-no').click();
  ok(await waitFor(() => A.page.locator('.question .q-photo').count().then((n) => n > 0)), 'saying No asks the owner for a photo');
  const [cardPhoto] = await Promise.all([A.page.waitForEvent('filechooser'), A.page.locator('.question .q-photo').first().click()]);
  await cardPhoto.setFiles(qrPng);
  ok(await waitFor(() => A.page.locator('.question[data-status=disputed]').count().then((n) => n > 0)), 'owner photo puts the card in dispute');
  await A.page.evaluate(() => window.__sqRefresh(true));
  const frozen = (await me(A.page)).saints.filter((s) => s.status === 'frozen');
  ok(frozen.length === 1, 'the disputed saint is frozen');
  await closeModal(A.page);
  await B.page.evaluate(() => window.__sqRefresh(true));
  await B.page.locator('#bell-btn').tap();
  ok(await waitFor(() => B.page.locator('.question[data-status=disputed]', { hasText: 'photo' }).count().then((n) => n > 0)), 'claimant sees the dispute status');
  await shot(B.page, 'dispute-phone');
  await closeModal(B.page);

  // ---------- Holy Battle (duel) ----------
  const battlesBefore = (await me(A.page)).lifetime;
  await A.page.locator('#battle-btn').click();
  await A.page.waitForSelector('.game-card');
  await shot(A.page, 'battle-select');
  await A.page.locator('.game-card[data-game=duel]').click();
  await A.page.waitForSelector('#battle-root canvas');
  await waitFor(() => A.page.evaluate(() => window.__sqBattle && window.__sqBattle.running), 15000);
  const shine = A.page.locator('#battle-root .round-btn', { hasText: 'Shine' });
  const shield = A.page.locator('#battle-root .round-btn', { hasText: 'Shield' });
  let shotTaken = false;
  const t0 = Date.now();
  while (Date.now() - t0 < 90000) {
    if (await A.page.locator('#battle-result').count()) break;
    const d = await A.page.evaluate(() => window.__sqBattle.debug());
    if (d.over) { await sleep(300); continue; }
    if (await shine.isEnabled().catch(() => false)) await shine.click({ timeout: 1000 }).catch(() => {});
    if (d.myHp < 60 && await shield.isEnabled().catch(() => false)) await shield.click({ timeout: 1000 }).catch(() => {});
    if (!shotTaken && Date.now() - t0 > 3000) { await shot(A.page, 'battle'); shotTaken = true; }
    await sleep(250);
  }
  await A.page.waitForSelector('#battle-result', { timeout: 15000 });
  const resultText = await A.page.textContent('#battle-result');
  ok(/\+\d+ virtue points/.test(resultText) && !/\+0 virtue/.test(resultText), 'battle finishes and pays virtue', resultText);
  await A.page.locator('#battle-result button', { hasText: 'Home' }).click();
  mA = await me(A.page);
  ok(mA.lifetime > battlesBefore, 'battle reward is saved', `${battlesBefore} -> ${mA.lifetime}`);

  ok(errors.length === 0, 'no page errors', errors.join(' | '));
  await A.ctx.close();
  await B.ctx.close();
} catch (e) {
  fail++;
  console.log('  FAIL exception: ' + (e.stack || e.message));
  if (errors.length) console.log('  page errors: ' + errors.join(' | '));
} finally {
  await browser.close();
  if (server) server.kill();
  rmSync(tmp, { recursive: true, force: true });
}
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
