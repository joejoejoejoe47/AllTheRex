<?php

declare(strict_types=1);

namespace WorldDomination;

/**
 * Reconstructs a match from heartbeats + capture trails and scores it with
 * the same flood-fill used by the browser. Client-supplied percentages are
 * never trusted.
 */
final class MatchEngine
{
    /**
     * @param list<list<int>> $grid
     * @param array{x:int,y:int} $spawn
     * @param list<array<string,mixed>> $heartbeats
     * @param list<array<string,mixed>> $captures
     * @return array{ok:bool,reason?:string,pct:float,captured:int,playable:int,owned:int,grid:list<list<int>>}
     */
    public static function evaluate(array $grid, array $spawn, array $heartbeats, array $captures, int $serverElapsedMs): array
    {
        if (count($heartbeats) > Rules::MAX_HEARTBEATS || count($captures) > Rules::MAX_CAPTURES) {
            return self::fail($grid, 'match log too large');
        }

        $owner = Rules::PLAYER_ID;
        Geo::paintDisk($grid, (int) $spawn['x'], (int) $spawn['y'], Rules::START_RADIUS, $owner);
        $playable = Capture::playableCount($grid);

        $normalizedCaps = [];
        foreach ($captures as $cap) {
            if (!is_array($cap)) {
                return self::fail($grid, 'invalid capture');
            }
            $trail = $cap['trail'] ?? null;
            if (!is_array($trail) || $trail === []) {
                return self::fail($grid, 'empty capture trail');
            }
            $clean = [];
            foreach ($trail as $cell) {
                if (!is_array($cell) || !isset($cell[0], $cell[1])) {
                    return self::fail($grid, 'invalid trail cell');
                }
                $clean[] = [(int) $cell[0], (int) $cell[1]];
            }
            if (!self::isFourConnected($clean)) {
                return self::fail($grid, 'capture trail is not connected');
            }
            $normalizedCaps[] = [
                't' => (float) ($cap['t'] ?? 0),
                'trail' => $clean,
            ];
        }
        usort($normalizedCaps, static fn ($a, $b) => $a['t'] <=> $b['t']);

        $prevX = (float) $spawn['x'] + 0.5;
        $prevY = (float) $spawn['y'] + 0.5;
        $prevT = 0.0;
        $visited = [];
        self::markVisited($visited, (int) floor($prevX), (int) floor($prevY));
        $deaths = 0;
        $capIndex = 0;
        $applied = 0;

        foreach ($heartbeats as $hb) {
            if (!is_array($hb)) {
                return self::fail($grid, 'invalid heartbeat');
            }
            $t = (float) ($hb['t'] ?? -1);
            $x = (float) ($hb['x'] ?? NAN);
            $y = (float) ($hb['y'] ?? NAN);
            if (!is_finite($t) || !is_finite($x) || !is_finite($y) || $t < -20) {
                return self::fail($grid, 'invalid heartbeat');
            }
            if ($t > $serverElapsedMs + 2500 || $t > Rules::MATCH_MS + 8000) {
                return self::fail($grid, 'heartbeat ahead of the server clock');
            }
            if ($t + 80 < $prevT) {
                return self::fail($grid, 'heartbeat time went backwards');
            }
            $dt = max(0.001, ($t - $prevT) / 1000.0);
            $dist = hypot($x - $prevX, $y - $prevY);
            $maxDist = Rules::SPEED * Rules::SPEED_SLACK * $dt + 1.25;
            // A respawn (client-flagged) or an impossible jump both count as a
            // knock-out: all owned land is lost and only a fresh starter disk
            // is granted, so neither can be used to gain territory.
            if (!empty($hb['respawn']) || $dist > $maxDist) {
                $deaths++;
                if ($deaths > Rules::MAX_DEATHS) {
                    return self::fail($grid, 'too many discontinuous jumps');
                }
                $cx = (int) floor($x);
                $cy = (int) floor($y);
                if (!self::inPlayable($grid, $cx, $cy)) {
                    return self::fail($grid, 'respawn outside the country');
                }
                Geo::clearOwner($grid, $owner);
                Geo::paintDisk($grid, $cx, $cy, Rules::START_RADIUS, $owner);
                $visited = [];
                self::markVisited($visited, $cx, $cy);
            } else {
                foreach (self::walkCells($prevX, $prevY, $x, $y) as [$cx, $cy]) {
                    if (self::inBounds($grid, $cx, $cy)) {
                        self::markVisited($visited, $cx, $cy);
                    }
                }
            }
            $prevX = $x;
            $prevY = $y;
            $prevT = $t;
            while ($capIndex < count($normalizedCaps) && $normalizedCaps[$capIndex]['t'] <= $t + 50) {
                $appliedOne = self::applyCapture($grid, $owner, $normalizedCaps[$capIndex]['trail'], $visited);
                if (!$appliedOne['ok']) {
                    return self::fail($grid, $appliedOne['reason'] ?? 'invalid capture');
                }
                $applied += $appliedOne['captured'];
                $capIndex++;
            }
        }

        while ($capIndex < count($normalizedCaps)) {
            // Captures after the last heartbeat are only allowed if the trail was walked.
            $appliedOne = self::applyCapture($grid, $owner, $normalizedCaps[$capIndex]['trail'], $visited);
            if (!$appliedOne['ok']) {
                return self::fail($grid, $appliedOne['reason'] ?? 'invalid capture');
            }
            $applied += $appliedOne['captured'];
            $capIndex++;
        }

        $owned = Capture::ownedCount($grid, $owner);
        $pct = $playable > 0 ? ($owned / $playable) * 100 : 0.0;
        return [
            'ok' => true,
            'pct' => $pct,
            'captured' => $applied,
            'playable' => $playable,
            'owned' => $owned,
            'grid' => $grid,
        ];
    }

