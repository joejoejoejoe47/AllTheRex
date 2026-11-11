<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SaintQuest\Battles;
use SaintQuest\Db;
use SaintQuest\Deeds;
use SaintQuest\Saints;

test_db();

$p = make_user('Player', 0);
$other = make_user('Other', 0);
$saint = Saints::ofOwner($p)[0]['id'];
$otherSaint = Saints::ofOwner($other)[0]['id'];

expect_error(fn () => Battles::start($p, 'duel', $otherSaint), 403, 'cannot battle with someone else\'s saint');
expect_error(fn () => Battles::start($p, 'dragon', $saint), 404, 'unknown game rejected');

$age = function (string $id, int $ms): void { Db::run('UPDATE sq_battles SET started_ms = started_ms - ? WHERE id = ?', [$ms, $id]); };

// Too fast: no reward
$b = Battles::start($p, 'duel', $saint);
$r = Battles::finish($p, $b['battleId'], $b['token'], 100, true);
check($r['won'] === false && $r['reward'] === 0 && $r['ratingDelta'] === 0, 'an instant "win" does not count');

// Real duel win
$b = Battles::start($p, 'duel', $saint);
$age($b['battleId'], 20000);
expect_error(fn () => Battles::finish($p, $b['battleId'], 'wrong-token', 80, true), 404, 'wrong battle token rejected');
$r = Battles::finish($p, $b['battleId'], $b['token'], 80, true);
check($r['won'] === true && $r['reward'] === 40 + 32, 'duel win pays 40 + score bonus');
check($r['rating'] === 1016, 'win raises the saint rating by 16');
check(virtue($p) === 72 && (int) Db::value('SELECT lifetime FROM sq_users WHERE id = ?', [$p]) === 72, 'reward is added to wallet and lifetime');
expect_error(fn () => Battles::finish($p, $b['battleId'], $b['token'], 80, true), 409, 'a battle can only be finished once');

// Score is clamped
$b = Battles::start($p, 'duel', $saint);
$age($b['battleId'], 20000);
$r = Battles::finish($p, $b['battleId'], $b['token'], 999999, true);
check($r['score'] === 100 && $r['reward'] === 80, 'scores above the game maximum are clamped');

// Race: server decides from the seeded rivals
$b = Battles::start($p, 'race', $saint);
check(count($b['rivals']) === 3, 'race has three seeded rival saints');
$best = max(Battles::rivals($b['seed']));
$age($b['battleId'], 46000);
$r = Battles::finish($p, $b['battleId'], $b['token'], $best, true);
check($r['won'] === false, 'tying the best rival is not a win, whatever the client says');
$b = Battles::start($p, 'race', $saint);
$best = max(Battles::rivals($b['seed']));
$age($b['battleId'], 46000);
$r = Battles::finish($p, $b['battleId'], $b['token'], $best + 1, false);
check($r['won'] === true, 'beating every rival wins the race');

// Loss
$b = Battles::start($p, 'defense', $saint);
$age($b['battleId'], 61000);
$before = (int) Db::value('SELECT rating FROM sq_saints WHERE id = ?', [$saint]);
$r = Battles::finish($p, $b['battleId'], $b['token'], 4, false);
check($r['won'] === false && $r['reward'] === 10 && $r['rating'] === $before - 8, 'a loss pays 10 and lowers rating by 8');

// Home saint = best rated
Saints::grant($p, 'george', 'store');
check(Saints::ofOwner($p)[0]['id'] === $saint, 'the best-rated saint is first (shown on the home page)');

// Deeds
$v = virtue($p);
$d = Deeds::complete($p, 'apples');
check($d['reward'] === 25 && virtue($p) === $v + 25, 'helping a villager pays 25 virtue');
check((int) Db::value('SELECT good_deeds FROM sq_users WHERE id = ?', [$p]) === 1, 'good deeds counter goes up');
expect_error(fn () => Deeds::complete($p, 'apples'), 409, 'each villager can be helped once a day');
expect_error(fn () => Deeds::complete($p, 'gold'), 404, 'unknown deed rejected');
check(Deeds::today($p) === ['apples'], 'today\'s deeds are listed');

// Leaderboard
$lb = Saints::leaderboard(3);
check($lb[0]['username'] === 'Player' && $lb[0]['points'] === (int) Db::value('SELECT lifetime FROM sq_users WHERE id = ?', [$p]) && $lb[0]['saint'] === 'francis', 'leaderboard ranks by lifetime virtue with the best saint');

// Search
$s = Saints::search('ireland');
check($s['store'] === ['patrick'], 'search finds saints by what they are patron of');
check(in_array('Player', array_column(Saints::search('play')['players'], 'username'), true), 'search finds players');

finish();
