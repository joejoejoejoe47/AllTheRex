<?php

declare(strict_types=1);

require __DIR__ . '/../app/src/bootstrap.php';

use WorldDomination\Auth;
use WorldDomination\Db;

function test_db(): void
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    Db::useConnection($pdo, 'sqlite');
    Db::runSchema((string) file_get_contents(__DIR__ . '/../app/sql/schema.sql'));
    Auth::resetCache();
    $_COOKIE = [];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTPS'] = '';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
}

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];

function check(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['__t']['pass']++;
        echo "  ok  $label\n";
    } else {
        $GLOBALS['__t']['fail']++;
        fwrite(STDERR, "  FAIL: $label\n");
    }
}

function finish(): never
{
    $t = $GLOBALS['__t'];
    echo "{$t['pass']} passed, {$t['fail']} failed\n";
    exit($t['fail'] > 0 ? 1 : 0);
}
