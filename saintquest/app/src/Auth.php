<?php

declare(strict_types=1);

namespace SaintQuest;

/** Username + password accounts with opaque hashed server-side sessions. */
final class Auth
{
    public const SESSION_COOKIE = 'sq_session';
    public const CSRF_COOKIE = 'sq_csrf';
    private const SESSION_DAYS = 30;
    public const START_VIRTUE = 200;

    /** @var array{id:string,username:string}|null|false */
    private static array|null|false $cached = false;

    public static function resetCache(): void
    {
        self::$cached = false;
    }

    /** @return array{id:string,username:string}|null */
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
            'SELECT s.id AS sid, s.expires_at AS expires_at, u.id AS uid, u.username AS username
             FROM sq_sessions s JOIN sq_users u ON u.id = s.user_id
             WHERE s.token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );
        if ($row === null || Db::toMs($row['expires_at']) < Db::nowMs()) {
            return self::$cached = null;
        }
        $remaining = Db::toMs($row['expires_at']) - Db::nowMs();
        if ($remaining < (self::SESSION_DAYS * 86400 * 1000) - 3600 * 1000) {
            $newExpiry = gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400);
            Db::run('UPDATE sq_sessions SET expires_at = ?, updated_at = ? WHERE id = ?', [$newExpiry, Db::now(), $row['sid']]);
            Http::setCookie(self::SESSION_COOKIE, $token, time() + self::SESSION_DAYS * 86400);
        }
        return self::$cached = ['id' => (string) $row['uid'], 'username' => (string) $row['username']];
    }

    /** @return array{id:string,username:string} */
    public static function requireUser(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            throw new HttpError('Please log in first.', 401);
        }
        return $user;
    }

    /** @return array{id:string,username:string} */
    public static function signUp(string $username, string $password): array
    {
        $username = self::cleanUsername($username);
        self::checkPassword($password);
        self::throttle('signup:' . Http::ip(), 10, 3600, false);
        $lc = mb_strtolower($username);
        if (Db::value('SELECT id FROM sq_users WHERE username_lc = ? LIMIT 1', [$lc]) !== null) {
            throw new HttpError('That username is taken. Try another one.', 422);
        }
        $id = self::randomId();
        $now = Db::now();
        try {
            Db::transaction(function () use ($id, $username, $lc, $password, $now): void {
                Db::run(
                    'INSERT INTO sq_users (id, username, username_lc, password_hash, virtue, lifetime, good_deeds, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)',
                    [$id, $username, $lc, self::hash($password), self::START_VIRTUE, $now, $now]
                );
                Saints::grant($id, Saints::STARTER, 'starter');
            });
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpError('That username is taken. Try another one.', 422);
            }
            throw $e;
        }
        self::throttle('signup:' . Http::ip(), 10, 3600, true);
        Notify::send($id, 'welcome', 'Welcome to Saint Quest!', 'St. Francis of Assisi has joined you. Help the villagers, win Holy Battles and collect saints.');
        self::startSession($id);
        self::rotateCsrf();
        return ['id' => $id, 'username' => $username];
    }

    /** @return array{id:string,username:string} */
    public static function signIn(string $username, string $password): array
    {
        $lc = mb_strtolower(trim($username));
        $bucket = 'signin:' . sha1(Http::ip() . '|' . $lc);
        self::throttle($bucket, 10, 600, false);
        $row = Db::one('SELECT id, username, password_hash FROM sq_users WHERE username_lc = ? LIMIT 1', [$lc]);
        $ok = $row !== null && self::verify($password, (string) $row['password_hash']);
        if (!$ok) {
            self::throttle($bucket, 10, 600, true);
            throw new HttpError('Wrong username or password.', 401);
        }
        self::startSession((string) $row['id']);
        self::rotateCsrf();
        return ['id' => (string) $row['id'], 'username' => (string) $row['username']];
    }

    public static function signOut(): void
    {
        $token = Http::cookie(self::SESSION_COOKIE);
        if ($token !== null) {
            Db::run('DELETE FROM sq_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
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
        Db::run(
            'INSERT INTO sq_sessions (id, user_id, token_hash, expires_at, created_at, updated_at, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::randomId(), $userId, hash('sha256', $token),
                gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400), $now, $now,
                Http::ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]
        );
        if (random_int(1, 40) === 1) {
            Db::run('DELETE FROM sq_sessions WHERE expires_at < ?', [$now]);
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
        $header = Http::header('X-CSRF-Token');
        if ($header === null || $header === '') {
            $t = Http::jsonBody()['csrf'] ?? ($_POST['csrf'] ?? null);
            $header = is_string($t) ? $t : null;
        }
        $cookie = Http::cookie(self::CSRF_COOKIE);
        if ($header === null || $cookie === null || !hash_equals($cookie, $header)) {
            throw new HttpError('Missing or invalid security token. Refresh and try again.', 403);
        }
    }

    public static function randomId(): string
    {
        return bin2hex(random_bytes(12));
    }

    public static function cleanUsername(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.\-]{1,22}[\p{L}\p{N}]$/u', $name)) {
            throw new HttpError('Usernames are 3 to 24 letters or numbers (spaces, dots, dashes and underscores are OK in the middle).', 400);
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
        $row = Db::one('SELECT hits, window_start FROM sq_throttle WHERE bucket = ?', [$bucket]);
        if ($row === null || Db::toMs($row['window_start']) + $windowSec * 1000 < $nowMs) {
            if ($record) {
                Db::upsert('sq_throttle', ['bucket' => $bucket, 'hits' => 1, 'window_start' => Db::now()], ['bucket'], ['hits', 'window_start']);
            }
            return;
        }
        if ((int) $row['hits'] >= $limit) {
            throw new HttpError('Too many tries. Please wait a few minutes.', 429);
        }
        if ($record) {
            Db::run('UPDATE sq_throttle SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
        }
    }
}
