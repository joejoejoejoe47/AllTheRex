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

finish();
