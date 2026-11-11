<?php

declare(strict_types=1);

require __DIR__ . '/../app/src/bootstrap.php';

$__sqStorage = sys_get_temp_dir() . '/sq-test-storage-' . getmypid();
@mkdir($__sqStorage, 0775, true);
putenv('SQ_STORAGE=' . $__sqStorage);
register_shutdown_function(static function () use ($__sqStorage): void { exec('rm -rf ' . escapeshellarg($__sqStorage)); });

use SaintQuest\Auth;
use SaintQuest\Db;

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

function expect_error(callable $fn, int $status, string $label): void
{
    try {
        $fn();
        check(false, $label . ' (no error thrown)');
    } catch (\SaintQuest\HttpError $e) {
        check($e->status === $status, $label . " (got {$e->status}: {$e->getMessage()})");
    }
}

/** Create a user without going through cookies/HTTP; returns id. */
function make_user(string $name, int $virtue = 200): string
{
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 250);
    $u = \SaintQuest\Auth::signUp($name, 'Password-123');
    \SaintQuest\Db::run('UPDATE sq_users SET virtue = ? WHERE id = ?', [$virtue, $u['id']]);
    \SaintQuest\Auth::resetCache();
    $_COOKIE = [];
    return $u['id'];
}

function virtue(string $id): int
{
    return (int) \SaintQuest\Db::value('SELECT virtue FROM sq_users WHERE id = ?', [$id]);
}
