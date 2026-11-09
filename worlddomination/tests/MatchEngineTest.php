<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WorldDomination\Capture;
use WorldDomination\Geo;
use WorldDomination\MatchEngine;
use WorldDomination\Rules;

function emptyGrid(int $w, int $h): array
{
    $grid = [];
    for ($y = 0; $y < $h; $y++) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $row[] = ($x === 0 || $y === 0 || $x === $w - 1 || $y === $h - 1) ? Capture::OOB : Capture::EMPTY;
        }
        $grid[] = $row;
    }
    return $grid;
}

$spawn = ['x' => 6, 'y' => 10];
$base = emptyGrid(24, 20);
Geo::paintDisk($base, $spawn['x'], $spawn['y'], Rules::START_RADIUS, Rules::PLAYER_ID);

// Walk north out of the starter disk and around a rectangle, then back.
// Start and end must touch the radius-3 disk around (6,10); (6,6) and (7,7) do.
$trail = [
    [6, 6], [5, 6], [5, 5], [5, 4], [6, 4], [7, 4], [7, 5], [7, 6], [7, 7],
];
$heartbeats = [
    ['t' => 0, 'x' => 6.5, 'y' => 10.5],
    ['t' => 400, 'x' => 6.5, 'y' => 6.5],
    ['t' => 700, 'x' => 5.5, 'y' => 6.5],
    ['t' => 1100, 'x' => 5.5, 'y' => 4.5],
    ['t' => 1500, 'x' => 7.5, 'y' => 4.5],
    ['t' => 2000, 'x' => 7.5, 'y' => 7.5],
];
$ok = MatchEngine::evaluate($base, $spawn, $heartbeats, [['t' => 2000, 'trail' => $trail]], 4000);
check($ok['ok'] === true, 'walked enclosure is accepted' . (isset($ok['reason']) ? ' (' . $ok['reason'] . ')' : ''));
check($ok['captured'] > 0, 'server flood-fill captured cells');
check(($ok['grid'][5][6] ?? 0) === Rules::PLAYER_ID, 'enclosed interior is owned on the server');

$unwalked = MatchEngine::evaluate(emptyGrid(24, 20), $spawn, [
    ['t' => 0, 'x' => 6.5, 'y' => 10.5],
    ['t' => 400, 'x' => 7.5, 'y' => 10.5],
], [['t' => 400, 'trail' => $trail]], 4000);
check($unwalked['ok'] === false && str_contains((string) $unwalked['reason'], 'not walked'), 'a capture that was not walked is rejected');

$teleport = MatchEngine::evaluate(emptyGrid(24, 20), $spawn, [
    ['t' => 0, 'x' => 6.5, 'y' => 10.5],
    ['t' => 50, 'x' => 20.5, 'y' => 18.5],
], [['t' => 50, 'trail' => $trail]], 4000);
check($teleport['ok'] === false, 'an impossible jump used as a capture path is rejected');

$empty = MatchEngine::evaluate(emptyGrid(24, 20), $spawn, [], [], 1000);
check($empty['ok'] === true, 'a retreat with no log still evaluates the starter land');
check($empty['pct'] > 0 && $empty['pct'] < 10, 'starter land alone is a small percent, not a fake win');

// A flagged respawn costs all land; it can never add territory.
$afterCapture = MatchEngine::evaluate($base, $spawn, $heartbeats, [['t' => 2000, 'trail' => $trail]], 4000);
$withRespawn = MatchEngine::evaluate($base, $spawn, array_merge($heartbeats, [
    ['t' => 3000, 'x' => 18.5, 'y' => 14.5, 'respawn' => true],
]), [['t' => 2000, 'trail' => $trail]], 4000);
check($withRespawn['ok'] === true, 'respawn heartbeat is accepted');
check($withRespawn['owned'] < $afterCapture['owned'], 'respawn clears captured land back to a starter disk');
check($withRespawn['grid'][5][6] !== Rules::PLAYER_ID, 'old enclosure is no longer owned after a knock-out');

