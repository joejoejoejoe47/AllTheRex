<?php
declare(strict_types=1);
// One-time installer: creates the vchat tables. DELETE THIS FILE from the server afterwards.
require __DIR__ . '/src/bootstrap.php';
security_headers();
header('Content-Type: text/html; charset=utf-8');

$msg = '';
$token = (string)cfg('setup_token');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($token === '' || !hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $msg = 'Wrong token (set setup_token in config.php).';
    } else {
        try {
            foreach (vchat_schema(db()->getAttribute(PDO::ATTR_DRIVER_NAME)) as $sql) { db()->exec($sql); }
            $msg = 'Tables created. Now delete setup.php from the server.';
        } catch (Throwable $e) {
            error_log('[vchat setup] ' . $e->getMessage());
            $msg = 'Failed: ' . (cfg('debug') ? $e->getMessage() : 'check config.php database settings and the PHP error log.');
        }
    }
}
?><!doctype html><meta charset="utf-8"><title>vchat setup</title>
<body style="font:16px system-ui;background:#0f1115;color:#eee;padding:3rem;max-width:34rem">
<h1>vchat setup</h1>
<?php if ($msg): ?><p><b><?= htmlspecialchars($msg) ?></b></p><?php endif; ?>
<form method="post"><p><input type="password" name="token" placeholder="setup_token" style="padding:.6rem;width:100%"></p>
<button style="padding:.6rem 1rem">Create tables</button></form></body>
