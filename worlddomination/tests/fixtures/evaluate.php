<?php

declare(strict_types=1);

// CLI-only test helper: replay a client match log with the server engine.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../app/src/bootstrap.php';

use WorldDomination\Geo;
use WorldDomination\MatchEngine;
use WorldDomination\Rules;
use WorldDomination\Capture;

$in = json_decode((string) stream_get_contents(STDIN), true);
$country = Geo::countryById((string) ($in['country'] ?? ''));
if ($country === null) {
    echo json_encode(['ok' => false, 'reason' => 'unknown country']);
    exit(0);
}
$grid = Geo::rasterizeArena($country['arena'], Rules::GRID);
$out = ['playableBefore' => Capture::playableCount($grid)];
if (isset($in['matchId'])) {
    $out['spawn'] = Geo::pickSpawn($grid, (string) $in['matchId']);
}
if (isset($in['heartbeats'])) {
    $spawn = $in['spawn'] ?? $out['spawn'];
    $r = MatchEngine::evaluate($grid, $spawn, $in['heartbeats'], $in['captures'] ?? [], (int) ($in['elapsed'] ?? 60000));
    unset($r['grid']);
    $out += $r;
}
echo json_encode($out);