// Large enclosure on a real country: land jumps and every inside cell is owned.
$us = Geo::countryById('US');
$usGrid = Geo::rasterizeArena($us['arena'], Rules::GRID);
$usSpawn = Geo::pickSpawn($usGrid, 'big-loop');
$sx = $usSpawn['x'];
$sy = $usSpawn['y'];
$top = $sy - 8;
$right = $sx + 8;
$path = [[$sx, $sy], [$sx, $top], [$right, $top], [$right, $sy], [$sx, $sy]];
$hb = [];
$bigTrail = [];
$tms = 0.0;
$prev = null;
foreach ($path as [$px, $py]) {
    if ($prev !== null) {
        [$qx, $qy] = $prev;
        $steps = max(abs($px - $qx), abs($py - $qy));
        for ($i = 1; $i <= $steps; $i++) {
            $cx = $qx + (int) round(($px - $qx) * $i / $steps);
            $cy = $qy + (int) round(($py - $qy) * $i / $steps);
            $tms += 1000 / Rules::SPEED;
            $hb[] = ['t' => $tms, 'x' => $cx + 0.5, 'y' => $cy + 0.5];
            if (($cx - $sx) ** 2 + ($cy - $sy) ** 2 > Rules::START_RADIUS ** 2) {
                $bigTrail[] = [$cx, $cy];
            }
        }
    }
    $prev = [$px, $py];
}
$start = MatchEngine::evaluate($usGrid, $usSpawn, [], [], 1000);
$big = MatchEngine::evaluate($usGrid, $usSpawn, $hb, [['t' => $tms, 'trail' => $bigTrail]], (int) $tms + 1000);
check($big['ok'] === true, 'server accepts a walked loop on the US map' . (isset($big['reason']) ? ' (' . $big['reason'] . ')' : ''));
check($big['pct'] > $start['pct'] * 2.5, sprintf('US land jumps %.2f%% -> %.2f%% after closing the loop', $start['pct'], $big['pct']));
$allInside = true;
for ($y = $top; $y <= $sy; $y++) {
    for ($x = $sx; $x <= $right; $x++) {
        if ($big['grid'][$y][$x] !== Rules::PLAYER_ID) {
            $allInside = false;
        }
    }
}
check($allInside, 'every cell enclosed by the loop is owned on the server');


// Slow / paused play (hidden tab, dialogs, slow testers) must still record.
$paused = [
    ['t' => 0, 'x' => 6.5, 'y' => 10.5],
    ['t' => -11.0, 'x' => 6.5, 'y' => 10.5], // frame stamped just before the match clock
    ['t' => 20400, 'x' => 6.5, 'y' => 6.5],  // 20 s idle, then walked
    ['t' => 20700, 'x' => 5.5, 'y' => 6.5],
    ['t' => 21100, 'x' => 5.5, 'y' => 4.5],
    ['t' => 21500, 'x' => 7.5, 'y' => 4.5],
    ['t' => 52000, 'x' => 7.5, 'y' => 4.5],  // 30 s more paused mid-trail (blur)
    ['t' => 52500, 'x' => 7.5, 'y' => 7.5],
];
$slow = MatchEngine::evaluate($base, $spawn, $paused, [['t' => 52500, 'trail' => $trail]], 60000);
check($slow['ok'] === true, 'slow play with long pauses and an early frame stamp is accepted' . (isset($slow['reason']) ? ' (' . $slow['reason'] . ')' : ''));
check(($slow['captured'] ?? 0) === ($ok['captured'] ?? -1), 'paused play captures the same land as unpaused play');

$late = MatchEngine::evaluate($base, $spawn, [
    ['t' => 0, 'x' => 6.5, 'y' => 10.5],
    ['t' => Rules::MATCH_MS + 60000, 'x' => 6.5, 'y' => 10.5],
], [], Rules::MATCH_MS + 70000);
check($late['ok'] === true, 'heartbeats after the final whistle are ignored, not fatal');

$future = MatchEngine::evaluate($base, $spawn, [
    ['t' => 0, 'x' => 6.5, 'y' => 10.5],
    ['t' => 9000, 'x' => 6.5, 'y' => 10.5],
], [], 3000);
check($future['ok'] === false && str_contains((string) $future['reason'], 'ahead'), 'a heartbeat ahead of the server clock is still rejected');

$nan = MatchEngine::evaluate($base, $spawn, [['t' => 'x', 'x' => 'nope', 'y' => 1]], [], 3000);
check($nan['ok'] === false, 'garbage heartbeats are still rejected');

finish();
