<?php

declare(strict_types=1);

namespace WorldDomination;

/**
 * Grid territory capture: walking a trail back to owned land claims every
 * cell enclosed by that trail plus existing owned land (flood-fill from
 * outside the country). Matches the browser algorithm in assets/js/capture.js.
 */
final class Capture
{
    public const OOB = -2;
    public const EMPTY = 0;

    /**
     * @param list<list<int>> $grid row-major [y][x]
     * @param list<array{0:int,1:int}> $trail list of [x, y]
     * @return array{ok:bool,reason?:string,captured:int,grid:list<list<int>>}
     */
    public static function attempt(array $grid, int $ownerId, array $trail): array
    {
        $rows = count($grid);
        $cols = $rows > 0 ? count($grid[0]) : 0;
        $clone = self::cloneGrid($grid);
        if ($trail === []) {
            return ['ok' => false, 'reason' => 'empty-trail', 'captured' => 0, 'grid' => $clone];
        }
        foreach ($trail as $cell) {
            $x = (int) $cell[0];
            $y = (int) $cell[1];
            if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows || $grid[$y][$x] === self::OOB) {
                return ['ok' => false, 'reason' => 'out-of-bounds-trail', 'captured' => 0, 'grid' => $clone];
            }
        }
        $first = $trail[0];
        $last = $trail[count($trail) - 1];
        if (!self::adjacentToOwner($grid, (int) $first[0], (int) $first[1], $ownerId, $cols, $rows)) {
            return ['ok' => false, 'reason' => 'start-not-connected', 'captured' => 0, 'grid' => $clone];
        }
        if (!self::adjacentToOwner($grid, (int) $last[0], (int) $last[1], $ownerId, $cols, $rows)) {
            return ['ok' => false, 'reason' => 'end-not-connected', 'captured' => 0, 'grid' => $clone];
        }

        $trailSet = [];
        foreach ($trail as $cell) {
            $trailSet[((int) $cell[1]) . ',' . ((int) $cell[0])] = true;
        }

        $visited = [];
        for ($y = 0; $y < $rows; $y++) {
            $visited[$y] = array_fill(0, $cols, false);
        }
        $queue = [];
        $head = 0;
        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                if ($grid[$y][$x] === self::OOB) {
                    $visited[$y][$x] = true;
                    $queue[] = [$x, $y];
                }
            }
        }
        $dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];
        while ($head < count($queue)) {
            [$x, $y] = $queue[$head++];
            foreach ($dirs as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $cols || $ny >= $rows || $visited[$ny][$nx]) {
                    continue;
                }
                $key = $ny . ',' . $nx;
                if ($grid[$ny][$nx] === $ownerId || isset($trailSet[$key])) {
                    continue;
                }
                $visited[$ny][$nx] = true;
                $queue[] = [$nx, $ny];
            }
        }

        $captured = 0;
        $next = self::cloneGrid($grid);
        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                if ($grid[$y][$x] === self::OOB) {
                    continue;
                }
                $isTrail = isset($trailSet[$y . ',' . $x]);
                if ($isTrail || !$visited[$y][$x]) {
                    if ($next[$y][$x] !== $ownerId) {
                        $next[$y][$x] = $ownerId;
                        $captured++;
                    }
                }
            }
        }
        return ['ok' => true, 'captured' => $captured, 'grid' => $next];
    }

    /**
     * @param list<array{0:int,1:int}> $trail
     */
    public static function trailSelfCrosses(array $trail, int $ignoreTail = 3): bool
    {
        $seen = [];
        $n = count($trail);
        for ($i = 0; $i < $n; $i++) {
            $key = ((int) $trail[$i][1]) . ',' . ((int) $trail[$i][0]);
            if (isset($seen[$key])) {
                $first = $seen[$key];
                if ($i - $first > $ignoreTail) {
                    return true;
                }
            } else {
                $seen[$key] = $i;
            }
        }
        return false;
    }

    /**
     * @param list<list<int>> $grid
     */
    public static function playableCount(array $grid): int
    {
        $n = 0;
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                if ($cell !== self::OOB) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /**
     * @param list<list<int>> $grid
     */
    public static function ownedCount(array $grid, int $ownerId): int
    {
        $n = 0;
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                if ($cell === $ownerId) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /**
     * @param list<list<int>> $grid
     */
    private static function adjacentToOwner(array $grid, int $x, int $y, int $ownerId, int $cols, int $rows): bool
    {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($nx >= 0 && $ny >= 0 && $nx < $cols && $ny < $rows && $grid[$ny][$nx] === $ownerId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<list<int>> $grid
     * @return list<list<int>>
     */
    private static function cloneGrid(array $grid): array
    {
        $out = [];
        foreach ($grid as $row) {
            $out[] = $row;
        }
        return $out;
    }
}
