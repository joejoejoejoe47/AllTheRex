<?php

declare(strict_types=1);

namespace ComboCraft;

/** One shared daily target, seeded by the UTC date, plus a per-player streak. */
final class Daily
{
    /** Fun mid-tier seed results that almost always have a recipe. */
    private const POOL = [
        'Steam', 'Ocean', 'Storm', 'Tsunami', 'Lava', 'Dragon', 'Inferno Feline',
        'Zoomies Apocalypse', 'Singularity', 'Cat Overlords', 'Wood-Fired Pizza',
        'Mud Pie', 'Firenado', 'Cloud', 'Rain', 'Sauna Cat', 'Steampunk Robot',
        'Sunken Treasure', 'Dragon Hoard', 'Pizza Planet', 'Mother Nature',
        'Mecha Dragon', 'Flying Cat', 'Cat Fountain', 'Steam Engine', 'Friday',
        'True Love', 'Gold Mine', 'Tornado', 'Hot Take', 'Plant',
    ];

    public static function today(): string
    {
        return Player::today();
    }

    /** @return array<string,mixed> */
    public static function status(?array $player = null): array
    {
        $day = self::today();
        $target = self::target($day);
        $player = $player ?? Player::current();
        $row = null;
        $stats = ['streak' => 0, 'best_streak' => 0, 'wins' => 0, 'last_day' => ''];
        if ($player) {
            $row = Db::one('SELECT * FROM cc_daily WHERE player_id = ? AND day = ?', [$player['id'], $day]);
            $st = Db::one('SELECT * FROM cc_daily_stats WHERE player_id = ?', [$player['id']]);
            if ($st) {
                $stats = $st;
                $last = (string) $st['last_day'];
                $yesterday = gmdate('Y-m-d', time() - 86400);
                if ($last !== $day && $last !== $yesterday) {
                    $stats['streak'] = 0;
                }
            }
        }
        $path = [];
        if ($row && !empty($row['path'])) {
            $decoded = json_decode((string) $row['path'], true);
            if (is_array($decoded)) {
                $path = $decoded;
            }
        }
        return [
            'day' => $day,
            'target' => $target,
            'solved' => $row ? (int) $row['solved'] === 1 : false,
            'combos' => $row ? (int) $row['combos'] : 0,
            'path' => $path,
            'streak' => (int) $stats['streak'],
            'bestStreak' => (int) $stats['best_streak'],
            'wins' => (int) $stats['wins'],
            'share' => self::shareText($day, $target, $row, (int) $stats['streak']),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function target(string $day): ?array
    {
        $pool = self::POOL;
        $idx = hexdec(substr(hash('sha256', 'cc-daily-v1|' . $day), 0, 8)) % count($pool);
        $name = $pool[$idx];
        $e = self::ensureNamed($name);
        if ($e === null) {
            // walk the pool from the seed until one exists
            for ($i = 1; $i < count($pool); $i++) {
                $e = self::ensureNamed($pool[($idx + $i) % count($pool)]);
                if ($e !== null) {
                    break;
                }
            }
        }
        return $e ? Game::view($e) : null;
    }

    /** @return array<string,mixed>|null */
    private static function ensureNamed(string $name): ?array
    {
        $key = Text::key($name);
        $e = Db::one('SELECT * FROM cc_elements WHERE name_key = ?', [$key]);
        if ($e) {
            return $e;
        }
        foreach (Seeds::RECIPES as $pair => $r) {
            if (Text::key($r[0]) !== $key) {
                continue;
            }
            $now = Db::now();
            Game::insertPublic('cc_elements', [
                'name' => $r[0], 'name_key' => $key, 'emoji' => Text::safeEmoji($r[1]), 'tagline' => $r[2],
                'art' => null, 'label' => null, 'depth' => 2, 'is_base' => 0, 'sort_order' => 0,
                'discovered_by' => null, 'discovered_at' => null, 'first_a' => null, 'first_b' => null,
                'uses' => 0, 'source' => 'seed', 'created_at' => $now,
            ]);
            return Db::one('SELECT * FROM cc_elements WHERE name_key = ?', [$key]);
        }
        return null;
    }

    /**
     * Record a combine against today's challenge.
     * @param array<string,mixed> $player
     * @param array<string,mixed> $result
     * @param array<int,array<string,mixed>|null> $inputs
     * @return array<string,mixed>
     */
    public static function onCombine(array $player, array $result, array $inputs, bool $newForYou): array
    {
        $day = self::today();
        $target = self::target($day);
        if ($target === null) {
            return self::status($player);
        }
        $row = Db::one('SELECT * FROM cc_daily WHERE player_id = ? AND day = ?', [$player['id'], $day]);
        $path = [];
        if ($row && !empty($row['path'])) {
            $decoded = json_decode((string) $row['path'], true);
            $path = is_array($decoded) ? $decoded : [];
        }
        $step = [
            'a' => $inputs[0]['emoji'] ?? '❓',
            'b' => $inputs[1]['emoji'] ?? '❓',
            'r' => $result['emoji'] ?? '❓',
            'an' => $inputs[0]['name'] ?? '',
            'bn' => $inputs[1]['name'] ?? '',
            'rn' => $result['name'] ?? '',
        ];
        if (count($path) < 16) {
            $path[] = $step;
        }
        $combos = ($row ? (int) $row['combos'] : 0) + 1;
        $solved = $row && (int) $row['solved'] === 1;
        $just = false;
        if (!$solved && (int) $result['id'] === (int) $target['id']) {
            $solved = true;
            $just = true;
        }
        $now = Db::now();
        if ($row) {
            Db::run(
                'UPDATE cc_daily SET combos = ?, path = ?, solved = ?, solved_at = COALESCE(solved_at, ?) WHERE player_id = ? AND day = ?',
                [$combos, json_encode($path, JSON_UNESCAPED_UNICODE), $solved ? 1 : 0, $just ? $now : null, $player['id'], $day]
            );
        } else {
            Game::insertPublic('cc_daily', [
                'player_id' => $player['id'], 'day' => $day, 'target_id' => $target['id'],
                'solved' => $solved ? 1 : 0, 'combos' => $combos,
                'path' => json_encode($path, JSON_UNESCAPED_UNICODE),
                'solved_at' => $just ? $now : null,
            ]);
        }
        if ($just) {
            self::bumpStreak($player['id'], $day);
        }
        $status = self::status($player);
        $status['justSolved'] = $just;
        return $status;
    }

    private static function bumpStreak(string $playerId, string $day): void
    {
        $st = Db::one('SELECT * FROM cc_daily_stats WHERE player_id = ?', [$playerId]);
        $yesterday = gmdate('Y-m-d', time() - 86400);
        if ($st === null) {
            Game::insertPublic('cc_daily_stats', [
                'player_id' => $playerId, 'streak' => 1, 'best_streak' => 1, 'last_day' => $day, 'wins' => 1,
            ]);
            return;
        }
        if ((string) $st['last_day'] === $day) {
            return;
        }
        $streak = (string) $st['last_day'] === $yesterday ? (int) $st['streak'] + 1 : 1;
        $best = max($streak, (int) $st['best_streak']);
        Db::run('UPDATE cc_daily_stats SET streak = ?, best_streak = ?, last_day = ?, wins = wins + 1 WHERE player_id = ?', [$streak, $best, $day, $playerId]);
    }

    /** @param array<string,mixed>|null $row @param array<string,mixed>|null $target */
    public static function shareText(string $day, ?array $target, ?array $row, int $streak): string
    {
        $name = $target['name'] ?? '???';
        $emoji = $target['emoji'] ?? '✨';
        $solved = $row && (int) ($row['solved'] ?? 0) === 1;
        $combos = $row ? (int) $row['combos'] : 0;
        $lines = ['ComboCraft Daily ' . $day, $emoji . ' ' . $name];
        $path = [];
        if ($row && !empty($row['path'])) {
            $decoded = json_decode((string) $row['path'], true);
            $path = is_array($decoded) ? $decoded : [];
        }
        foreach ($path as $s) {
            $lines[] = ($s['a'] ?? '❓') . ' + ' . ($s['b'] ?? '❓') . ' = ' . ($s['r'] ?? '❓');
        }
        if ($solved) {
            $lines[] = $combos . ' combo' . ($combos === 1 ? '' : 's') . ' · 🔥 ' . $streak . ' streak';
        } else {
            $lines[] = 'Still hunting…';
        }
        return implode("\n", $lines);
    }
}
