<?php

declare(strict_types=1);

namespace WorldDomination;

/** Email + password accounts with opaque hashed server-side sessions. */
final class Auth
{
    public const SESSION_COOKIE = 'wd_session';
    public const CSRF_COOKIE = 'wd_csrf';
    private const SESSION_DAYS = 30;

    /** @var array{id:string,name:string,email:string}|null|false */
    private static array|null|false $cached = false;

    public static function resetCache(): void
    {
        self::$cached = false;
    }

    /** @return array{id:string,name:string,email:string}|null */
    public static function currentUser(): ?array
    {
        if (self::$cached !== false) {
            return self::$cached;
        }
        $token = Http::cookie(self::SESSION_COOKIE);
        if ($token === null) {
            return self::$cached = null;
        }
        $row = Db::one(
            'SELECT s.id AS sid, s.expires_at AS expires_at, u.id AS uid, u.name AS name, u.email AS email
             FROM wd_sessions s JOIN wd_users u ON u.id = s.user_id
             WHERE s.token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );
        if ($row === null || Db::toMs($row['expires_at']) < Db::nowMs()) {
            return self::$cached = null;
        }
        $remaining = Db::toMs($row['expires_at']) - Db::nowMs();
        if ($remaining < (self::SESSION_DAYS * 86400 * 1000) - 3600 * 1000) {
            $newExpiry = gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400);
            Db::run('UPDATE wd_sessions SET expires_at = ?, updated_at = ? WHERE id = ?', [$newExpiry, Db::now(), $row['sid']]);
            Http::setCookie(self::SESSION_COOKIE, $token, time() + self::SESSION_DAYS * 86400);
        }
        return self::$cached = [
            'id' => (string) $row['uid'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
        ];
    }

    public static function requireUser(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            throw new HttpError('Please log in first.', 401);
        }
        return $user;
    }

    /** @return array{id:string,name:string,email:string} */
    public static function signUp(string $email, string $password, string $name): array
    {
        $email = self::normalizeEmail($email);
        $name = self::cleanName($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new HttpError('Enter a valid email address.', 400);
        }
        self::checkPassword($password);
        if ($name === '') {
            throw new HttpError('Pick a display name (at least 2 characters).', 400);
        }
        self::throttle('signup:' . Http::ip(), 8, 3600, true);

        $exists = Db::value('SELECT id FROM wd_users WHERE email = ? LIMIT 1', [$email]);
        if ($exists !== null) {
            throw new HttpError('An account with that email already exists.', 422);
        }
        $id = self::randomId();
        $now = Db::now();
        Db::run(
            'INSERT INTO wd_users (id, name, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $name, $email, self::hash($password), $now, $now]
        );
        self::startSession($id);
        self::rotateCsrf();
        return ['id' => $id, 'name' => $name, 'email' => $email];
    }

    /** @return array{id:string,name:string,email:string} */
    public static function signIn(string $email, string $password): array
    {
        $email = self::normalizeEmail($email);
        $bucket = 'signin:' . sha1(Http::ip() . '|' . $email);
        self::throttle($bucket, 10, 600, false);

        $row = Db::one('SELECT id, name, email, password_hash FROM wd_users WHERE email = ? LIMIT 1', [$email]);
        $ok = $row !== null && is_string($row['password_hash']) && self::verify($password, $row['password_hash']);
        if (!$ok) {
            self::throttle($bucket, 10, 600, true);
            throw new HttpError('Invalid email or password.', 401);
        }
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            Db::run(
                'UPDATE wd_users SET password_hash = ?, updated_at = ? WHERE id = ?',
                [self::hash($password), Db::now(), $row['id']]
            );
        }
        self::startSession((string) $row['id']);
        self::rotateCsrf();
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
        ];
    }

    public static function signOut(): void
    {
        $token = Http::cookie(self::SESSION_COOKIE);
        if ($token !== null) {
            Db::run('DELETE FROM wd_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        }
        Http::setCookie(self::SESSION_COOKIE, '', time() - 3600);
        unset($_COOKIE[self::SESSION_COOKIE]);
        self::$cached = null;
        self::rotateCsrf();
    }

    public static function startSession(string $userId): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Db::now();
        $expires = gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400);
        Db::run(
            'INSERT INTO wd_sessions (id, user_id, token_hash, expires_at, created_at, updated_at, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::randomId(),
                $userId,
                hash('sha256', $token),
                $expires,
                $now,
                $now,
                Http::ip(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]
        );
        if (random_int(1, 40) === 1) {
            Db::run('DELETE FROM wd_sessions WHERE expires_at < ?', [$now]);
        }
        Http::setCookie(self::SESSION_COOKIE, $token, time() + self::SESSION_DAYS * 86400);
        $_COOKIE[self::SESSION_COOKIE] = $token;
        self::$cached = false;
    }

    public static function csrfToken(): string
    {
        $token = Http::cookie(self::CSRF_COOKIE);
        if ($token === null || strlen($token) < 16) {
            $token = self::rotateCsrf();
        }
        return $token;
    }

    public static function rotateCsrf(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Http::setCookie(self::CSRF_COOKIE, $token, time() + self::SESSION_DAYS * 86400);
        $_COOKIE[self::CSRF_COOKIE] = $token;
        return $token;
    }

    public static function assertCsrf(): void
    {
        $header = self::headerToken();
        $cookie = Http::cookie(self::CSRF_COOKIE);
        if ($header === null || $cookie === null || !hash_equals($cookie, $header)) {
            throw new HttpError('Missing or invalid security token. Refresh and try again.', 403);
        }
    }

    private static function headerToken(): ?string
    {
        $h = Http::header('X-CSRF-Token');
        if ($h !== null && $h !== '') {
            return $h;
        }
        $body = Http::jsonBody();
        $t = $body['csrf'] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    public static function randomId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if (mb_strlen($name) > 24) {
            $name = mb_substr($name, 0, 24);
        }
        if (mb_strlen($name) < 2) {
            return '';
        }
        return $name;
    }

    public static function checkPassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new HttpError('Password must be at least 8 characters.', 400);
        }
        if (strlen($password) > 128) {
            throw new HttpError('That password is too long.', 400);
        }
    }

    /** Pre-hash so bcrypt's 72-byte limit never silently truncates a password. */
    public static function hash(string $password): string
    {
        return password_hash(base64_encode(hash('sha256', $password, true)), PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify(base64_encode(hash('sha256', $password, true)), $hash);
    }

    public static function throttle(string $bucket, int $limit, int $windowSec, bool $record): void
    {
        $nowMs = Db::nowMs();
        $row = Db::one('SELECT hits, window_start FROM wd_throttle WHERE bucket = ?', [$bucket]);
        if ($row === null || Db::toMs($row['window_start']) + $windowSec * 1000 < $nowMs) {
            if ($record) {
                Db::upsert(
                    'wd_throttle',
                    ['bucket' => $bucket, 'hits' => 1, 'window_start' => Db::now()],
                    ['bucket'],
                    ['hits', 'window_start']
                );
            }
            return;
        }
        $hits = (int) $row['hits'];
        if ($hits >= $limit) {
            throw new HttpError('Too many attempts. Try again in a few minutes.', 429);
        }
        if ($record) {
            Db::run('UPDATE wd_throttle SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
        }
    }
}
