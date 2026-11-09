<?php

declare(strict_types=1);

namespace WorldDomination;

/** Country rasterization matching assets/js/geo.js. */
final class Geo
{
    /** @param list<array{0:float,1:float}> $ring */
    public static function pointInRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        if ($n < 3) {
            return false;
        }
        for ($i = 0, $j = $n - 1; $i < $n; $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];
            $denom = ($yj - $yi) ?: 1e-12;
            if (($yi > $y) !== ($yj > $y) && $x < (($xj - $xi) * ($y - $yi)) / $denom + $xi) {
                $inside = !$inside;
            }
            $j = $i;
        }
        return $inside;
    }

    /** @param array{outer:list<array{0:float,1:float}>,holes?:list<list<array{0:float,1:float}>>} $arena */
    public static function pointInArena(float $lng, float $lat, array $arena): bool
    {
        if (!isset($arena['outer']) || !self::pointInRing($lng, $lat, $arena['outer'])) {
            return false;
        }
        foreach ($arena['holes'] ?? [] as $hole) {
            if (is_array($hole) && self::pointInRing($lng, $lat, $hole)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{outer:list<array{0:float,1:float}>,holes?:list<list<array{0:float,1:float}>>} $arena
     * @return list<list<int>>
     */
    public static function rasterizeArena(array $arena, int $size = Rules::GRID): array
    {
        $outer = $arena['outer'] ?? [];
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;
        foreach ($outer as $pt) {
            $x = (float) $pt[0];
            $y = (float) $pt[1];
            if ($x < $minX) {
                $minX = $x;
            }
            if ($y < $minY) {
                $minY = $y;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($y > $maxY) {
                $maxY = $y;
            }
        }
        $pad = 0.05 * max($maxX - $minX, $maxY - $minY, 1.0);
        $minX -= $pad;
        $minY -= $pad;
        $maxX += $pad;
        $maxY += $pad;
        $bw = max($maxX - $minX, 1e-6);
        $bh = max($maxY - $minY, 1e-6);
        $inner = $size - 4;
        $scale = $inner / max($bw, $bh);
        $ox = 2 + ($inner - $bw * $scale) / 2;
        $oy = 2 + ($inner - $bh * $scale) / 2;
        $grid = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $lng = $minX + ($x + 0.5 - $ox) / $scale;
                $lat = $maxY - ($y + 0.5 - $oy) / $scale;
                $row[] = self::pointInArena($lng, $lat, $arena) ? Capture::EMPTY : Capture::OOB;
            }
            $grid[] = $row;
        }
        return $grid;
    }

    /** @return array<string,mixed>|null */
    public static function countryById(string $id): ?array
    {
        $id = strtoupper(trim($id));
        $path = dirname(__DIR__, 2) . '/assets/data/countries.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['countries']) || !is_array($data['countries'])) {
            return null;
        }
        foreach ($data['countries'] as $c) {
            if (is_array($c) && strtoupper((string) ($c['id'] ?? '')) === $id) {
                return $c;
            }
        }
        return null;
    }

    /**
     * @param list<list<int>> $grid
     * @return array{x:int,y:int}
     */
    public static function pickSpawn(array $grid, string $seed): array
    {
        $rows = count($grid);
        $cols = $rows ? count($grid[0]) : 0;
        // Prefer spawns with open land all around (a 17x17 box) so the
        // first-capture tutorial loop always fits; fall back to any land.
        $room = 8;
        $sum = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));
        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $sum[$y + 1][$x + 1] = ($grid[$y][$x] === Capture::OOB ? 0 : 1)
                    + $sum[$y][$x + 1] + $sum[$y + 1][$x] - $sum[$y][$x];
            }
        }
        $roomy = [];
        $candidates = [];
        for ($y = 0; $y < $rows; $y += 2) {
            for ($x = 0; $x < $cols; $x += 2) {
                if ($grid[$y][$x] !== Capture::EMPTY) {
                    continue;
                }
                $candidates[] = ['x' => $x, 'y' => $y];
                $x0 = $x - $room;
                $y0 = $y - $room;
                $x1 = $x + $room + 1;
                $y1 = $y + $room + 1;
                if ($x0 >= 0 && $y0 >= 0 && $x1 <= $cols && $y1 <= $rows) {
                    $open = $sum[$y1][$x1] - $sum[$y0][$x1] - $sum[$y1][$x0] + $sum[$y0][$x0];
                    if ($open === ($room * 2 + 1) ** 2) {
                        $roomy[] = ['x' => $x, 'y' => $y];
                    }
                }
            }
        }
        if ($roomy !== []) {
            $candidates = $roomy;
        }
        if ($candidates === []) {
            for ($y = 0; $y < $rows; $y++) {
                for ($x = 0; $x < $cols; $x++) {
                    if ($grid[$y][$x] !== Capture::OOB) {
                        return ['x' => $x, 'y' => $y];
                    }
                }
            }
            return ['x' => (int) floor($cols / 2), 'y' => (int) floor($rows / 2)];
        }
        $idx = hexdec(substr(hash('sha256', $seed), 0, 8)) % count($candidates);
        return $candidates[$idx];
    }

    /** @param list<list<int>> $grid */
    public static function paintDisk(array &$grid, int $cx, int $cy, int $r, int $id): void
    {
        $rows = count($grid);
        $cols = $rows ? count($grid[0]) : 0;
        for ($y = $cy - $r; $y <= $cy + $r; $y++) {
            for ($x = $cx - $r; $x <= $cx + $r; $x++) {
                if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows) {
                    continue;
                }
                if ($grid[$y][$x] === Capture::OOB) {
                    continue;
                }
                if (($x - $cx) * ($x - $cx) + ($y - $cy) * ($y - $cy) <= $r * $r) {
                    $grid[$y][$x] = $id;
                }
            }
        }
    }

    /** @param list<list<int>> $grid */
    public static function clearOwner(array &$grid, int $id): void
    {
        foreach ($grid as $y => $row) {
            foreach ($row as $x => $cell) {
                if ($cell === $id) {
                    $grid[$y][$x] = Capture::EMPTY;
                }
            }
        }
    }
}
