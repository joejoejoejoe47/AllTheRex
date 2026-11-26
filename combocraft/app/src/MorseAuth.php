<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/**
 * Username + password sign-in / create-account against the existing Morse Chess
 * `user` / `account` / `session` / `profiles` tables, using the same password
 * pre-hash and morse_session cookie (path /, HttpOnly, SameSite=Lax) as /chess.
 */
final class MorseAuth
{
    private const SESSION_DAYS = 30;
    private const USERNAME_RE = '/^[a-zA-Z0-9_]{8,20}$/';
    private const SYNTH_DOMAIN = '@players.moreschess.app';

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function signIn(array $player, string $username, string $password): array
    {
        $username = self::cleanUsername($username, false);
        if (strlen($password) < 8) {
            throw new HttpError('Password must be at least 8 characters.', 400);
        }
        if (!MorseLink::tablesExist()) {
            throw new HttpError('Morse Chess accounts are not available on this server.', 503);
        }
        self::throttle('cc-signin:' . sha1(Http::ip() . '|' . strtolower($username)), 10, 600, false);
        $email = self::emailForUsername($username);
        if ($email === '') {
            self::throttle('cc-signin:' . sha1(Http::ip() . '|' . strtolower($username)), 10, 600, true);
            throw new HttpError('No Morse Chess account with that username and password.', 401);
        }
        $row = Db::one(
            'SELECT u.`id` AS id, u.`name` AS name, u.`email` AS email, a.`password` AS pw
             FROM `user` u JOIN `account` a ON a.`userId` = u.`id` AND a.`providerId` = ?
             WHERE u.`email` = ? LIMIT 1',
            ['credential', $email]
        );
        $ok = $row !== null && is_string($row['pw'] ?? null) && self::verify($password, (string) $row['pw']);
        if (!$ok) {
            self::throttle('cc-signin:' . sha1(Http::ip() . '|' . strtolower($username)), 10, 600, true);
            throw new HttpError('No Morse Chess account with that username and password.', 401);
        }
        if (password_needs_rehash((string) $row['pw'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE `account` SET `password` = ?, `updatedAt` = ? WHERE `userId` = ? AND `providerId` = ?', [self::hash($password), self::ts(Db::nowMs()), $row['id'], 'credential']);
        }
        self::startSession((string) $row['id']);
        $player = self::restorePlayer($player, (string) $row['id']);
        return ['ok' => true, 'morse' => MorseLink::status($player), 'prefs' => Prefs::get($player), 'me' => Player::publicView($player)];
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function signUp(array $player, string $username, string $password): array
    {
        $username = self::cleanUsername($username, true);
        if (strlen($password) < 8) {
            throw new HttpError('Password must be at least 8 characters.', 400);
        }
        if (strlen($password) > 128) {
            throw new HttpError('Password is too long.', 400);
        }
        if (!MorseLink::tablesExist()) {
            throw new HttpError('Morse Chess accounts are not available on this server.', 503);
        }
        self::throttle('cc-signup:' . Http::ip(), 10, 3600, true);
        $email = strtolower($username) . self::SYNTH_DOMAIN;
        if (Db::one('SELECT id FROM `user` WHERE `email` = ? LIMIT 1', [$email])) {
            throw new HttpError('That username is already taken.', 422);
        }
        if (Db::one('SELECT 1 FROM profiles WHERE username_lc = ? LIMIT 1', [strtolower($username)])) {
            throw new HttpError('That username is already taken.', 422);
        }
        $id = bin2hex(random_bytes(16));
        $now = self::ts(Db::nowMs());
        try {
            Db::transaction(function () use ($id, $email, $username, $password, $now): void {
                Db::run(
                    'INSERT INTO `user` (`id`, `name`, `email`, `emailVerified`, `image`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, 0, NULL, ?, ?)',
                    [$id, $username, $email, $now, $now]
                );
                Db::run(
                    'INSERT INTO `account` (`id`, `accountId`, `providerId`, `userId`, `password`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [bin2hex(random_bytes(16)), $id, 'credential', $id, self::hash($password), $now, $now]
                );
                Db::run(
                    'INSERT INTO profiles (user_id, username, username_lc, score, last_seen, created_at, equipped_board, elo_scaled, coins, coins_ready, owned_boards)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 0, ?)',
                    [$id, $username, strtolower($username), 1200, $now, $now, 'lodge', '']
                );
            });
        } catch (Throwable $e) {
            error_log('[combocraft signup] ' . $e->getMessage());
            throw new HttpError('Could not create that account. Try a different username.', 422);
        }
        self::startSession($id);
        $player = MorseLink::attach($player);
        if (empty($player['nickname'])) {
            try {
                $player = Player::setNickname($username);
            } catch (Throwable $e) {
            }
        }
        return ['ok' => true, 'created' => true, 'morse' => MorseLink::status($player), 'prefs' => Prefs::get($player), 'me' => Player::publicView($player)];
    }

    public static function signOut(): array
    {
        $token = $_COOKIE[MorseLink::COOKIE] ?? null;
        if (is_string($token) && $token !== '') {
            try {
                Db::run('DELETE FROM `session` WHERE `token` = ?', [hash('sha256', $token)]);
            } catch (Throwable $e) {
            }
        }
        Http::setCookie(MorseLink::COOKIE, '', time() - 3600, true, '/');
        unset($_COOKIE[MorseLink::COOKIE]);
        Player::endSession();
        return ['ok' => true, 'signedOut' => true, 'morse' => MorseLink::guestStatus()];
    }

    /**
     * If this Morse account already has a ComboCraft player, restore that session.
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    private static function restorePlayer(array $player, string $morseUserId): array
    {
        $name = '';
        try {
            $name = (string) (Db::value('SELECT username FROM profiles WHERE user_id = ?', [$morseUserId]) ?: '');
            if ($name === '') {
                $name = (string) (Db::value('SELECT `name` FROM `user` WHERE `id` = ?', [$morseUserId]) ?: '');
            }
        } catch (Throwable $e) {
        }
        $pid = MorseLink::playerIdForMorse($morseUserId, $name);
        if ($pid && $pid !== (string) $player['id']) {
            try {
                $player = Player::adopt($pid);
            } catch (Throwable $e) {
                error_log('[combocraft adopt] ' . $e->getMessage());
            }
        }
        return MorseLink::attach($player);
    }

    /** Lightweight probe so the UI can offer Legendary picture sign-in. */
    public static function probe(string $username): array
    {
        $username = trim($username);
        $out = ['ok' => true, 'legendary' => false, 'valid' => false];
        if (!preg_match(self::USERNAME_RE, $username)) {
            return $out;
        }
        $out['valid'] = true;
        if (!MorseLink::tablesExist()) {
            return $out;
        }
        try {
            $uid = Db::value('SELECT user_id FROM profiles WHERE username_lc = ? LIMIT 1', [strtolower($username)]);
            if ($uid) {
                $out['legendary'] = self::isLegendary((string) $uid);
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    public static function isLegendary(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }
        try {
            $n = Db::value('SELECT 1 FROM legendary_unlocks WHERE user_id = ? LIMIT 1', [$userId]);
            return $n !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function startSession(string $userId): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Db::nowMs();
        $expires = $now + self::SESSION_DAYS * 86400 * 1000;
        Db::run(
            'INSERT INTO `session` (`id`, `expiresAt`, `token`, `createdAt`, `updatedAt`, `ipAddress`, `userAgent`, `userId`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                bin2hex(random_bytes(16)),
                self::ts($expires),
                hash('sha256', $token),
                self::ts($now),
                self::ts($now),
                Http::ip(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                $userId,
            ]
        );
        if (random_int(1, 50) === 1) {
            try {
                Db::run('DELETE FROM `session` WHERE `expiresAt` < ?', [self::ts($now)]);
            } catch (Throwable $e) {
            }
        }
        Http::setCookie(MorseLink::COOKIE, $token, intdiv($expires, 1000), true, '/');
        $_COOKIE[MorseLink::COOKIE] = $token;
    }

    public static function hash(string $password): string
    {
        return password_hash(base64_encode(hash('sha256', $password, true)), PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify(base64_encode(hash('sha256', $password, true)), $hash);
    }

    private static function emailForUsername(string $username): string
    {
        if (str_contains($username, '@')) {
            return strtolower(trim($username));
        }
        $found = Db::value(
            'SELECT u.`email` FROM `user` u JOIN `profiles` p ON p.`user_id` = u.`id` WHERE p.`username_lc` = ? LIMIT 1',
            [strtolower($username)]
        );
        if (is_string($found) && $found !== '') {
            return strtolower($found);
        }
        return strtolower($username) . self::SYNTH_DOMAIN;
    }

    private static function cleanUsername(string $raw, bool $strict): string
    {
        $username = trim($raw);
        if ($strict) {
            if (!preg_match(self::USERNAME_RE, $username)) {
                throw new HttpError('Usernames are 8–20 letters, numbers, or underscores.', 400);
            }
        } elseif ($username === '') {
            throw new HttpError('Enter your username.', 400);
        }
        return $username;
    }

    private static function ts(int $ms): string
    {
        $sec = intdiv($ms, 1000);
        $rem = $ms - $sec * 1000;
        if ($rem < 0) {
            $rem += 1000;
            $sec -= 1;
        }
        return gmdate('Y-m-d H:i:s', $sec) . sprintf('.%03d', $rem);
    }

    private static function throttle(string $bucket, int $limit, int $windowSec, bool $record): void
    {
        try {
            $now = Db::nowMs();
            $row = Db::one('SELECT `hits`, `window_start` FROM `auth_throttle` WHERE `bucket` = ?', [$bucket]);
            if ($row === null || Db::toMs($row['window_start']) + $windowSec * 1000 < $now) {
                if ($record) {
                    Db::upsert('auth_throttle', ['bucket' => $bucket, 'hits' => 1, 'window_start' => self::ts($now)], ['bucket'], ['hits', 'window_start']);
                }
                return;
            }
            if ((int) $row['hits'] >= $limit) {
                throw new HttpError('Too many attempts. Try again in a few minutes.', 429);
            }
            if ($record) {
                Db::run('UPDATE `auth_throttle` SET `hits` = `hits` + 1 WHERE `bucket` = ?', [$bucket]);
            }
        } catch (HttpError $e) {
            throw $e;
        } catch (Throwable $e) {
            // If the Morse throttle table is missing, fail open but still require a valid password.
        }
    }
}
