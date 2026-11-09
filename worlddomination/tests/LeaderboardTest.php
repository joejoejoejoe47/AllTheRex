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
check(isset($match['token'], $match['matchId'], $match['spawn']['x'], $match['spawn']['y']), 'startMatch issues a token and server spawn');
check($match['winPct'] === 50.0, 'win threshold is 50 percent');

$stored = Db::value('SELECT token_hash FROM wd_matches WHERE id = ?', [$match['matchId']]);
check($stored === hash('sha256', $match['token']), 'match token stored hashed');

Db::run('UPDATE wd_matches SET started_at = ? WHERE id = ?', [
    gmdate('Y-m-d H:i:s', time() - 20),
    $match['matchId'],
]);

$result = Leaderboard::finishMatch($user['id'], [
    'token' => $match['token'],
    'playerPct' => 99.9,
    'outcome' => 'win',
    'bots' => [
        ['slug' => 'quarry', 'name' => 'Bot Quarry', 'pct' => 88, 'outcome' => 'win'],
        ['slug' => 'human-impostor', 'name' => 'Totally Human', 'pct' => 70, 'outcome' => 'win'],
    ],
    'heartbeats' => [],
    'captures' => [],
    'intent' => 'finish',
]);

check($result['player']['pct'] < 5, 'invented client percent is ignored; starter land is scored instead');
check($result['player']['outcome'] !== 'win', 'a client cannot award itself a win');
check($result['player']['score'] === (int) round($result['player']['pct'] * 10), 'points come from the server percent');

$bots = Db::all('SELECT * FROM wd_scores WHERE is_bot = 1');
check($bots === [], 'client-supplied bot scores are not persisted');

$replay = null;
try {
    Leaderboard::finishMatch($user['id'], [
        'token' => $match['token'],
        'heartbeats' => [],
        'captures' => [],
        'intent' => 'finish',
    ]);
} catch (HttpError $e) {
    $replay = $e;
}
check($replay instanceof HttpError && $replay->status === 409, 'double-finish is rejected');

$other = Auth::signUp('other@example.com', 'correct-horse', 'Other');
$match2 = Leaderboard::startMatch($user['id'], 'US');
$stolen = null;
try {
    Leaderboard::finishMatch($other['id'], [
        'token' => $match2['token'],
        'heartbeats' => [],
        'captures' => [],
        'intent' => 'quit',
    ]);
} catch (HttpError $e) {
    $stolen = $e;
}
check($stolen instanceof HttpError && $stolen->status === 404, 'a player cannot finish someone else\'s match');

$podium = Leaderboard::podium();
check(count($podium) === 1, 'podium contains only the real player after a verified match');
check($podium[0]['displayName'] === 'QA Explorer' && $podium[0]['isBot'] === false, 'leaderboard name is the authenticated player');

// Practice bots can never reach the podium, even if a bot row exists.
Db::run(
    'INSERT INTO wd_scores (id, subject_key, display_name, is_bot, points, wins, matches, best_pct, updated_at) VALUES (?, ?, ?, 1, 99999, 50, 50, 99, ?)',
    [Auth::randomId(), 'bot:quarry', 'Bot Quarry', Db::now()]
);
$podium2 = Leaderboard::podium();
check(count(array_filter($podium2, fn ($p) => $p['isBot'])) === 0, 'podium never lists practice bots');
check($podium2[0]['displayName'] === 'QA Explorer', 'champion is the real top explorer, not a bot');

// claimedPct can only lower the server score (bots took land back), never raise it.
$m3 = Leaderboard::startMatch($user['id'], 'US');
$up = Leaderboard::finishMatch($user['id'], ['token' => $m3['token'], 'heartbeats' => [], 'captures' => [], 'intent' => 'quit', 'claimedPct' => 80.0]);
check($up['player']['pct'] < 5, 'a higher claimed percent is ignored');
$m4 = Leaderboard::startMatch($user['id'], 'US');
$down = Leaderboard::finishMatch($user['id'], ['token' => $m4['token'], 'heartbeats' => [], 'captures' => [], 'intent' => 'quit', 'claimedPct' => 0.1]);
check(abs($down['player']['pct'] - 0.1) < 1e-9, 'a lower claimed percent (land lost to bots) is honoured');

finish();
