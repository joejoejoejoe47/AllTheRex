<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WorldDomination\Auth;
use WorldDomination\Db;
use WorldDomination\HttpError;
use WorldDomination\Leaderboard;

test_db();

check(Leaderboard::podium() === [], 'podium starts empty — no invented players');
check(Leaderboard::countryExists('US') === true, 'bundled US outline is known');
check(Leaderboard::countryExists('ZZ') === false, 'unknown country id rejected');

$user = Auth::signUp('qa.worlddom@example.com', 'WdTest-Pass9!', 'QA Explorer');

$nope = null;
try {
    Leaderboard::startMatch($user['id'], 'ZZ');
} catch (HttpError $e) {
    $nope = $e;
}
check($nope instanceof HttpError, 'cannot start a match in an unknown country');

$match = Leaderboard::startMatch($user['id'], 'US');
check(isset($match['token'], $match['matchId']), 'startMatch issues a server token');
check($match['winPct'] === 50.0, 'win threshold is 50 percent');

// Stored token must be hashed.
$stored = Db::value('SELECT token_hash FROM wd_matches WHERE id = ?', [$match['matchId']]);
check($stored === hash('sha256', $match['token']), 'match token stored hashed');

$tooFast = null;
try {
    Leaderboard::finishMatch($user['id'], [
        'token' => $match['token'],
        'country' => 'US',
        'playerPct' => 80,
        'outcome' => 'win',
        'durationMs' => 1000,
        'bots' => [],
    ]);
} catch (HttpError $e) {
    $tooFast = $e;
}
check($tooFast instanceof HttpError && $tooFast->status === 422, 'too-fast claimed win is rejected');

// Pretend the match started earlier so a legitimate finish is accepted.
Db::run('UPDATE wd_matches SET started_at = ? WHERE id = ?', [
    gmdate('Y-m-d H:i:s', time() - 40),
    $match['matchId'],
]);

$result = Leaderboard::finishMatch($user['id'], [
    'token' => $match['token'],
    'country' => 'US',
    'playerPct' => 62.5,
    'outcome' => 'win',
    'durationMs' => 40000,
    'bots' => [
        ['slug' => 'quarry', 'name' => 'Bot Quarry', 'pct' => 18.0, 'outcome' => 'lose'],
        ['slug' => 'marina', 'name' => 'Marina', 'pct' => 11.0, 'outcome' => 'lose'],
        ['slug' => 'human-impostor', 'name' => 'Totally Human', 'pct' => 9.0, 'outcome' => 'lose'],
    ],
]);

check($result['player']['score'] === (int) round(62.5 * 10) + 250, 'player points include the win bonus');
check($result['player']['outcome'] === 'win', 'win recorded');

$replay = null;
try {
    Leaderboard::finishMatch($user['id'], [
        'token' => $match['token'],
        'country' => 'US',
        'playerPct' => 90,
        'outcome' => 'win',
        'durationMs' => 40000,
        'bots' => [],
    ]);
} catch (HttpError $e) {
    $replay = $e;
}
check($replay instanceof HttpError && $replay->status === 409, 'double-finish is rejected');

$podium = Leaderboard::podium();
check(count($podium) === 3, 'podium has player plus two scored bots after one real match');
check($podium[0]['displayName'] === 'QA Explorer', 'human leader is first after winning');
check($podium[0]['isBot'] === false, 'human is not labeled a bot');

$botNames = array_map(static fn ($r) => $r['displayName'], array_filter($podium, static fn ($r) => $r['isBot']));
check(in_array('Bot Quarry', $botNames, true), 'bot scores persist under their bot name');
check(in_array('Bot Marina', $botNames, true), 'bot names without a prefix still become Bot …');
$impostor = Db::one("SELECT display_name, is_bot FROM wd_scores WHERE subject_key = ?", ['bot:human-impostor']);
check($impostor !== null && (int) $impostor['is_bot'] === 1 && str_starts_with((string) $impostor['display_name'], 'Bot '), 'client-supplied human-looking bot names are forced to Bot …');

$san = Leaderboard::sanitizeBots([
    ['slug' => 'x', 'name' => 'PlayerOne', 'pct' => 200, 'outcome' => 'win'],
]);
check($san[0]['name'] === 'Bot PlayerOne', 'sanitize prefixes Bot');
check($san[0]['pct'] === 100.0, 'sanitize caps percent at 100');

finish();
