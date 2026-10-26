<?php
declare(strict_types=1);

function rex_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $defaults = [
            'db_host' => 'localhost', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => '',
            'setup_token' => '', 'site_name' => 'AllTheRex', 'debug' => false,
        ];
        $file = REX_ROOT . '/config.php';
        $loaded = is_file($file) ? require $file : [];
        $cfg = array_merge($defaults, is_array($loaded) ? $loaded : []);
    }
    return $cfg;
}

/** Stop with a plain error page that never reveals credentials. */
function rex_fatal(string $message, ?Throwable $e = null): never
{
    if ($e) {
        error_log('[rex] ' . $message . ': ' . $e->getMessage());
    }
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detail = (rex_config()['debug'] && $e) ? "\n" . $e->getMessage() : '';
    echo PHP_SAPI === 'cli'
        ? $message . $detail . "\n"
        : '<!doctype html><meta charset="utf-8"><title>Error</title>'
          . '<body style="font:16px system-ui;background:#14110f;color:#eee;padding:3rem">'
          . '<h1>Something went wrong</h1><p>' . htmlspecialchars($message) . '</p>'
          . ($detail ? '<pre>' . htmlspecialchars($detail) . '</pre>' : '') . '</body>';
    exit(1);
}

function db(): PDO
{
    // Tests inject their own connection here.
    if (isset($GLOBALS['REX_PDO']) && $GLOBALS['REX_PDO'] instanceof PDO) {
        return $GLOBALS['REX_PDO'];
    }
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = rex_config();
    if ($c['db_name'] === '' || $c['db_user'] === '') {
        rex_fatal('The site is not configured yet. Create config.php from config.sample.php.');
    }
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)$c['db_port'], $c['db_name']),
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );
    } catch (Throwable $e) {
        rex_fatal('Could not connect to the database.', $e);
    }
    return $pdo;
}

/** Run a query and return all rows. $types may force PARAM_INT for LIMIT/OFFSET. */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    bind_all($st, $params);
    $st->execute();
    return $st->fetchAll();
}

function q1(string $sql, array $params = []): ?array
{
    $rows = q($sql, $params);
    return $rows[0] ?? null;
}

function qx(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    bind_all($st, $params);
    $st->execute();
    return $st->rowCount();
}

function bind_all(PDOStatement $st, array $params): void
{
    $i = 1;
    foreach ($params as $p) {
        $st->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

/** "?, ?, ?" for an IN (...) list. */
function placeholders(int $n): string
{
    return implode(',', array_fill(0, max(1, $n), '?'));
}
