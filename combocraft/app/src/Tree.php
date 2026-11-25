<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Family trees for crafted elements, plus the Deepest Tree leaderboard.
 * Additive cc_tree_best table only.
 */
final class Tree
{
    private const MAX_DEPTH = 24;
    private const HIDDEN = '/^(CcBot|CcFin|CcQA|CcTest|CcAi|CcAud|CcHide|CcPty|CcDay)/i';

    public static function boot(): void
    {
        static $ok = false;
        if ($ok) {
            return;
        }
        try {
            Db::run(
                "CREATE TABLE IF NOT EXISTS cc_tree_best (
                    player_id VARCHAR(24) NOT NULL PRIMARY KEY,
                    element_id INT NOT NULL,
                    element_name VARCHAR(160) NOT NULL DEFAULT '',
                    tree_size INT NOT NULL DEFAULT 0,
                    depth INT NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL
                )"
            );
        } catch (\Throwable $e) {
            error_log('[combocraft tree boot] ' . $e->getMessage());
        }
        $ok = true;
    }

    public static function isHiddenName(?string $name): bool
    {
        $n = trim((string) $name);
        return $n !== '' && (bool) preg_match(self::HIDDEN, $n);
    }

    /**
     * Family tree for an element. Prefers the viewing player's own recipe
     * when it was recorded, otherwise the first-known recipe.
     * @param array<string,mixed>|null $player
     * @return array<string,mixed>
     */
    public static function of(int $id, ?array $player): array
    {
        $e = Game::element($id);
        if ($e === null) {
            throw new HttpError('Not found', 404);
        }
        $seen = [];
        $combos = [];
        $root = self::walk($id, [], $player, true, 0, $seen, $combos);
        $size = count($combos);
        $depth = self::depthOf($root);
        return [
            'element' => Game::view($e),
            'root' => $root,
            'size' => $size,
            'depth' => $depth,
            'base' => (int) $e['is_base'] === 1,
        ];
    }

    /**
     * Canonical (world-first / first-known recipe) size and depth, used for
     * the Deepest Tree board so scores do not depend on who is looking.
     * @return array{size:int,depth:int}
     */
    public static function stats(int $id): array
    {
        $seen = [];
        $combos = [];
        $root = self::walk($id, [], null, false, 0, $seen, $combos);
        return ['size' => count($combos), 'depth' => self::depthOf($root)];
    }

    /**
     * Record a world-first discovery onto the Deepest Tree board if it beats
     * this player's current best.
     * @param array<string,mixed> $player
     * @param array<string,mixed> $element
     */
    public static function record(array $player, array $element): void
    {
        self::boot();
        if (self::isHiddenName($player['nickname'] ?? null)) {
            return;
        }
        $id = (int) ($element['id'] ?? 0);
        if ($id <= 0 || (int) ($element['is_base'] ?? 0) === 1) {
            return;
        }
        $stats = self::stats($id);
        if ($stats['size'] < 1) {
            return;
        }
        self::upsertBest((string) $player['id'], $id, (string) ($element['name'] ?? ''), $stats['size'], $stats['depth']);
    }


    /** The current player's best tree, without running a backfill. */
    public static function mine(?array $me): ?array
    {
        if (!$me) {
            return null;
        }
        self::boot();
        try {
            $own = Db::one('SELECT * FROM cc_tree_best WHERE player_id = ?', [$me['id']]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$own || (int) $own['tree_size'] < 1 || self::isHiddenName($me['nickname'] ?? null)) {
            return null;
        }
        $el = Game::element((int) $own['element_id']);
        return [
            'rank' => null,
            'id' => $me['id'],
            'nickname' => $me['nickname'] ?: 'You',
            'avatar' => $me['avatar'] ?: '🐱',
            'size' => (int) $own['tree_size'],
            'depth' => (int) $own['depth'],
            'element' => $el ? Game::view($el, true) : ['name' => (string) $own['element_name'], 'emoji' => '🌳'],
        ];
    }

    /** @return array<string,mixed> */
    public static function board(?array $me = null, bool $fill = true): array
    {
        self::boot();
        if ($fill) {
            self::backfill(80);
        }
        $rows = [];
        try {
            $raw = Db::all(
                'SELECT b.player_id, b.element_id, b.element_name, b.tree_size, b.depth, b.updated_at,
                        p.nickname, p.avatar
                 FROM cc_tree_best b
                 JOIN cc_players p ON p.id = b.player_id
                 WHERE b.tree_size > 0
                 ORDER BY b.tree_size DESC, b.depth DESC, b.updated_at ASC
                 LIMIT 80'
            );
        } catch (\Throwable $e) {
            $raw = [];
        }
        $rank = 0;
        foreach ($raw as $r) {
            if (self::isHiddenName($r['nickname'] ?? null)) {
                continue;
            }
            $rank++;
            $el = Game::element((int) $r['element_id']);
            $rows[] = [
                'rank' => $rank,
                'id' => $r['player_id'],
                'nickname' => $r['nickname'] ?: 'Anonymous crafter',
                'avatar' => $r['avatar'] ?: '🐱',
                'size' => (int) $r['tree_size'],
                'depth' => (int) $r['depth'],
                'element' => $el ? Game::view($el, true) : [
                    'id' => (int) $r['element_id'],
                    'name' => (string) $r['element_name'],
                    'emoji' => '🌳',
                ],
            ];
            if (count($rows) >= 50) {
                break;
            }
        }
        $top = $rows[0] ?? null;
        $mine = null;
        if ($me) {
            foreach ($rows as $row) {
                if ($row['id'] === $me['id']) {
                    $mine = $row;
                    break;
                }
            }
            if ($mine === null) {
                $own = Db::one('SELECT * FROM cc_tree_best WHERE player_id = ?', [$me['id']]);
                if ($own && (int) $own['tree_size'] > 0 && !self::isHiddenName($me['nickname'] ?? null)) {
                    $better = (int) Db::value(
                        'SELECT COUNT(*) FROM cc_tree_best b JOIN cc_players p ON p.id = b.player_id
                         WHERE b.tree_size > ? OR (b.tree_size = ? AND (b.depth > ? OR (b.depth = ? AND b.updated_at < ?)))',
                        [$own['tree_size'], $own['tree_size'], $own['depth'], $own['depth'], $own['updated_at']]
                    );
                    // Hidden names may inflate this slightly; fine for a personal rank hint.
                    $el = Game::element((int) $own['element_id']);
                    $mine = [
                        'rank' => $better + 1,
                        'id' => $me['id'],
                        'nickname' => $me['nickname'] ?: 'You',
                        'avatar' => $me['avatar'] ?: '🐱',
                        'size' => (int) $own['tree_size'],
                        'depth' => (int) $own['depth'],
                        'element' => $el ? Game::view($el, true) : ['name' => (string) $own['element_name'], 'emoji' => '🌳'],
                    ];
                }
            }
        }
        return ['rows' => $rows, 'top' => $top, 'mine' => $mine];
    }

    /** A compact #1 blurb for badges. */
    public static function headline(): ?array
    {
        self::boot();
        try {
            $raw = Db::all(
                'SELECT b.tree_size, b.depth, b.element_id, b.element_name, p.nickname
                 FROM cc_tree_best b
                 JOIN cc_players p ON p.id = b.player_id
                 WHERE b.tree_size > 0
                 ORDER BY b.tree_size DESC, b.depth DESC, b.updated_at ASC
                 LIMIT 20'
            );
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($raw as $r) {
            if (self::isHiddenName($r['nickname'] ?? null)) {
                continue;
            }
            $el = Game::element((int) $r['element_id']);
            return [
                'nickname' => $r['nickname'] ?: 'Anonymous crafter',
                'size' => (int) $r['tree_size'],
                'depth' => (int) $r['depth'],
                'element' => [
                    'id' => (int) ($el['id'] ?? $r['element_id']),
                    'name' => (string) ($el['name'] ?? $r['element_name']),
                    'emoji' => $el ? (Game::view($el)['emoji'] ?? '🌳') : '🌳',
                ],
            ];
        }
        return null;
    }

    /**
     * @param list<int> $path
     * @param array<int,true> $seen
     * @param array<int,true> $combos
     * @return array<string,mixed>
     */
    private static function walk(int $id, array $path, ?array $player, bool $preferYours, int $level, array &$seen, array &$combos): array
    {
        $e = Game::element($id);
        if ($e === null) {
            return self::unknownNode($id);
        }
        $node = Game::mini($e) + [
            'rarity' => Game::rarity((int) $e['depth'], (int) $e['uses']),
            'base' => (int) $e['is_base'] === 1,
            'source' => null,
            'a' => null,
            'b' => null,
            'cycle' => false,
        ];
        if ($node['base'] || $level >= self::MAX_DEPTH) {
            return $node;
        }
        if (in_array($id, $path, true)) {
            $node['cycle'] = true;
            return $node;
        }
        $pair = self::pairFor($id, $player, $preferYours);
        if ($pair === null) {
            return $node;
        }
        [$a, $b, $source] = $pair;
        if ($a <= 0 || $b <= 0 || $a === $id || $b === $id) {
            return $node;
        }
        $node['source'] = $source;
        $combos[$id] = true;
        $next = array_merge($path, [$id]);
        $node['a'] = self::walk($a, $next, $player, $preferYours, $level + 1, $seen, $combos);
        $node['b'] = self::walk($b, $next, $player, $preferYours, $level + 1, $seen, $combos);
        return $node;
    }

    /**
     * @param array<string,mixed>|null $player
     * @return array{0:int,1:int,2:string}|null
     */
    private static function pairFor(int $id, ?array $player, bool $preferYours): ?array
    {
        if ($preferYours && $player) {
            try {
                $row = Db::one('SELECT via_a, via_b FROM cc_collection WHERE player_id = ? AND element_id = ?', [$player['id'], $id]);
                if ($row && !empty($row['via_a']) && !empty($row['via_b'])) {
                    return [(int) $row['via_a'], (int) $row['via_b'], 'yours'];
                }
            } catch (\Throwable $e) {
                // Collection row may be missing.
            }
        }
        $e = Game::element($id);
        if ($e && !empty($e['first_a']) && !empty($e['first_b'])) {
            return [(int) $e['first_a'], (int) $e['first_b'], 'first'];
        }
        try {
            $r = Db::one('SELECT a_id, b_id FROM cc_recipes WHERE result_id = ? ORDER BY created_at ASC, id ASC LIMIT 1', [$id]);
            if ($r) {
                return [(int) $r['a_id'], (int) $r['b_id'], 'known'];
            }
        } catch (\Throwable $e) {
            // No recipe table row.
        }
        return null;
    }

    /** @param array<string,mixed> $node */
    private static function depthOf(array $node): int
    {
        if (empty($node['a']) && empty($node['b'])) {
            return 0;
        }
        $da = !empty($node['a']) ? self::depthOf($node['a']) : 0;
        $db = !empty($node['b']) ? self::depthOf($node['b']) : 0;
        return 1 + max($da, $db);
    }

    /** @return array<string,mixed> */
    private static function unknownNode(int $id): array
    {
        return [
            'id' => $id,
            'name' => '???',
            'emoji' => '❓',
            'rarity' => 'Common',
            'base' => false,
            'source' => null,
            'a' => null,
            'b' => null,
            'cycle' => false,
        ];
    }

    private static function upsertBest(string $playerId, int $elementId, string $name, int $size, int $depth): void
    {
        $now = Db::now();
        $row = Db::one('SELECT tree_size, depth FROM cc_tree_best WHERE player_id = ?', [$playerId]);
        if ($row) {
            $better = $size > (int) $row['tree_size']
                || ($size === (int) $row['tree_size'] && $depth > (int) $row['depth']);
            if (!$better) {
                return;
            }
            Db::run(
                'UPDATE cc_tree_best SET element_id = ?, element_name = ?, tree_size = ?, depth = ?, updated_at = ? WHERE player_id = ?',
                [$elementId, mb_substr($name, 0, 160), $size, $depth, $now, $playerId]
            );
            return;
        }
        try {
            Game::insertPublic('cc_tree_best', [
                'player_id' => $playerId,
                'element_id' => $elementId,
                'element_name' => mb_substr($name, 0, 160),
                'tree_size' => $size,
                'depth' => $depth,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[combocraft tree upsert] ' . $e->getMessage());
        }
    }

    /** Compute missing scores for existing first discoveries, a few at a time. */
    private static function backfill(int $limit): void
    {
        try {
            $have = [];
            foreach (Db::all('SELECT player_id FROM cc_tree_best') as $r) {
                $have[(string) $r['player_id']] = true;
            }
            $rows = Db::all(
                'SELECT e.id, e.name, e.discovered_by, p.nickname
                 FROM cc_elements e
                 JOIN cc_players p ON p.id = e.discovered_by
                 WHERE e.is_base = 0 AND e.discovered_by IS NOT NULL
                 ORDER BY e.depth DESC, e.discovered_at ASC
                 LIMIT 400'
            );
            $n = 0;
            // Track best-so-far per player during this pass, including existing rows.
            $best = [];
            foreach ($have as $pid => $_) {
                $row = Db::one('SELECT element_id, tree_size, depth FROM cc_tree_best WHERE player_id = ?', [$pid]);
                if ($row) {
                    $best[$pid] = $row;
                }
            }
            foreach ($rows as $r) {
                $pid = (string) $r['discovered_by'];
                if (self::isHiddenName($r['nickname'] ?? null)) {
                    continue;
                }
                $stats = self::stats((int) $r['id']);
                if ($stats['size'] < 1) {
                    continue;
                }
                $cur = $best[$pid] ?? null;
                $better = $cur === null
                    || $stats['size'] > (int) $cur['tree_size']
                    || ($stats['size'] === (int) $cur['tree_size'] && $stats['depth'] > (int) ($cur['depth'] ?? 0));
                if ($better) {
                    self::upsertBest($pid, (int) $r['id'], (string) $r['name'], $stats['size'], $stats['depth']);
                    $best[$pid] = ['element_id' => (int) $r['id'], 'tree_size' => $stats['size'], 'depth' => $stats['depth']];
                    $n++;
                    if ($n >= $limit) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[combocraft tree backfill] ' . $e->getMessage());
        }
    }
}
