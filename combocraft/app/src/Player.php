<?php

declare(strict_types=1);

namespace ComboCraft;

/** Anonymous players: a random token in an httpOnly cookie, plus a chosen nickname. */
final class Player
{
    public const COOKIE = 'cc_tok';
    public const FREE_DAILY = 500;
    public const AVATARS = ['🐱', '🦊', '🐼', '🐸', '🐙', '🦄', '🐧', '🦉', '🐯', '🐨', '🐵', '🦖', '🐳', '🐝', '🦁', '🐰'];

    /** @var array<string,mixed>|null */
    private static ?array $current = null;

    public static function reset(): void
    {
        self::$current = null;
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $tok = Http::cookie(self::COOKIE);
        if ($tok === null || !preg_match('/^[a-f0-9]{48}$/', $tok)) {
            return null;
        }
        $row = Db::one('SELECT * FROM cc_players WHERE token_hash = ?', [hash('sha256', $tok)]);
        if ($row !== null) {
            self::$current = $row;
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public static function require(): array
    {
        $p = self::current();
        if ($p !== null) {
            return $p;
        }
        // Limit brand-new anonymous players per IP per hour (bot spam guard).
        $ipHash = substr(hash('sha256', Http::ip() . '|cc'), 0, 16);
        $recent = (int) Db::value('SELECT COUNT(*) FROM cc_players WHERE ip_hash = ? AND created_at > ?', [$ipHash, gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($recent > 40) {
            throw new HttpError('Too many new players from this network. Try again later.', 429);
        }
        $tok = bin2hex(random_bytes(24));
        $id = 'p' . substr(bin2hex(random_bytes(8)), 0, 11);
        $now = Db::now();
        Db::run(
            'INSERT INTO cc_players (id, token_hash, nickname, nick_key, avatar, day, day_count, streak, last_day, firsts, combos, ip_hash, created_at, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, hash('sha256', $tok), null, null, self::AVATARS[random_int(0, count(self::AVATARS) - 1)], '', 0, 0, '', 0, 0, $ipHash, $now, $now]
        );
        Http::setCookie(self::COOKIE, $tok, time() + 86400 * 400);
        $_COOKIE[self::COOKIE] = $tok;
        self::$current = null;
        $row = self::current();
        if ($row === null) {
            throw new HttpError('Could not create player.', 500);
        }
        return $row;
    }

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    /** @param array<string,mixed> $p */
    public static function usedToday(array $p): int
    {
        return ($p['day'] ?? '') === self::today() ? (int) $p['day_count'] : 0;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function publicView(array $p): array
    {
        $streak = (int) $p['streak'];
        $last = (string) $p['last_day'];
        if ($last !== self::today() && $last !== gmdate('Y-m-d', time() - 86400)) {
            $streak = 0;
        }
        return [
            'id' => $p['id'],
            'nickname' => $p['nickname'],
            'avatar' => $p['avatar'] ?: '🐱',
            'firsts' => (int) $p['firsts'],
            'combos' => (int) $p['combos'],
            'streak' => $streak,
            'usedToday' => self::usedToday($p),
            'dailyLimit' => self::FREE_DAILY,
            'premium' => false,
        ];
    }

    /** Count one combination; returns false when today's free limit is used up. @param array<string,mixed> $p */
    public static function spend(array $p): bool
    {
        $today = self::today();
        if (($p['day'] ?? '') === $today) {
            if ((int) $p['day_count'] >= self::FREE_DAILY) {
                return false;
            }
            Db::run('UPDATE cc_players SET day_count = day_count + 1, combos = combos + 1, last_seen = ? WHERE id = ?', [Db::now(), $p['id']]);
        } else {
            $yesterday = gmdate('Y-m-d', time() - 86400);
            $streak = ($p['last_day'] ?? '') === $yesterday ? (int) $p['streak'] + 1 : 1;
            Db::run('UPDATE cc_players SET day = ?, day_count = 1, combos = combos + 1, streak = ?, last_day = ?, last_seen = ? WHERE id = ?', [$today, $streak, $today, Db::now(), $p['id']]);
        }
        self::$current = null;
        return true;
    }

    public static function setNickname(string $nick): array
    {
        $p = self::require();
        $nick = trim((string) preg_replace('/\s+/u', ' ', $nick));
        if (!preg_match('/^[\p{L}\p{N}_.\- ]{2,20}$/u', $nick)) {
            throw new HttpError('Nicknames are 2–20 letters, numbers, spaces, dots, dashes or underscores.', 422);
        }
        if (!Text::isSafe($nick) || preg_match('/admin|moderator|combocraft|official/i', $nick)) {
            throw new HttpError('Please pick a different nickname.', 422);
        }
        $key = mb_strtolower($nick);
        $taken = Db::value('SELECT id FROM cc_players WHERE nick_key = ? AND id <> ?', [$key, $p['id']]);
        if ($taken !== null) {
            throw new HttpError('That nickname is taken. Try another!', 409);
        }
        Db::run('UPDATE cc_players SET nickname = ?, nick_key = ?, last_seen = ? WHERE id = ?', [$nick, $key, Db::now(), $p['id']]);
        self::$current = null;
        return self::publicView(self::require());
    }
}
