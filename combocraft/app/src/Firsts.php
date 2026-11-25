<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Global First Discovery: at most one account in the world per element.
 * Additive cc_first_discoveries table (unique element_id) claimed with
 * INSERT IGNORE so two races cannot both win. Grandfathered rows are the
 * earliest existing cc_elements.discovered_by and are never coin-granted.
 */
final class Firsts
{
    public static function boot(): void
    {
        static $ok = false;
        if ($ok) {
            return;
        }
        try {
            $int = Db::isSqlite() ? 'INTEGER' : 'INT';
            Db::run(
                "CREATE TABLE IF NOT EXISTS cc_first_discoveries (
                    element_id {$int} NOT NULL PRIMARY KEY,
                    player_id VARCHAR(24) NOT NULL,
                    discovered_at DATETIME NOT NULL,
                    coin_granted TINYINT NOT NULL DEFAULT 0,
                    legacy TINYINT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL
                )"
            );
            self::grandfather();
        } catch (\Throwable $e) {
            error_log('[combocraft firsts boot] ' . $e->getMessage());
        }
        $ok = true;
    }

    /**
     * Try to become the one global first discoverer of this element.
     * Returns true only when this call wins the insert (a brand-new first).
     * @param array<string,mixed> $player
     */
    public static function claim(int $elementId, array $player): bool
    {
        self::boot();
        if ($elementId <= 0) {
            return false;
        }
        $e = Game::element($elementId);
        if ($e === null || (int) $e['is_base'] === 1) {
            return false;
        }
        $now = Db::now();
        $verb = Db::isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        try {
            $n = Db::run(
                $verb . ' INTO cc_first_discoveries (element_id, player_id, discovered_at, coin_granted, legacy, created_at) VALUES (?,?,?,0,0,?)',
                [$elementId, $player['id'], $now, $now]
            );
            return $n > 0;
        } catch (\Throwable $e) {
            error_log('[combocraft firsts claim] ' . $e->getMessage());
            return false;
        }
    }

    public static function ownerId(int $elementId): ?string
    {
        self::boot();
        $row = Db::one('SELECT player_id FROM cc_first_discoveries WHERE element_id = ?', [$elementId]);
        return $row ? (string) $row['player_id'] : null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    public static function owners(array $ids): array
    {
        self::boot();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        try {
            foreach (Db::all("SELECT element_id, player_id FROM cc_first_discoveries WHERE element_id IN ({$place})", $ids) as $r) {
                $out[(int) $r['element_id']] = (string) $r['player_id'];
            }
        } catch (\Throwable $e) {
            // Table may still be coming up.
        }
        return $out;
    }

    public static function isLegacy(int $elementId): bool
    {
        self::boot();
        $row = Db::one('SELECT legacy FROM cc_first_discoveries WHERE element_id = ?', [$elementId]);
        return $row !== null && (int) $row['legacy'] === 1;
    }

    public static function alreadyPaid(int $elementId): bool
    {
        self::boot();
        $row = Db::one('SELECT coin_granted FROM cc_first_discoveries WHERE element_id = ?', [$elementId]);
        return $row !== null && (int) $row['coin_granted'] === 1;
    }

    public static function markPaid(int $elementId): void
    {
        self::boot();
        Db::run('UPDATE cc_first_discoveries SET coin_granted = 1 WHERE element_id = ? AND coin_granted = 0 AND legacy = 0', [$elementId]);
    }

    /**
     * New (non-legacy) firsts this player won that have not been coin-granted yet.
     * @param array<string,mixed> $player
     * @return list<int>
     */
    public static function unpaidFor(array $player): array
    {
        self::boot();
        $ids = [];
        try {
            foreach (Db::all(
                'SELECT element_id FROM cc_first_discoveries WHERE player_id = ? AND legacy = 0 AND coin_granted = 0',
                [$player['id']]
            ) as $r) {
                $ids[] = (int) $r['element_id'];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $ids;
    }

    /** Keep the earliest existing discovered_by as the official first. No coins. */
    private static function grandfather(): void
    {
        $verb = Db::isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        try {
            Db::run(
                $verb . ' INTO cc_first_discoveries (element_id, player_id, discovered_at, coin_granted, legacy, created_at)
                 SELECT id, discovered_by, COALESCE(discovered_at, created_at), 0, 1, COALESCE(discovered_at, created_at)
                 FROM cc_elements
                 WHERE is_base = 0 AND discovered_by IS NOT NULL AND discovered_by <> \'\''
            );
        } catch (\Throwable $e) {
            error_log('[combocraft firsts grandfather] ' . $e->getMessage());
        }
    }
}
