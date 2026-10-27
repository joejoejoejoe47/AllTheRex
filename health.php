<?php
/**
 * Diagnostic page. Open:  /health.php?token=YOUR_SETUP_TOKEN
 * Checks each piece of the app step by step and prints exactly what fails. Always returns HTTP 200 so the
 * server's custom error pages can't hide the message. DELETE THIS FILE when you're done.
 */
define('REX_ROOT', __DIR__);
$cfgFile = __DIR__ . '/config.php';
$cfg = is_file($cfgFile) ? (require $cfgFile) : [];
$token = (string)($cfg['setup_token'] ?? '');
header('Content-Type: text/plain; charset=utf-8');
if (strlen($token) < 12 || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
    exit("Add ?token=<your setup_token from config.php> to the address.\n");
}
ini_set('display_errors', '1');
error_reporting(E_ALL);

function step(string $name, callable $fn): void {
    try { $r = $fn(); echo "[ ok ] $name" . ($r !== null && $r !== true ? " -> $r" : '') . "\n"; }
    catch (Throwable $e) { echo "[FAIL] $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n       at " . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
}
echo "PHP " . PHP_VERSION . " on " . PHP_OS_FAMILY . "\n";
foreach (['pdo_mysql', 'mbstring', 'curl', 'json'] as $x) { echo "ext $x: " . (extension_loaded($x) ? 'yes' : 'NO') . "\n"; }
echo "\n";
foreach (['db', 'schema', 'helpers', 'auth', 'images', 'catalog', 'recommender', 'view'] as $f) {
    step("load lib/$f.php", function () use ($f) { require_once __DIR__ . "/lib/$f.php"; });
}
mb_internal_encoding('UTF-8');
step('connect to database', function () { return db()->query('SELECT VERSION()')->fetchColumn(); });
if (isset($_GET['fix'])) {
    step('FIX: create/upgrade tables (adds missing columns, keeps all data)', function () { rex_install(db()); return 'done'; });
}
step('items columns', function () {
    $c = array_column(db()->query('SHOW COLUMNS FROM items')->fetchAll(), 'Field');
    $need = ['image_url', 'image_tried'];
    $miss = array_diff($need, $c);
    if ($miss) { throw new RuntimeException('missing columns: ' . implode(', ', $miss) . '  (open this page again with &fix=1 added to the address)'); }
    return implode(',', $c);
});
step('counts', function () {
    return json_encode(q1('SELECT (SELECT COUNT(*) FROM items) items, (SELECT COUNT(*) FROM items WHERE image_url IS NOT NULL) with_images, (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM ratings) ratings'));
});
foreach (['movie', 'album', 'book', null] as $cat) {
    step('list ' . ($cat ?? 'all') . ' (query)', function () use ($cat) { [$rows, $t] = catalog_list($cat, '', 'popular', 1, null); return count($rows) . " rows of $t"; });
    step('list ' . ($cat ?? 'all') . ' (render each card)', function () use ($cat) {
        [$rows] = catalog_list($cat, '', 'popular', 1, null);
        ob_start();
        try { foreach ($rows as $r) { try { item_card($r, 'browse.php'); } catch (Throwable $e) { throw new RuntimeException('card for "' . $r['title'] . '": ' . $e->getMessage()); } } }
        finally { ob_end_clean(); }
        return count($rows) . ' cards';
    });
}
step('recommender tables', function () { return (int)q1('SELECT COUNT(*) c FROM item_sim')['c'] . ' similarity rows'; });
echo "\nDone. Send this whole output back, then delete health.php.\n";
