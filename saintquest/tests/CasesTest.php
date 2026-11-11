<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SaintQuest\Cases;
use SaintQuest\Db;
use SaintQuest\Saints;

test_db();

$u = make_user('Walker', 200);
$virtue = fn () => (int) Db::value('SELECT virtue FROM sq_users WHERE id = ?', [$u]);
$deeds = fn () => (int) Db::value('SELECT good_deeds FROM sq_users WHERE id = ?', [$u]);

$s = Cases::status($u);
check($s['today'] === [] && $s['clues'] === [] && !$s['mysteryReady'] && !$s['mysterySolved'] && $s['blessing'] === 0, 'a new player has no cases, clues or blessings');

// Beggar: give 50 now, 100 comes back at the Store, once.
$r = Cases::complete($u, 'beggar');
check($r['gave'] === 50 && $r['blessing'] === 100, 'giving to the poor reports 50 given and a 100 blessing');
check($virtue() === 150, 'giving takes 50 virtue points', (string) $virtue());
check(Cases::status($u)['blessing'] === 100, 'the blessing waits for the Store');
expect_error(fn () => Cases::complete($u, 'beggar'), 409, 'the beggar can be helped once a day');
check($virtue() === 150, 'a refused second gift takes nothing');
$p = Cases::claimBlessing($u);
check($p['paid'] === 100 && $virtue() === 250, 'opening the Store pays the blessing (100)', (string) $virtue());
check(Cases::claimBlessing($u)['paid'] === 0 && $virtue() === 250, 'the blessing is paid only once');
check(Cases::status($u)['blessing'] === 0, 'no blessing left after paying');

$poor = make_user('Poor', 20);
expect_error(fn () => Cases::complete($poor, 'beggar'), 402, 'cannot give 50 with only 20 virtue');
check((int) Db::value('SELECT virtue FROM sq_users WHERE id = ?', [$poor]) === 20 && Cases::status($poor)['blessing'] === 0, 'a refused gift leaves no blessing');

// Kids fighting: daily, +30 virtue and a Good Deed.
$d0 = $deeds();
$r = Cases::complete($u, 'kids_fight');
check($r['reward'] === 30 && $virtue() === 280 && $deeds() === $d0 + 1, 'stopping the fight gives +30 virtue and a Good Deed');
expect_error(fn () => Cases::complete($u, 'kids_fight'), 409, 'each case once a day');
expect_error(fn () => Cases::complete($u, 'nope'), 404, 'unknown case rejected');

// Clues are once ever; the mystery needs 3 clues and 3 kind deeds.
Cases::complete($u, 'clue_bell');
expect_error(fn () => Cases::complete($u, 'clue_bell'), 409, 'a clue is found once');
Cases::complete($u, 'clue_dove');
Cases::complete($u, 'clue_lantern');
$s = Cases::status($u);
check(count($s['clues']) === 3 && $s['kindDone'] === 2 && !$s['mysteryReady'], 'three clues but only two kind deeds: not ready yet');
expect_error(fn () => Cases::solveMystery($u), 409, 'the mystery waits for three kind deeds');
Cases::complete($u, 'sick_man');
check(Cases::status($u)['mysteryReady'], 'three clues + three kind deeds: the mystery is ready');
$before = $virtue();
$m = Cases::solveMystery($u);
check($m['reward'] === 100 && $virtue() === $before + 100, 'ringing the bell pays 100');
expect_error(fn () => Cases::solveMystery($u), 409, 'the mystery is solved only once');
check(Cases::status($u)['mysterySolved'] && !Cases::status($u)['mysteryReady'], 'status shows the mystery solved');

// Tomorrow the daily cases come back, clues do not.
Db::run("UPDATE sq_cases SET day = '2000-01-01' WHERE user_id = ? AND day <> 'ever'", [$u]);
Db::run("UPDATE sq_blessings SET day = '2000-01-01' WHERE user_id = ?", [$u]);
$s = Cases::status($u);
check($s['today'] === [] && count($s['clues']) === 3, 'daily cases reset the next day; clues stay found');
Cases::complete($u, 'beggar');
check(Cases::status($u)['blessing'] === 100, 'a new day brings a new chance to give');

check(isset(Saints::me($u)['cases']['clues']), '/api/me includes the case status');
finish();
