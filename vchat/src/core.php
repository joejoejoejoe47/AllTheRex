<?php
declare(strict_types=1);

/** Shared plumbing: config, database, JSON I/O, signed tokens, rate limits. */

final class HttpError extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $extra = [])
    {
        parent::__construct($message);
    }
}

function cfg(?string $key = null): mixed
{
    static $c = null;
    if ($c === null) {
        $defaults = [
            'db_dsn' => '', 'db_host' => 'localhost', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => '',
            'session_secret' => '', 'setup_token' => '', 'session_days' => 14, 'max_participants' => 20,
            'trust_proxy' => false, 'debug' => false, 'google_client_id' => '', 'sms_mode' => 'console',
            'twilio_sid' => '', 'twilio_token' => '', 'twilio_from' => '', 'totp_issuer' => 'AllTheRex vchat',
            'sms_ip_limit' => 15, 'stun_urls' => ['stun:stun.l.google.com:19302'], 'turn_urls' => [],
            'turn_secret' => '', 'turn_username' => '', 'turn_credential' => '', 'poll_hold_seconds' => 0,
        ];
        $file = getenv('VCHAT_CONFIG') ?: __DIR__ . '/../config.php';
        $loaded = is_file($file) ? require $file : [];
        $c = array_merge($defaults, is_array($loaded) ? $loaded : []);
        $c['max_participants'] = max(2, min(20, (int)$c['max_participants']));
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = cfg();
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($c['db_dsn'] !== '') { // used by the tests (SQLite)
        $pdo = new PDO($c['db_dsn'], $c['db_user'] ?: null, $c['db_pass'] ?: null, $opts);
    } else {
        if ($c['db_name'] === '' || $c['db_user'] === '') {
            throw new HttpError(500, 'vchat is not configured: create config.php from config.sample.php');
        }
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)$c['db_port'], $c['db_name']),
            $c['db_user'], $c['db_pass'], $opts + [PDO::ATTR_EMULATE_PREPARES => false]);
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    }
    return $pdo;
}

function is_mysql(): bool { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function secret(): string
{
    $s = (string)cfg('session_secret');
    if (strlen($s) < 24) {
        throw new HttpError(500, 'vchat is not configured: set a long session_secret in config.php');
    }
    return $s;
}

/** Signed, expiring token: base64url(json).hmac - used for login cookies and 2-step tickets. */
function sign_token(array $payload, int $ttl): string
{
    $payload['exp'] = time() + $ttl;
    $body = b64u(json_encode($payload));
    return $body . '.' . b64u(hash_hmac('sha256', $body, secret(), true));
}

function verify_token(?string $t): ?array
{
    if (!is_string($t) || substr_count($t, '.') !== 1) {
        return null;
    }
    [$body, $mac] = explode('.', $t);
    if (!hash_equals(b64u(hash_hmac('sha256', $body, secret(), true)), $mac)) {
        return null;
    }
    $p = json_decode(b64u_dec($body), true);
    return is_array($p) && ($p['exp'] ?? 0) > time() ? $p : null;
}

function client_ip(): string
{
    if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return (bool)cfg('trust_proxy') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function cookie_path(): string
{
    $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $d === '' ? '/' : $d . '/';
}

function set_session_cookie(string $userId): void
{
    $ttl = (int)cfg('session_days') * 86400;
    setcookie('vchat_sid', sign_token(['uid' => $userId, 'k' => 'session'], $ttl), [
        'expires' => time() + $ttl, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function clear_session_cookie(): void
{
    setcookie('vchat_sid', '', ['expires' => 1, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function current_user(): ?array
{
    $p = verify_token($_COOKIE['vchat_sid'] ?? null);
    return $p && ($p['k'] ?? '') === 'session' ? user_by_id((string)$p['uid']) : null;
}

function require_user(): array
{
    return current_user() ?? throw new HttpError(401, 'Sign in required');
}

function read_json(int $maxBytes = 131072): array
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        throw new HttpError(415, 'Expected JSON'); // also blocks cross-site form posts
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        throw new HttpError(413, 'Request too large');
    }
    $d = json_decode($raw === '' ? '{}' : $raw, true);
    if (!is_array($d)) {
        throw new HttpError(400, 'Bad JSON');
    }
    return $d;
}

function json_out(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self)');
}

/* ---- rate limiting (stored in the database so it works across PHP processes) ---- */

function rl_state(string $key, int $window): array
{
    $k = sha1($key);
    $row = q('SELECT n, reset_at FROM vchat_rate WHERE k = ?', [$k])->fetch();
    if (!$row || (int)$row['reset_at'] < time()) {
        q('DELETE FROM vchat_rate WHERE k = ?', [$k]);
        q('INSERT INTO vchat_rate (k, n, reset_at) VALUES (?, 0, ?)', [$k, time() + $window]);
        return [0, $k];
    }
    return [(int)$row['n'], $k];
}

/** Count one attempt. Returns false once over the limit. */
function rl_hit(string $key, int $max, int $window): bool
{
    [$n, $k] = rl_state($key, $window);
    q('UPDATE vchat_rate SET n = n + 1 WHERE k = ?', [$k]);
    return $n + 1 <= $max;
}
function rl_blocked(string $key, int $max, int $window): bool { return rl_state($key, $window)[0] >= $max; }
function rl_clear(string $key): void { q('DELETE FROM vchat_rate WHERE k = ?', [sha1($key)]); }
