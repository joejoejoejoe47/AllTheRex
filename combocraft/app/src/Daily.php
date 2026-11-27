<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Daily Challenge chain: a date-seeded ramp from Very Easy (two basic
 * elements) up to Hard (two rare elements). Same day, same targets for
 * everyone. Progress is stored per player in cc_daily_chain; existing
 * rows are never rewritten, so mid-day progress and rewards stay put.
 */
final class Daily
{
    public const TOTAL_LEVELS = 5;

    /** Visible Easy → Hard ramp. Each level prefers the listed ingredient pairs, then the next-closest band. */
    public const TIERS = [
        1 => ['id' => 'very-easy', 'label' => 'Very Easy', 'blurb' => 'Two basic elements', 'want' => [['basic', 'basic']]],
        2 => ['id' => 'easy', 'label' => 'Easy', 'blurb' => 'A basic and a common', 'want' => [['basic', 'common']]],
        3 => ['id' => 'medium', 'label' => 'Medium', 'blurb' => 'Two common elements', 'want' => [['common', 'common'], ['basic', 'rare']]],
        4 => ['id' => 'medium-hard', 'label' => 'Medium-Hard', 'blurb' => 'A common and a rare', 'want' => [['common', 'rare'], ['basic', 'epic'], ['common', 'epic']]],
        5 => ['id' => 'hard', 'label' => 'Hard', 'blurb' => 'Two rare elements', 'want' => [['rare', 'rare'], ['rare', 'epic'], ['epic', 'epic'], ['rare', 'legendary'], ['epic', 'legendary']]],
    ];