    /**
     * @param list<list<int>> $grid
     * @param list<array{0:int,1:int}> $trail
     * @param array<string,true> $visited
     * @return array{ok:bool,reason?:string,captured:int}
     */
    private static function applyCapture(array &$grid, int $owner, array $trail, array $visited): array
    {
        foreach ($trail as [$x, $y]) {
            if (!self::walkedNear($visited, $x, $y)) {
                return ['ok' => false, 'reason' => 'capture trail was not walked', 'captured' => 0];
            }
        }
        $result = Capture::attempt($grid, $owner, $trail);
        if (!$result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'] ?? 'invalid loop', 'captured' => 0];
        }
        $grid = $result['grid'];
        return ['ok' => true, 'captured' => $result['captured']];
    }

    /**
     * @param list<array{0:int,1:int}> $trail
     */
    private static function isFourConnected(array $trail): bool
    {
        for ($i = 1; $i < count($trail); $i++) {
            $dx = abs($trail[$i][0] - $trail[$i - 1][0]);
            $dy = abs($trail[$i][1] - $trail[$i - 1][1]);
            if ($dx + $dy !== 1 && !($dx === 0 && $dy === 0)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    private static function walkCells(float $x0, float $y0, float $x1, float $y1): array
    {
        $out = [];
        $steps = max((int) ceil(max(abs($x1 - $x0), abs($y1 - $y0)) * 2), 1);
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $out[] = [(int) floor($x0 + ($x1 - $x0) * $t), (int) floor($y0 + ($y1 - $y0) * $t)];
        }
        return $out;
    }

    /**
     * A trail cell counts as walked if the reconstructed path passed through
     * it or a touching cell. Heartbeats are sampled, so the straight line
     * between samples can clip a corner by one cell.
     *
     * @param array<string,true> $visited
     */
    private static function walkedNear(array $visited, int $x, int $y): bool
    {
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if (isset($visited[($y + $dy) . ',' . ($x + $dx)])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,true> $visited */
    private static function markVisited(array &$visited, int $x, int $y): void
    {
        $visited[$y . ',' . $x] = true;
    }

    /** @param list<list<int>> $grid */
    private static function inBounds(array $grid, int $x, int $y): bool
    {
        return $y >= 0 && $x >= 0 && $y < count($grid) && $x < count($grid[0]);
    }

    /** @param list<list<int>> $grid */
    private static function inPlayable(array $grid, int $x, int $y): bool
    {
        return self::inBounds($grid, $x, $y) && $grid[$y][$x] !== Capture::OOB;
    }

    /**
     * @param list<list<int>> $grid
     * @return array{ok:bool,reason:string,pct:float,captured:int,playable:int,owned:int,grid:list<list<int>>}
     */
    private static function fail(array $grid, string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'pct' => 0.0,
            'captured' => 0,
            'playable' => Capture::playableCount($grid),
            'owned' => 0,
            'grid' => $grid,
        ];
    }
}
