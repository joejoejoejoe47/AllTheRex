<?php

declare(strict_types=1);

/**
 * One-page installer. Enter the MySQL details Plesk gave you; it tests them,
 * creates the World Domination tables, and saves app/.env.
 * DELETE THIS FILE afterwards.
 */

$appDir = is_dir(__DIR__ . '/app/src') ? __DIR__ . '/app' : dirname(__DIR__);
require $appDir . '/src/bootstrap.php';

use WorldDomination\Db;

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$envPath = $appDir . '/.env';
$error = '';
$manual = '';
$done = false;

$already = false;
if (is_file($envPath) || getenv('DB_NAME') !== false) {
    try {
        Db::pdo();
        $already = true;
    } catch (Throwable $e) {
        $already = false;
    }
}

if (!$already && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $v = [
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => trim((string) ($_POST['host'] ?? 'localhost')) ?: 'localhost',
        'DB_PORT' => (string) ((int) ($_POST['port'] ?? 3306) ?: 3306),
        'DB_NAME' => trim((string) ($_POST['name'] ?? '')),
        'DB_USER' => trim((string) ($_POST['user'] ?? '')),
        'DB_PASS' => (string) ($_POST['pass'] ?? ''),
    ];
    try {
        foreach ($v as $k => $val) {
            putenv("$k=$val");
        }
        Db::reset();
        Db::pdo();
        Db::ensureSchema(true);
        $lines = ['# Written by install.php — World Domination'];
        foreach ($v as $k => $val) {
            $lines[] = $k . '=' . (preg_match('/[\s#"\']/', $val) ? '"' . str_replace('"', '\\"', $val) . '"' : $val);
        }
        $lines[] = 'APP_DEBUG=0';
        $text = implode("\n", $lines) . "\n";
        if (@file_put_contents($envPath, $text) === false) {
            $manual = $text;
            $error = 'The tables were created, but PHP is not allowed to write app/.env. In Plesk File Manager open the worlddomination/app folder, create a file named .env, paste the text below into it and save.';
        } else {
            @chmod($envPath, 0640);
            $done = true;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install World Domination</title>
<style>
  body{font:16px/1.5 system-ui,sans-serif;background:#0a2a4a;color:#eef6ff;margin:0;display:grid;place-items:center;min-height:100vh}
  main{width:min(92vw,30rem);background:#123252;border:1px solid #2c6aa0;border-radius:14px;padding:1.6rem}
  h1{margin:.2rem 0 1rem;font-weight:700} label{display:block;margin:.7rem 0 .2rem;color:#c5def5;font-size:.9rem}
  input{width:100%;box-sizing:border-box;padding:.6rem;border-radius:8px;border:1px solid #2c6aa0;background:#0a2a4a;color:inherit}
  button{margin-top:1.1rem;width:100%;padding:.7rem;border:0;border-radius:8px;background:#f0c14b;color:#1a1408;font-weight:800;cursor:pointer}
  .err{background:#3a1b1b;border:1px solid #7a3333;padding:.7rem;border-radius:8px} .ok{background:#1b3a22;border:1px solid #337a44;padding:.7rem;border-radius:8px}
  textarea{width:100%;box-sizing:border-box;height:11rem;margin-top:.6rem;background:#0a2a4a;color:inherit;border:1px solid #2c6aa0;border-radius:8px;padding:.6rem;font:13px/1.4 ui-monospace,monospace}
  .muted{color:#9ec0dc;font-size:.9rem}
</style>
</head>
<body><main>
<h1>Install World Domination</h1>
<?php if ($already): ?>
  <p class="ok">Already installed — the database connects. Delete <code>install.php</code> from the server, then open the game.</p>
<?php elseif ($done): ?>
  <p class="ok">Done. Tables created and settings saved. Delete <code>install.php</code>, then open the game.</p>
<?php else: ?>
  <p class="muted">Create a MySQL database and user in Plesk first (or reuse an existing database — World Domination tables are prefixed <code>wd_</code> and will not touch Morse Chess tables). The host is usually <code>localhost</code>.</p>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <?php if ($manual): ?><textarea readonly onclick="this.select()"><?= h($manual) ?></textarea><?php endif; ?>
  <form method="post">
    <label>Host <input name="host" value="localhost" required></label>
    <label>Database name <input name="name" required></label>
    <label>Database user <input name="user" required></label>
    <label>Password <input name="pass" type="password"></label>
    <label>Port <input name="port" value="3306"></label>
    <button type="submit">Create tables &amp; save</button>
  </form>
<?php endif; ?>
</main></body></html>