    /** Fun mid-tier seed results used by the legacy Level 1 picker. Kept so already-started days stay stable. */
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
        $auto = [];
        $coins = ['justCoins' => 0, 'byLevel' => []];
        if ($player) {
            $auto = self::claimOwned($player, $day, $chain);
            $claimLevels = array_values(array_unique(array_map(static fn ($row) => (int) ($row['level'] ?? 0), $auto)));
            $coins = MorseLink::grantDailyLevels($player, $day, $claimLevels);
        }
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
            'autoSolved' => $auto,
            'justCoins' => (int) ($coins['justCoins'] ?? 0),
            'coinGrants' => $coins['byLevel'] ?? [],
        ] + self::difficultyView((int) $chain['level'], $cleared, $chain['solved_list'] ?? [], (string) ($chain['target_key'] ?? ''));
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
        // Count it even when this result was already in the collection.
        // Match by name key, stored name_key, element id, or a folded name —
        // never require a first-time discovery ($newForYou is ignored).
        if (empty($chain['cleared']) && self::hitsTarget($result, $chain)) {
            $just = true;
            $solved = $chain['solved_list'];
            $hitKey = (string) $chain['target_key'];
            if ($hitKey === '') {
                $hitKey = $resultKey;
            }
            $solved[] = $hitKey;
            $chain['solved_list'] = array_values(array_unique($solved));
            $gain = 10 * (int) $chain['level'] + (int) $chain['steps'];
            $chain['score'] = (int) $chain['score'] + $gain;
            $chain['history'][] = [
                'level' => (int) $chain['level'],
                'name' => $chain['target_name'],
                'key' => $hitKey,
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
        if ($just) {
            $pay = MorseLink::grantDailyLevels($player, $day, [$prevLevel]);
            $status['justCoins'] = (int) ($status['justCoins'] ?? 0) + (int) ($pay['justCoins'] ?? 0);
            if (!empty($pay['byLevel'])) {
                $status['coinGrants'] = ($status['coinGrants'] ?? []) + $pay['byLevel'];
            }
        }
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
        $t = self::pickForLevel($day, 1, $solved);
        $key = $t['key'] ?? '';
        $steps = (int) ($t['steps'] ?? ($key !== '' ? (self::stepsForKey($key) ?: 1) : 1));
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
     * Levels follow the Easy → Hard ingredient ramp (basic+basic up to rare+rare).
     * @param list<string> $exclude
     * @return array{key:string,name:string,steps:int,emoji:string,pair:?array}|null
     */
    public static function pickForLevel(string $day, int $level, array $exclude): ?array
    {
        if ($level < 1) {
            return null;
        }
        if ($level > self::TOTAL_LEVELS) {
            return null;
        }
        $g = self::graph();
        $candidates = self::candidatesForLevel($g, $level, $exclude);
        if ($candidates === []) {
            return null;
        }
        $idx = hexdec(substr(hash('sha256', 'cc-daily-v3|' . $day . '|' . $level), 0, 8)) % count($candidates);
        $n = $candidates[$idx];
        self::ensureNamed($n['name']);
        return [
            'key' => $n['key'],
            'name' => $n['name'],
            'steps' => (int) $n['steps'],
            'emoji' => $n['emoji'] ?? '✨',
            'pair' => $n['pair'] ?? null,
        ];
    }

    /** @return array{id:string,label:string,blurb:string,want:list<array{0:string,1:string}>} */
    public static function tierInfo(int $level): array
    {
        $level = max(1, min(self::TOTAL_LEVELS, $level));
        return self::TIERS[$level];
    }

    /**
     * Fields the client uses to draw the Easy → Hard ramp.
     * @param list<string> $solved
     * @return array<string,mixed>
     */
    public static function difficultyView(int $level, bool $cleared, array $solved, string $targetKey = ''): array
    {
        $info = self::tierInfo($level);
        $blurb = $info['blurb'];
        if ($targetKey !== '') {
            $pair = self::bestPair($targetKey, self::graph()['nodes']);
            if (!empty($pair['blurb'])) {
                $blurb = (string) $pair['blurb'];
            }
        }
        $tiers = [];
        $solvedN = count($solved);
        for ($i = 1; $i <= self::TOTAL_LEVELS; $i++) {
            $t = self::TIERS[$i];
            $state = 'todo';
            if ($cleared || $i < $level || ($i === $level && $cleared)) {
                $state = 'done';
            } elseif ($i === $level) {
                $state = 'current';
            }
            // Already-solved keys at this index count as done even if the player is still on that level mid-claim.
            if ($i <= $solvedN && $i < $level) {
                $state = 'done';
            }
            $tiers[] = [
                'level' => $i,
                'id' => $t['id'],
                'label' => $t['label'],
                'blurb' => $t['blurb'],
                'state' => $state,
            ];
        }
        return [
            'difficulty' => $info['id'],
            'difficultyLabel' => $info['label'],
            'difficultyBlurb' => $blurb,
            'stepsLabel' => $blurb,
            'totalLevels' => self::TOTAL_LEVELS,
            'tiers' => $tiers,
        ];
    }

    /**
     * @param array{nodes:array<string,array<string,mixed>>,recipes:list<array{a:string,b:string,r:string}>,maxSteps:int} $g
     * @param list<string> $exclude
     * @return list<array<string,mixed>>
     */
    private static function candidatesForLevel(array $g, int $level, array $exclude): array
    {
        $info = self::tierInfo($level);
        $ranked = [];
        foreach ($g['nodes'] as $key => $n) {
            if (($n['steps'] ?? 0) <= 0 || ($n['steps'] ?? 0) >= 99) {
                continue;
            }
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $pair = self::bestPair($key, $g['nodes']);
            if ($pair === null) {
                continue;
            }
            $band = $pair['a'] . '+' . $pair['b'];
            $ranked[$band][] = $n + ['key' => $key, 'pair' => $pair];
        }
        $pool = [];
        foreach ($info['want'] as $want) {
            $band = $want[0] . '+' . $want[1];
            if (!empty($ranked[$band])) {
                $pool = $ranked[$band];
                break;
            }
        }
        if ($pool === []) {
            $wantScore = self::bandScore($info['want'][0][0], $info['want'][0][1]);
            $best = null;
            $bestKey = null;
            foreach ($ranked as $band => $rows) {
                [$ta, $tb] = explode('+', $band, 2);
                $score = self::bandScore($ta, $tb);
                $delta = abs($score - $wantScore);
                $harder = $score >= $wantScore ? 0 : 1;
                $sort = sprintf('%d-%d-%02d', $delta, $harder, 9 - $score);
                if ($bestKey === null || $sort < $bestKey) {
                    $bestKey = $sort;
                    $best = $rows;
                }
            }
            $pool = $best ?? [];
            // Within a fallback band, earlier levels take shallower recipes and later ones take deeper ones.
            if ($pool) {
                $steps = [];
                foreach ($pool as $row) {
                    $steps[(int) ($row['steps'] ?? 0)] = true;
                }
                $steps = array_keys($steps);
                sort($steps);
                if ($level <= 3) {
                    $pickSteps = [$steps[0]];
                } elseif ($level >= self::TOTAL_LEVELS) {
                    $pickSteps = [end($steps)];
                } else {
                    $pickSteps = [$steps[(int) floor((count($steps) - 1) / 2)]];
                }
                $pool = array_values(array_filter($pool, static fn ($row) => in_array((int) ($row['steps'] ?? 0), $pickSteps, true)));
            }
        }
        return array_values($pool);
    }

    /** @param array<string,array<string,mixed>> $nodes @return array{a:string,b:string,aName:string,bName:string,blurb:string}|null */
    private static function bestPair(string $key, array $nodes): ?array
    {
        $n = $nodes[$key] ?? null;
        if (!$n) {
            return null;
        }
        $best = null;
        $bestScore = 99;
        foreach ($n['parents'] ?? [] as $pair) {
            $a = (string) $pair[0];
            $b = (string) $pair[1];
            $ta = self::ingredientTier($a, $nodes);
            $tb = self::ingredientTier($b, $nodes);
            if ($ta === 'unknown' || $tb === 'unknown') {
                continue;
            }
            if (self::tierRank($ta) > self::tierRank($tb)) {
                [$ta, $tb] = [$tb, $ta];
                [$a, $b] = [$b, $a];
            }
            $score = self::bandScore($ta, $tb);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = [
                    'a' => $ta,
                    'b' => $tb,
                    'aName' => $nodes[$a]['name'] ?? $a,
                    'bName' => $nodes[$b]['name'] ?? $b,
                    'blurb' => self::pairBlurb($ta, $tb),
                ];
            }
        }
        return $best;
    }

    /** @param array<string,array<string,mixed>> $nodes */
    public static function ingredientTier(string $key, ?array $nodes = null): string
    {
        $nodes = $nodes ?? self::graph()['nodes'];
        $n = $nodes[$key] ?? null;
        if (!$n) {
            return 'unknown';
        }
        $steps = (int) ($n['steps'] ?? 99);
        if ($steps === 0) {
            return 'basic';
        }
        if ($steps === 1) {
            return 'common';
        }
        if ($steps === 2) {
            return 'rare';
        }
        if ($steps === 3) {
            return 'epic';
        }
        if ($steps < 99) {
            return 'legendary';
        }
        return 'unknown';
    }

    private static function tierRank(string $tier): int
    {
        return ['basic' => 0, 'common' => 1, 'rare' => 2, 'epic' => 3, 'legendary' => 4][$tier] ?? 9;
    }

    private static function bandScore(string $a, string $b): int
    {
        return self::tierRank($a) + self::tierRank($b);
    }

    public static function pairBlurb(string $a, string $b): string
    {
        if ($a === $b) {
            return 'Two ' . $a . ' elements';
        }
        $an = $a === 'basic' ? 'a basic' : ('a ' . $a);
        $bn = $b === 'basic' ? 'a basic' : ('a ' . $b);
        return ucfirst($an) . ' and ' . $bn;
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


    /**
     * True when this combine result is the current daily target, even if the
     * player already had the element. Does not depend on first-time discovery.
     * @param array<string,mixed> $result
     * @param array<string,mixed> $chain
     */
    public static function hitsTarget(array $result, array $chain): bool
    {
        return self::targetMatchKeys($chain, (string) ($result['name'] ?? ''), (int) ($result['id'] ?? 0));
    }

    /**
     * Fold a name/key for a looser comparison (spaces, punctuation, case).
     */
    public static function foldName(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
        return $s;
    }

    /**
     * @param array<string,mixed> $chain
     */
    private static function targetMatchKeys(array $chain, string $name, int $id): bool
    {
        $targetKey = (string) ($chain['target_key'] ?? '');
        $targetName = (string) ($chain['target_name'] ?? '');
        if ($targetKey === '' && $targetName === '') {
            return false;
        }
        $resultKey = Text::key($name);
        if ($targetKey !== '' && $resultKey !== '' && $resultKey === $targetKey) {
            return true;
        }
        if ($targetName !== '' && $resultKey !== '' && $resultKey === Text::key($targetName)) {
            return true;
        }
        $fold = self::foldName($name);
        if ($fold !== '' && ($fold === self::foldName($targetName) || $fold === self::foldName($targetKey))) {
            return true;
        }
        $el = null;
        if ($id > 0) {
            $el = Db::one('SELECT id, name, name_key FROM cc_elements WHERE id = ?', [$id]);
        }
        if ($el === null && $resultKey !== '') {
            $el = Db::one('SELECT id, name, name_key FROM cc_elements WHERE name_key = ?', [$resultKey]);
        }
        if ($el === null && $fold !== '') {
            $el = Db::one('SELECT id, name, name_key FROM cc_elements WHERE REPLACE(LOWER(name_key), \' \', \'\') = ? OR REPLACE(LOWER(name), \' \', \'\') = ?', [$fold, $fold]);
        }
        if ($el === null) {
            return false;
        }
        // Compare the catalog row to the daily TARGET, never to the result
        // itself. A previous fold check compared $el to $fold (the result),
        // which made every owned element look like a hit and auto-advanced
        // the daily chain on every page load.
        $ek = (string) $el['name_key'];
        $en = Text::key((string) $el['name']);
        $elFold = self::foldName((string) $el['name'] !== '' ? (string) $el['name'] : $ek);
        $targetFold = self::foldName($targetName !== '' ? $targetName : $targetKey);
        if ($targetKey !== '' && ($ek === $targetKey || $en === $targetKey || ($elFold !== '' && $elFold === self::foldName($targetKey)))) {
            return true;
        }
        if ($targetName !== '' && ($en === Text::key($targetName) || $ek === Text::key($targetName) || ($targetFold !== '' && $elFold === $targetFold))) {
            return true;
        }
        if ($id > 0 && $targetKey !== '') {
            $t = Db::one('SELECT id FROM cc_elements WHERE name_key = ?', [$targetKey]);
            if ($t && (int) $t['id'] === (int) $el['id']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every owned element, indexed by name_key, Text::key(name), and folded name.
     * @param array<string,mixed> $player
     * @return array<string,array<string,mixed>>
     */
    private static function ownedLookup(array $player): array
    {
        $owned = [];
        $index = static function (array &$owned, array $el): void {
            $keys = [];
            $name = (string) ($el['name'] ?? '');
            $nk = (string) ($el['name_key'] ?? '');
            if ($nk !== '') {
                $keys[] = $nk;
            }
            $tk = Text::key($name);
            if ($tk !== '') {
                $keys[] = $tk;
            }
            $fold = self::foldName($name !== '' ? $name : $nk);
            if ($fold !== '') {
                $keys[] = $fold;
            }
            foreach ($keys as $k) {
                if ($k !== '' && !isset($owned[$k])) {
                    $owned[$k] = $el;
                }
            }
        };
        try {
            foreach (Game::collection($player) as $el) {
                $index($owned, $el);
            }
        } catch (\Throwable $e) {
            // Fall through and try a raw query.
        }
        try {
            $bases = Db::all('SELECT id, name, name_key, emoji FROM cc_elements WHERE is_base = 1');
            foreach ($bases as $el) {
                $index($owned, $el);
            }
            $mine = Db::all(
                'SELECT e.id, e.name, e.name_key, e.emoji FROM cc_collection c JOIN cc_elements e ON e.id = c.element_id WHERE c.player_id = ?',
                [$player['id']]
            );
            foreach ($mine as $el) {
                $index($owned, $el);
            }
        } catch (\Throwable $e) {
            error_log('[combocraft daily owned] ' . $e->getMessage());
        }
        return $owned;
    }

    /**
     * The owned element that matches the current chain target, if any.
     * @param array<string,array<string,mixed>> $owned
     * @param array<string,mixed> $chain
     * @return array<string,mixed>|null
     */
    private static function ownedHit(array $owned, array $chain): ?array
    {
        $key = (string) ($chain['target_key'] ?? '');
        $name = (string) ($chain['target_name'] ?? '');
        foreach ([$key, Text::key($name), self::foldName($key), self::foldName($name)] as $k) {
            if ($k !== '' && isset($owned[$k])) {
                return $owned[$k];
            }
        }
        // Last resort: walk owned elements (small collections) with hitsTarget.
        $seen = [];
        foreach ($owned as $el) {
            $id = (int) ($el['id'] ?? 0);
            if ($id && isset($seen[$id])) {
                continue;
            }
            if ($id) {
                $seen[$id] = true;
            }
            if (self::hitsTarget($el, $chain)) {
                return $el;
            }
        }
        return null;
    }

    /**
     * If the player already owns the current target, mark it solved, award
     * the usual daily score, and keep advancing while they already have
     * the next ones too.
     * @param array<string,mixed> $player
     * @param array<string,mixed> $chain
     * @return list<array{level:int,name:string,key:string,scoreGain:int}>
     */
    private static function claimOwned(array $player, string $day, array &$chain): array
    {
        $owned = self::ownedLookup($player);
        if ($owned === []) {
            return [];
        }
        $claimed = [];
        $changed = false;
        for ($i = 0; $i < 40; $i++) {
            if (!empty($chain['cleared'])) {
                break;
            }
            $key = (string) ($chain['target_key'] ?? '');
            $hit = self::ownedHit($owned, $chain);
            if ($key === '' || $hit === null) {
                break;
            }
            $level = (int) $chain['level'];
            $name = (string) $chain['target_name'];
            $steps = (int) $chain['steps'];
            $gain = 10 * $level + $steps;
            if (!in_array($key, $chain['solved_list'], true)) {
                $chain['solved_list'][] = $key;
                $chain['solved_list'] = array_values(array_unique($chain['solved_list']));
                $chain['score'] = (int) $chain['score'] + $gain;
                if (count($chain['solved_list']) === 1) {
                    self::markLegacySolved($player, $day, $hit, [], 0);
                    self::bumpStreak($player['id'], $day);
                }
                $claimed[] = [
                    'level' => $level,
                    'name' => $name,
                    'key' => $key,
                    'scoreGain' => $gain,
                ];
                $changed = true;
            }
            $next = self::pickForLevel($day, $level + 1, $chain['solved_list']);
            if ($next) {
                $chain['level'] = $level + 1;
                $chain['target_key'] = $next['key'];
                $chain['target_name'] = $next['name'];
                $chain['steps'] = $next['steps'];
                $chain['combos'] = 0;
                $chain['path'] = json_encode([], JSON_UNESCAPED_UNICODE);
                $chain['cleared'] = false;
                $changed = true;
            } else {
                $chain['cleared'] = true;
                $changed = true;
                break;
            }
        }
        if ($changed) {
            $chain['history'] = self::historyFromSolved($day, $chain['solved_list']);
            self::saveChain($player['id'], $day, $chain);
        }
        return $claimed;
    }

    /** @param array<string,mixed> $chain @return list<int> */
    private static function solvedLevels(array $chain): array
    {
        $out = [];
        foreach ($chain['history'] ?? [] as $row) {
            $n = (int) ($row['level'] ?? 0);
            if ($n > 0) {
                $out[] = $n;
            }
        }
        if ($out === [] && !empty($chain['solved_list'])) {
            // History may be empty on a very old row; levels were claimed in order.
            $out = range(1, count($chain['solved_list']));
        }
        return $out;
    }

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
        $diff = self::tierInfo($level)['label'];
        $lines = ['ComboCraft Daily ' . $day, 'Level ' . $level . ' of ' . self::TOTAL_LEVELS . ' · ' . $diff . ' · ' . $emoji . ' ' . $name];
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
