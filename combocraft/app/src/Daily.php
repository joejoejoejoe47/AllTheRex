<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Daily Challenge chain: a date-seeded first target for everyone, then
 * harder follow-ups (more recipe-tree steps) that are also date-seeded
 * so friends can compare. Progress is stored per anonymous player id.
 */
final class Daily
{
    /** Fun mid-tier seed results that almost always have a recipe. Used for Level 1. */
    private const POOL = [
        'Steam', 'Ocean', 'Storm', 'Tsunami', 'Lava', 'Dragon', 'Inferno Feline',
        'Zoomies Apocalypse', 'Singularity', 'Cat Overlords', 'Wood-Fired Pizza',
        'Mud Pie', 'Firenado', 'Cloud', 'Rain', 'Sauna Cat', 'Steampunk Robot',
        'Sunken Treasure', 'Dragon Hoard', 'Pizza Planet', 'Mother Nature',
        'Mecha Dragon', 'Flying Cat', 'Cat Fountain', 'Steam Engine', 'Friday',
        'True Love', 'Gold Mine', 'Tornado', 'Hot Take', 'Plant',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $graphCache = null;

    public static function today(): string
    {
        return Player::today();
    }

    /** @return array<string,mixed> */
    public static function status(?array $player = null): array
    {
        $day = self::today();
        $player = $player ?? Player::current();
        $chain = $player ? self::loadChain($player, $day) : self::freshChain($day, []);
        $target = self::viewForKey($chain['target_key']);
        $stats = ['streak' => 0, 'best_streak' => 0, 'wins' => 0, 'last_day' => ''];
        if ($player) {
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
        if (!empty($chain['path'])) {
            $decoded = json_decode((string) $chain['path'], true);
            if (is_array($decoded)) {
                $path = $decoded;
            }
        }
        $cleared = !empty($chain['cleared']);
        $steps = (int) $chain['steps'];
        return [
            'day' => $day,
            'level' => (int) $chain['level'],
            'steps' => $steps,
            'stepsLabel' => self::stepsLabel($steps),
            'target' => $target,
            'solved' => $cleared,
            'cleared' => $cleared,
            'combos' => (int) $chain['combos'],
            'path' => $path,
            'score' => (int) $chain['score'],
            'solvedKeys' => $chain['solved_list'],
            'history' => $chain['history'],
            'streak' => (int) $stats['streak'],
            'bestStreak' => (int) $stats['best_streak'],
            'wins' => (int) $stats['wins'],
            'share' => self::shareText($day, $target, $chain, (int) $stats['streak']),
        ];
    }

    /** Level 1 target: same date seed as before, so today's first challenge stays unchanged. */
    /** @return array<string,mixed>|null */
    public static function target(string $day): ?array
    {
        $pool = self::POOL;
        $idx = hexdec(substr(hash('sha256', 'cc-daily-v1|' . $day), 0, 8)) % count($pool);
        $name = $pool[$idx];
        $e = self::ensureNamed($name);
        if ($e === null) {
            for ($i = 1; $i < count($pool); $i++) {
                $e = self::ensureNamed($pool[($idx + $i) % count($pool)]);
                if ($e !== null) {
                    break;
                }
            }
        }
        return $e ? Game::view($e) : null;
    }

    /**
     * Record a combine against the current chain level.
     * @param array<string,mixed> $player
     * @param array<string,mixed> $result
     * @param array<int,array<string,mixed>|null> $inputs
     * @return array<string,mixed>
     */
    public static function onCombine(array $player, array $result, array $inputs, bool $newForYou): array
    {
        $day = self::today();
        $chain = self::loadChain($player, $day);
        $path = [];
        if (!empty($chain['path'])) {
            $decoded = json_decode((string) $chain['path'], true);
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
        if (count($path) < 24) {
            $path[] = $step;
        }
        $chain['combos'] = (int) $chain['combos'] + 1;
        $chain['path'] = json_encode($path, JSON_UNESCAPED_UNICODE);
        $just = false;
        $clearedAll = false;
        $prevLevel = (int) $chain['level'];
        $prevTarget = $chain['target_name'];
        $resultKey = Text::key((string) ($result['name'] ?? ''));
        if (empty($chain['cleared']) && $resultKey !== '' && $resultKey === (string) $chain['target_key']) {
            $just = true;
            $solved = $chain['solved_list'];
            $solved[] = $resultKey;
            $chain['solved_list'] = array_values(array_unique($solved));
            $gain = 10 * (int) $chain['level'] + (int) $chain['steps'];
            $chain['score'] = (int) $chain['score'] + $gain;
            $chain['history'][] = [
                'level' => (int) $chain['level'],
                'name' => $chain['target_name'],
                'key' => $resultKey,
                'steps' => (int) $chain['steps'],
                'combos' => (int) $chain['combos'],
            ];
            // First clear of the day still counts for the calendar streak + cc_daily row.
            if (count($chain['solved_list']) === 1) {
                self::markLegacySolved($player, $day, $result, $path, (int) $chain['combos']);
                self::bumpStreak($player['id'], $day);
            }
            $next = self::pickForLevel($day, (int) $chain['level'] + 1, $chain['solved_list']);
            if ($next) {
                $chain['level'] = (int) $chain['level'] + 1;
                $chain['target_key'] = $next['key'];
                $chain['target_name'] = $next['name'];
                $chain['steps'] = $next['steps'];
                $chain['combos'] = 0;
                $chain['path'] = json_encode([], JSON_UNESCAPED_UNICODE);
                $chain['cleared'] = false;
            } else {
                $chain['cleared'] = true;
                $clearedAll = true;
            }
        }
        self::saveChain($player['id'], $day, $chain);
        $status = self::status($player);
        $status['justSolved'] = $just;
        $status['justLevel'] = $just ? $prevLevel : null;
        $status['justName'] = $just ? $prevTarget : null;
        $status['scoreGain'] = $just ? (10 * $prevLevel + (int) ($status['history'][count($status['history']) - 1]['steps'] ?? 0)) : 0;
        $status['clearedAll'] = $clearedAll;
        return $status;
    }

    /**
     * A hint aimed at the current daily target. Scales: early levels can
     * reveal the last combo; later levels reveal an earlier intermediate.
     * @param array<string,mixed> $player
     * @param array<int,array<string,mixed>> $owned keyed by name_key or list
     * @return array<string,mixed>|null
     */
    public static function nextHint(array $player, array $ownedByKey): ?array
    {
        $chain = self::loadChain($player, self::today());
        if (!empty($chain['cleared']) || $chain['target_key'] === '') {
            return null;
        }
        $g = self::graph();
        $target = $chain['target_key'];
        if (!isset($g['nodes'][$target])) {
            return null;
        }
        $step = self::nextPathStep($target, $ownedByKey, $g, (int) $chain['level']);
        if ($step === null) {
            return null;
        }
        $a = self::ensureNamed($step['aName']);
        $b = self::ensureNamed($step['bName']);
        if (!$a || !$b) {
            return null;
        }
        $tease = $step['final']
            ? ('This makes today’s Level ' . (int) $chain['level'] . ' target!')
            : ('You’ll need this on the way to ' . $chain['target_name'] . '.');
        return [
            'a' => Game::view($a),
            'b' => Game::view($b),
            'tease' => $tease,
            'daily' => true,
        ];
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

    /** @return array<string,mixed>|null */
    private static function viewForKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $e = Db::one('SELECT * FROM cc_elements WHERE name_key = ?', [$key]);
        if ($e) {
            return Game::view($e);
        }
        $g = self::graph();
        $n = $g['nodes'][$key] ?? null;
        if (!$n) {
            return null;
        }
        $ensured = self::ensureNamed($n['name']);
        return $ensured ? Game::view($ensured) : [
            'id' => 0,
            'name' => $n['name'],
            'emoji' => $n['emoji'] ?? '✨',
            'tagline' => '',
            'depth' => $n['steps'] ?? 1,
        ];
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    private static function loadChain(array $player, string $day): array
    {
        $row = Db::one('SELECT * FROM cc_daily_chain WHERE player_id = ? AND day = ?', [$player['id'], $day]);
        $solved = [];
        if ($row && !empty($row['solved_keys'])) {
            $decoded = json_decode((string) $row['solved_keys'], true);
            $solved = is_array($decoded) ? $decoded : [];
        }
        // Recover L1 solves recorded before the chain table existed.
        if ($solved === []) {
            $legacy = Db::one('SELECT * FROM cc_daily WHERE player_id = ? AND day = ? AND solved = 1', [$player['id'], $day]);
            if ($legacy) {
                $t = self::target($day);
                if ($t) {
                    $solved[] = Text::key((string) $t['name']);
                }
            }
        }
        if ($row) {
            $history = [];
            // history is not a column; rebuild from solved keys + current
            $fresh = self::freshChain($day, $solved);
            $fresh['score'] = (int) $row['score'];
            $fresh['combos'] = (int) $row['combos'];
            $fresh['path'] = $row['path'] ?? '[]';
            $fresh['level'] = (int) $row['level'];
            $fresh['target_key'] = (string) $row['target_key'];
            $fresh['target_name'] = (string) $row['target_name'];
            $fresh['steps'] = (int) $row['steps'];
            $fresh['solved_list'] = $solved;
            $fresh['cleared'] = $fresh['target_key'] === '' || ($solved && end($solved) === $fresh['target_key'] && !self::pickForLevel($day, $fresh['level'] + 1, $solved));
            // If they already solved current (legacy) but row still shows old target, advance.
            if (in_array($fresh['target_key'], $solved, true) && !$fresh['cleared']) {
                $next = self::pickForLevel($day, $fresh['level'] + 1, $solved);
                if ($next) {
                    $fresh['level'] = $fresh['level'] + 1;
                    $fresh['target_key'] = $next['key'];
                    $fresh['target_name'] = $next['name'];
                    $fresh['steps'] = $next['steps'];
                    $fresh['combos'] = 0;
                    $fresh['path'] = '[]';
                    $fresh['cleared'] = false;
                    self::saveChain($player['id'], $day, $fresh);
                } else {
                    $fresh['cleared'] = true;
                    self::saveChain($player['id'], $day, $fresh);
                }
            }
            $fresh['history'] = self::historyFromSolved($day, $solved);
            return $fresh;
        }
        $chain = self::freshChain($day, $solved);
        if ($solved) {
            $next = self::pickForLevel($day, 2, $solved);
            if ($next) {
                $chain['level'] = 2;
                $chain['target_key'] = $next['key'];
                $chain['target_name'] = $next['name'];
                $chain['steps'] = $next['steps'];
            } else {
                $chain['cleared'] = true;
            }
            $chain['history'] = self::historyFromSolved($day, $solved);
            $chain['score'] = 10 + (self::stepsForKey($solved[0] ?? '') ?: 1);
        }
        self::saveChain($player['id'], $day, $chain);
        return $chain;
    }

    /** @param list<string> $solved @return array<string,mixed> */
    private static function freshChain(string $day, array $solved): array
    {
        $t = self::target($day);
        $key = $t ? Text::key((string) $t['name']) : '';
        $steps = $key !== '' ? (self::stepsForKey($key) ?: 1) : 1;
        return [
            'level' => 1,
            'score' => 0,
            'solved_list' => $solved,
            'target_key' => $key,
            'target_name' => $t['name'] ?? '',
            'steps' => $steps,
            'combos' => 0,
            'path' => '[]',
            'cleared' => false,
            'history' => [],
        ];
    }

    /** @param array<string,mixed> $chain */
    private static function saveChain(string $playerId, string $day, array $chain): void
    {
        $now = Db::now();
        $keys = json_encode(array_values($chain['solved_list'] ?? []), JSON_UNESCAPED_UNICODE) ?: '[]';
        $row = Db::one('SELECT player_id FROM cc_daily_chain WHERE player_id = ? AND day = ?', [$playerId, $day]);
        if ($row) {
            Db::run(
                'UPDATE cc_daily_chain SET level = ?, score = ?, solved_keys = ?, target_key = ?, target_name = ?, steps = ?, combos = ?, path = ?, updated_at = ? WHERE player_id = ? AND day = ?',
                [
                    (int) $chain['level'], (int) $chain['score'], $keys,
                    (string) $chain['target_key'], (string) $chain['target_name'], (int) $chain['steps'],
                    (int) $chain['combos'], (string) ($chain['path'] ?? '[]'), $now, $playerId, $day,
                ]
            );
            return;
        }
        try {
            Game::insertPublic('cc_daily_chain', [
                'player_id' => $playerId, 'day' => $day, 'level' => (int) $chain['level'],
                'score' => (int) $chain['score'], 'solved_keys' => $keys,
                'target_key' => (string) $chain['target_key'], 'target_name' => (string) $chain['target_name'],
                'steps' => (int) $chain['steps'], 'combos' => (int) $chain['combos'],
                'path' => (string) ($chain['path'] ?? '[]'), 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // Schema may not have been applied on the first request; status still works in memory.
            error_log('[combocraft daily chain] ' . $e->getMessage());
        }
    }

    /** @param list<string> $solved @return list<array<string,mixed>> */
    private static function historyFromSolved(string $day, array $solved): array
    {
        $out = [];
        $used = [];
        $level = 1;
        foreach ($solved as $key) {
            $n = self::graph()['nodes'][$key] ?? null;
            $out[] = [
                'level' => $level,
                'name' => $n['name'] ?? $key,
                'key' => $key,
                'steps' => $n['steps'] ?? self::stepsForKey($key),
                'combos' => 0,
            ];
            $used[] = $key;
            $level++;
        }
        return $out;
    }

    /**
     * Date-seeded pick for a level, skipping already solved keys.
     * Level L aims for 2L-1 .. 2L combination steps (L1=1–2, L2=3–4, L3=5–6…).
     * @param list<string> $exclude
     * @return array{key:string,name:string,steps:int,emoji:string}|null
     */
    public static function pickForLevel(string $day, int $level, array $exclude): ?array
    {
        if ($level <= 1) {
            $t = self::target($day);
            if (!$t) {
                return null;
            }
            $key = Text::key((string) $t['name']);
            return ['key' => $key, 'name' => (string) $t['name'], 'steps' => self::stepsForKey($key) ?: 1, 'emoji' => (string) ($t['emoji'] ?? '✨')];
        }
        $g = self::graph();
        $wantMin = 2 * $level - 1;
        $wantMax = 2 * $level;
        $buckets = [];
        foreach ($g['nodes'] as $key => $n) {
            if (($n['steps'] ?? 0) <= 0) {
                continue;
            }
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $buckets[(int) $n['steps']][] = $n + ['key' => $key];
        }
        $pool = [];
        for ($s = $wantMin; $s <= $wantMax; $s++) {
            if (!empty($buckets[$s])) {
                $pool = array_merge($pool, $buckets[$s]);
            }
        }
        if ($pool === []) {
            // Next deeper band, then any unused at least as long as the previous band's min.
            $maxS = $g['maxSteps'] ?? 0;
            for ($s = $wantMax + 1; $s <= $maxS; $s++) {
                if (!empty($buckets[$s])) {
                    $pool = array_merge($pool, $buckets[$s]);
                    break;
                }
            }
        }
        if ($pool === []) {
            for ($s = $wantMin - 1; $s >= 1; $s--) {
                if (!empty($buckets[$s])) {
                    $pool = array_merge($pool, $buckets[$s]);
                    break;
                }
            }
        }
        if ($pool === []) {
            return null;
        }
        $idx = hexdec(substr(hash('sha256', 'cc-daily-v2|' . $day . '|' . $level), 0, 8)) % count($pool);
        $n = $pool[$idx];
        self::ensureNamed($n['name']);
        return ['key' => $n['key'], 'name' => $n['name'], 'steps' => (int) $n['steps'], 'emoji' => $n['emoji'] ?? '✨'];
    }

    public static function stepsForKey(string $key): int
    {
        $g = self::graph();
        return (int) ($g['nodes'][$key]['steps'] ?? 0);
    }

    public static function stepsLabel(int $steps): string
    {
        if ($steps <= 0) {
            return 'a starter';
        }
        if ($steps === 1) {
            return 'about 1 combo';
        }
        return 'about ' . $steps . ' combos';
    }

    /**
     * @return array{nodes:array<string,array<string,mixed>>,recipes:list<array{a:string,b:string,r:string}>,maxSteps:int}
     */
    public static function graph(): array
    {
        if (self::$graphCache !== null) {
            return self::$graphCache;
        }
        $nodes = [];
        $recipes = [];
        foreach (Seeds::BASE as $name => $meta) {
            $k = Text::key($name);
            $nodes[$k] = ['name' => $name, 'emoji' => $meta[0], 'steps' => 0, 'parents' => []];
        }
        foreach (Seeds::RECIPES as $pair => $r) {
            [$a, $b] = explode('|', $pair, 2);
            $rk = Text::key($r[0]);
            $recipes[] = ['a' => $a, 'b' => $b, 'r' => $rk];
            if (!isset($nodes[$rk])) {
                $nodes[$rk] = ['name' => $r[0], 'emoji' => $r[1], 'steps' => 99, 'parents' => [[$a, $b]]];
            } else {
                $nodes[$rk]['parents'][] = [$a, $b];
            }
        }
        try {
            $rows = Db::all(
                'SELECT ea.name_key AS a_key, ea.name AS a_name, ea.emoji AS a_emoji,
                        eb.name_key AS b_key, eb.name AS b_name, eb.emoji AS b_emoji,
                        er.name_key AS r_key, er.name AS r_name, er.emoji AS r_emoji
                 FROM cc_recipes r
                 JOIN cc_elements ea ON ea.id = r.a_id
                 JOIN cc_elements eb ON eb.id = r.b_id
                 JOIN cc_elements er ON er.id = r.result_id'
            );
            foreach ($rows as $row) {
                $a = (string) $row['a_key'];
                $b = (string) $row['b_key'];
                $rk = (string) $row['r_key'];
                $recipes[] = ['a' => $a, 'b' => $b, 'r' => $rk];
                if (!isset($nodes[$a])) {
                    $nodes[$a] = ['name' => $row['a_name'], 'emoji' => $row['a_emoji'], 'steps' => 99, 'parents' => []];
                }
                if (!isset($nodes[$b])) {
                    $nodes[$b] = ['name' => $row['b_name'], 'emoji' => $row['b_emoji'], 'steps' => 99, 'parents' => []];
                }
                if (!isset($nodes[$rk])) {
                    $nodes[$rk] = ['name' => $row['r_name'], 'emoji' => $row['r_emoji'], 'steps' => 99, 'parents' => [[$a, $b]]];
                } else {
                    $nodes[$rk]['parents'][] = [$a, $b];
                }
            }
        } catch (\Throwable $e) {
            // Recipes table may be empty on a fresh install.
        }
        // Relax minimum combo counts from the 12 bases.
        $changed = true;
        $guard = 0;
        while ($changed && $guard++ < 80) {
            $changed = false;
            foreach ($recipes as $rec) {
                if (!isset($nodes[$rec['a']], $nodes[$rec['b']], $nodes[$rec['r']])) {
                    continue;
                }
                $ca = $nodes[$rec['a']]['steps'];
                $cb = $nodes[$rec['b']]['steps'];
                if ($ca >= 99 || $cb >= 99) {
                    continue;
                }
                $cost = ($rec['a'] === $rec['b'] ? $ca : $ca + $cb) + 1;
                if ($cost < $nodes[$rec['r']]['steps']) {
                    $nodes[$rec['r']]['steps'] = $cost;
                    $changed = true;
                }
            }
        }
        $max = 0;
        foreach ($nodes as $n) {
            if ($n['steps'] < 99 && $n['steps'] > $max) {
                $max = $n['steps'];
            }
        }
        self::$graphCache = ['nodes' => $nodes, 'recipes' => $recipes, 'maxSteps' => $max];
        return self::$graphCache;
    }

    /**
     * @param array<string,array<string,mixed>> $ownedByKey
     * @param array<string,mixed> $g
     * @return array{aName:string,bName:string,final:bool}|null
     */
    private static function nextPathStep(string $target, array $ownedByKey, array $g, int $level): ?array
    {
        $nodes = $g['nodes'];
        if (!isset($nodes[$target])) {
            return null;
        }
        // Walk backward. Prefer a recipe the player can already perform;
        // otherwise hint a recipe that produces a missing parent.
        $queue = [$target];
        $seen = [$target => true];
        $candidates = [];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($nodes[$cur]['parents'] ?? [] as $pair) {
                [$a, $b] = $pair;
                $haveA = isset($ownedByKey[$a]);
                $haveB = isset($ownedByKey[$b]);
                $haveR = isset($ownedByKey[$cur]);
                if ($haveA && $haveB && !$haveR) {
                    $candidates[] = [
                        'aName' => $nodes[$a]['name'],
                        'bName' => $nodes[$b]['name'],
                        'final' => $cur === $target,
                        'depth' => $nodes[$cur]['steps'] ?? 1,
                    ];
                }
                if (!isset($seen[$a]) && !isset($ownedByKey[$a])) {
                    $seen[$a] = true;
                    $queue[] = $a;
                }
                if (!isset($seen[$b]) && !isset($ownedByKey[$b])) {
                    $seen[$b] = true;
                    $queue[] = $b;
                }
            }
        }
        if ($candidates === []) {
            return null;
        }
        // Low levels: prefer the final combo. High levels: prefer an earlier intermediate.
        usort($candidates, static function ($x, $y) use ($target, $level) {
            if ($level <= 2) {
                return ($y['final'] <=> $x['final']);
            }
            return ($x['final'] <=> $y['final']) ?: ($x['depth'] <=> $y['depth']);
        });
        return $candidates[0];
    }

    /** @param array<string,mixed> $player @param array<string,mixed> $result */
    private static function markLegacySolved(array $player, string $day, array $result, array $path, int $combos): void
    {
        $t = self::target($day);
        $tid = $t['id'] ?? ($result['id'] ?? 0);
        $row = Db::one('SELECT * FROM cc_daily WHERE player_id = ? AND day = ?', [$player['id'], $day]);
        $now = Db::now();
        if ($row) {
            Db::run(
                'UPDATE cc_daily SET combos = ?, path = ?, solved = 1, solved_at = COALESCE(solved_at, ?) WHERE player_id = ? AND day = ?',
                [$combos, json_encode($path, JSON_UNESCAPED_UNICODE), $now, $player['id'], $day]
            );
            return;
        }
        try {
            Game::insertPublic('cc_daily', [
                'player_id' => $player['id'], 'day' => $day, 'target_id' => $tid,
                'solved' => 1, 'combos' => $combos,
                'path' => json_encode($path, JSON_UNESCAPED_UNICODE),
                'solved_at' => $now,
            ]);
        } catch (\Throwable $e) {
        }
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

    /** @param array<string,mixed>|null $target @param array<string,mixed> $chain */
    public static function shareText(string $day, ?array $target, $chainOrRow, int $streak): string
    {
        $chain = is_array($chainOrRow) && isset($chainOrRow['level']) ? $chainOrRow : [];
        $name = $target['name'] ?? ($chain['target_name'] ?? '???');
        $emoji = $target['emoji'] ?? '✨';
        $level = (int) ($chain['level'] ?? 1);
        $score = (int) ($chain['score'] ?? 0);
        $cleared = !empty($chain['cleared']);
        $solvedN = count($chain['solved_list'] ?? []);
        $lines = ['ComboCraft Daily ' . $day, 'Level ' . $level . ' · ' . $emoji . ' ' . $name];
        if ($score || $solvedN) {
            $lines[] = 'Score ' . $score . ' · ' . $solvedN . ' level' . ($solvedN === 1 ? '' : 's') . ' cleared';
        }
        if ($cleared) {
            $lines[] = 'Chain complete · 🔥 ' . $streak . ' streak';
        } elseif ($solvedN) {
            $lines[] = '🔥 ' . $streak . ' streak · now on Level ' . $level;
        } else {
            $lines[] = 'Still hunting…';
        }
        return implode("\n", $lines);
    }
}
