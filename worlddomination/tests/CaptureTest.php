<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WorldDomination\Capture;

function gridFrom(array $lines): array
{
    $map = ['.' => Capture::EMPTY, '#' => Capture::OOB, '1' => 1, '2' => 2];
    $grid = [];
    foreach ($lines as $line) {
        $row = [];
        foreach (str_split($line) as $ch) {
            $row[] = $map[$ch] ?? Capture::EMPTY;
        }
        $grid[] = $row;
    }
    return $grid;
}

function paint(array $grid): string
{
    $map = [Capture::OOB => '#', Capture::EMPTY => '.', 1 => '1', 2 => '2'];
    $out = [];
    foreach ($grid as $row) {
        $line = '';
        foreach ($row as $c) {
            $line .= $map[$c] ?? '?';
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

// 10x8 country with a 3x3 starter in the lower-left interior.
// # = out of country, . = empty, 1 = player
$base = gridFrom([
    '##########',
    '#........#',
    '#........#',
    '#........#',
    '#........#',
    '#.111....#',
    '#.111....#',
    '##########',
]);

// Walk out to the north-east and back around a rectangle, reconnecting to owned land.
$trail = [
    [2, 4], [2, 3], [2, 2], [3, 2], [4, 2], [5, 2], [5, 3], [5, 4], [4, 4], [3, 4],
];
// (2,4) is adjacent to owned (2,5); (3,4) is adjacent to owned (3,5).
$r = Capture::attempt($base, 1, $trail);
check($r['ok'] === true, 'valid loop captures');
check($r['captured'] > 0, 'captured some cells');
check($r['grid'][3][3] === 1, 'interior cell (3,3) claimed');
check($r['grid'][2][4] === 1, 'interior cell (4,2) claimed');
check($r['grid'][3][2] === 1, 'trail cell claimed');
check($r['grid'][1][1] === Capture::EMPTY, 'outside-the-loop land stays empty');

$enemy = $base;
$enemy[3][3] = 2;
$enemy[3][4] = 2;
$r2 = Capture::attempt($enemy, 1, $trail);
check($r2['ok'] === true, 'loop enclosing enemy is valid');
check($r2['grid'][3][3] === 1, 'enclosed enemy cell is captured');
check($r2['grid'][5][2] === 1, 'original territory stays owned');

$empty = Capture::attempt($base, 1, []);
check($empty['ok'] === false && $empty['reason'] === 'empty-trail', 'empty trail is invalid');

$oob = Capture::attempt($base, 1, [[0, 0], [1, 1]]);
check($oob['ok'] === false && $oob['reason'] === 'out-of-bounds-trail', 'trail on oob is invalid');

$unconnected = Capture::attempt($base, 1, [[6, 2], [6, 3], [5, 3]]);
check($unconnected['ok'] === false && $unconnected['reason'] === 'start-not-connected', 'trail that does not start at owned land is invalid');

// Exit one step and immediately return — no interior, only the path.
$nibble = Capture::attempt($base, 1, [[2, 4]]);
check($nibble['ok'] === true, 'out-and-back is a valid (tiny) capture');
check($nibble['captured'] === 1, 'out-and-back claims only the trail cell');
check($nibble['grid'][4][2] === 1, 'the single trail cell became owned');
check($nibble['grid'][2][4] === Capture::EMPTY, 'nothing else was filled');

$cross = [[1, 1], [2, 1], [3, 1], [3, 2], [2, 2], [2, 1], [2, 0]];
check(Capture::trailSelfCrosses($cross, 3) === true, 'self-crossing trail detected');
$nocross = [[1, 1], [2, 1], [3, 1], [4, 1]];
check(Capture::trailSelfCrosses($nocross, 3) === false, 'open trail is not a self-cross');

// Against the country border: trail along the interior edge encloses a pocket.
$borderTrail = [
    [2, 4], [1, 4], [1, 3], [1, 2], [1, 1], [2, 1], [3, 1], [3, 2], [3, 3], [3, 4],
];
$rb = Capture::attempt($base, 1, $borderTrail);
check($rb['ok'] === true, 'border-pinned loop is valid');
check($rb['grid'][2][2] === 1, 'pocket against the country border is claimed');

echo paint($r['grid']) . "\n";
finish();
