<?php

declare(strict_types=1);

namespace SaintQuest;

/** Saint catalog (assets/data/saints.json), ownership, store and player profile. */
final class Saints
{
    public const STARTER = 'francis';
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $catalog = null;

    /** @return array<string,array<string,mixed>> */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $file = dirname(Config::root()) . '/assets/data/saints.json';
            $data = json_decode((string) @file_get_contents($file), true);
            self::$catalog = [];
            foreach (($data['saints'] ?? []) as $s) {
                self::$catalog[(string) $s['key']] = $s;
            }
        }
        return self::$catalog;
    }

    /** @return array<string,mixed> */
    public static function def(string $key): array
    {
        $c = self::catalog();
        if (!isset($c[$key])) {
            throw new HttpError('Unknown saint.', 404);
        }
        return $c[$key];
    }

    public static function grant(string $ownerId, string $key, string $source, ?string $cardId = null): string
    {
        self::def($key);
        $id = Auth::randomId();
        $now = Db::now();
        Db::run(
            'INSERT INTO sq_saints (id, owner_id, saint_key, rating, wins, losses, source, card_id, status, created_at, updated_at)
             VALUES (?, ?, ?, 1000, 0, 0, ?, ?, ?, ?, ?)',
            [$id, $ownerId, $key, $source, $cardId, 'ok', $now, $now]
        );
        return $id;
    }

    /** Store purchase: the wallet debit is conditional, so a balance can never go negative. */
    public static function buyFromStore(string $userId, string $key): array
    {
        $def = self::def($key);
        $price = (int) $def['price'];
        Auth::throttle('store:' . $userId, 30, 600, true);
        return Db::transaction(function () use ($userId, $key, $price, $def): array {
            $n = Db::run('UPDATE sq_users SET virtue = virtue - ?, updated_at = ? WHERE id = ? AND virtue >= ?', [$price, Db::now(), $userId, $price]);
            if ($n !== 1) {
                throw new HttpError('Not enough virtue points yet. Win Holy Battles and help villagers to earn more.', 402);
            }
            $sid = self::grant($userId, $key, 'store');
            return ['saintId' => $sid, 'name' => $def['name'], 'price' => $price];
        });
    }

    /** @return array<string,mixed> */
    public static function row(array $r): array
    {
        $def = self::catalog()[$r['saint_key']] ?? null;
        return [
            'id' => (string) $r['id'],
            'key' => (string) $r['saint_key'],
            'name' => $def['name'] ?? $r['saint_key'],
            'rating' => (int) $r['rating'],
            'wins' => (int) $r['wins'],
            'losses' => (int) $r['losses'],
            'source' => (string) $r['source'],
            'status' => (string) $r['status'],
            'card' => isset($r['card_code']) && $r['card_code'] ? (string) $r['card_code'] : null,
        ];
    }

    /** Saints of a player, best rating first (the home saint is the first one). */
    public static function ofOwner(string $ownerId): array
    {
        $rows = Db::all(
            'SELECT s.*, c.code AS card_code FROM sq_saints s LEFT JOIN sq_cards c ON c.id = s.card_id
             WHERE s.owner_id = ? ORDER BY s.rating DESC, s.wins DESC, s.created_at ASC, s.id ASC',
            [$ownerId]
        );
        return array_map([self::class, 'row'], $rows);
    }

    public static function owned(string $saintId, string $ownerId): array
    {
        $r = Db::one('SELECT * FROM sq_saints WHERE id = ? AND owner_id = ?', [$saintId, $ownerId]);
        if ($r === null) {
            throw new HttpError('That saint is not yours.', 403);
        }
        return $r;
    }

    /** Level from lifetime virtue: 500 for level 2, then +250 more each level. */
    public static function level(int $lifetime): array
    {
        $level = 1;
        $need = 500;
        $xp = max(0, $lifetime);
        while ($xp >= $need && $level < 99) {
            $xp -= $need;
            $level++;
            $need = 500 + 250 * ($level - 1);
        }
        return ['level' => $level, 'into' => $xp, 'need' => $need];
    }

    /** @return array<string,mixed> */
    public static function me(string $userId): array
    {
        $u = Db::one('SELECT id, username, virtue, lifetime, good_deeds, created_at FROM sq_users WHERE id = ?', [$userId]);
        if ($u === null) {
            throw new HttpError('Please log in first.', 401);
        }
        $lifetime = (int) $u['lifetime'];
        return [
            'id' => (string) $u['id'],
            'username' => (string) $u['username'],
            'virtue' => (int) $u['virtue'],
            'lifetime' => $lifetime,
            'goodDeeds' => (int) $u['good_deeds'],
            'level' => self::level($lifetime),
            'saints' => self::ofOwner($userId),
            'unseen' => Notify::unseen($userId),
            'questions' => Cards::openQuestions($userId),
            'deedsToday' => Deeds::today($userId),
            'cases' => Cases::status($userId),
        ];
    }

    /** Public view of a player. */
    public static function player(string $username): array
    {
        $u = Db::one('SELECT id, username, lifetime, good_deeds FROM sq_users WHERE username_lc = ?', [mb_strtolower(trim($username))]);
        if ($u === null) {
            throw new HttpError('No player by that name.', 404);
        }
        return [
            'username' => (string) $u['username'],
            'level' => self::level((int) $u['lifetime'])['level'],
            'lifetime' => (int) $u['lifetime'],
            'goodDeeds' => (int) $u['good_deeds'],
            'saints' => self::ofOwner((string) $u['id']),
        ];
    }

    /** Top players by lifetime virtue, with their best saint as the avatar. */
    public static function leaderboard(int $limit = 10): array
    {
        $rows = Db::all('SELECT id, username, lifetime FROM sq_users ORDER BY lifetime DESC, created_at ASC LIMIT ' . max(1, min(50, $limit)));
        $out = [];
        foreach ($rows as $r) {
            $best = Db::one('SELECT saint_key, rating FROM sq_saints WHERE owner_id = ? ORDER BY rating DESC, created_at ASC LIMIT 1', [$r['id']]);
            $out[] = [
                'username' => (string) $r['username'],
                'points' => (int) $r['lifetime'],
                'level' => self::level((int) $r['lifetime'])['level'],
                'saint' => $best['saint_key'] ?? null,
                'rating' => isset($best['rating']) ? (int) $best['rating'] : null,
            ];
        }
        return $out;
    }

    public static function earn(string $userId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        Db::run('UPDATE sq_users SET virtue = virtue + ?, lifetime = lifetime + ?, updated_at = ? WHERE id = ?', [$amount, $amount, Db::now(), $userId]);
    }

    /** Search the store catalog, open listings and players. */
    public static function search(string $q): array
    {
        $q = trim(mb_strtolower($q));
        if (mb_strlen($q) > 60) {
            $q = mb_substr($q, 0, 60);
        }
        $store = [];
        foreach (self::catalog() as $key => $s) {
            $hay = mb_strtolower($s['name'] . ' ' . $s['patron'] . ' ' . $s['rarity'] . ' ' . $key);
            if ($q === '' || str_contains($hay, $q) || str_contains($hay, str_replace(['saint ', 'st '], ['st. ', 'st. '], $q))) {
                $store[] = $key;
            }
        }
        $listings = Market::open($q, 40);
        $players = [];
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            foreach (Db::all("SELECT username, lifetime FROM sq_users WHERE username_lc LIKE ? ESCAPE '!' ORDER BY lifetime DESC LIMIT 8", [$like]) as $p) {
                $players[] = ['username' => (string) $p['username'], 'level' => self::level((int) $p['lifetime'])['level']];
            }
        }
        return ['store' => $store, 'listings' => $listings, 'players' => $players];
    }
}
