// Health diagnosis: specific, non-sensitive reason codes.
//   PHP_BIN=php node worlddomination/tests/health.test.mjs
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
let pass = 0;
let fail = 0;
const ok = (c, n, x = '') => { if (c) { pass++; console.log('  ok  ' + n); } else { fail++; console.log('  FAIL ' + n + (x ? ' — ' + x : '')); } };
const freePort = () => new Promise((res) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });

async function health(env) {
  const port = await freePort();
  const srv = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', root, join(root, 'index.php')], { env: { PATH: process.env.PATH, ...env }, stdio: ['ignore', 'ignore', 'pipe'] });
  let log = '';
  srv.stderr.on('data', (d) => { log += d; });
  let body = null;
  let status = 0;
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${port}/api/health`);
      status = r.status;
      body = await r.text();
      break;
    } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  srv.kill();
  await new Promise((r) => setTimeout(r, 100));
  return { status, body, json: body ? JSON.parse(body) : null, log };
}

const tmp = mkdtempSync(join(tmpdir(), 'wd-health-'));
const SECRET = 'S3cr3t-Pa55-Never-Shown';
const HOST = '127.0.0.9';

const bad = await health({ WD_DB_DRIVER: 'mysql', WD_DB_HOST: HOST, WD_DB_PORT: '1', WD_DB_NAME: 'wd_secret_name', WD_DB_USER: 'wd_secret_user', WD_DB_PASS: SECRET });
ok(bad.status === 503, 'unreachable MySQL -> 503', String(bad.status));
ok(bad.json && bad.json.reason === 'connect_failed', 'reason is connect_failed', bad.body);
ok(bad.json && (typeof bad.json.code === 'number' || typeof bad.json.code === 'string'), `a numeric driver code is included (${bad.json && bad.json.code})`);
ok(bad.json && bad.json.source === 'env', 'config source is named (env), not its values', bad.body);
ok(!bad.body.includes(SECRET) && !bad.body.includes(HOST) && !bad.body.includes('wd_secret'), 'no password, host, user or db name in the response');
ok(/worlddomination db connect/.test(bad.log), 'the PHP error is logged server-side');
ok(!bad.log.includes(SECRET), 'the password is not written to the log');

const good = await health({ WD_DB_DRIVER: 'sqlite', WD_DB_SQLITE: join(tmp, 'h.sqlite') });
ok(good.status === 200 && good.json.ok === true && good.json.db === true, 'working database -> 200 ok/db true', good.body);

rmSync(tmp, { recursive: true, force: true });
console.log(`${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
