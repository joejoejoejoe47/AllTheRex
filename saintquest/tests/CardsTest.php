<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SaintQuest\Cards;
use SaintQuest\Db;
use SaintQuest\Market;
use SaintQuest\Saints;

test_db();

// Codes
$codes = [];
for ($i = 0; $i < 300; $i++) {
    $codes[] = Cards::newCode();
}
check(count(array_unique($codes)) === 300, '300 generated codes are all different');
check((bool) preg_match('/^SQ-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $codes[0]), 'code format SQ-XXXX-XXXX-XXXX (no I, L, O, U)');
check(Cards::normalize($codes[0]) === $codes[0], 'a valid code normalizes to itself');
check(Cards::normalize(strtolower(str_replace('-', ' ', $codes[0]))) === $codes[0], 'lowercase and spaces are accepted');
check(Cards::normalize('https://alltherex.com/saintquest/?card=' . $codes[0]) === $codes[0], 'the QR card link is accepted');
$typo = $codes[0];
$typo[4] = $typo[4] === 'A' ? 'B' : 'A';
check(Cards::normalize($typo) === null, 'a one-letter typo fails the check character');
check(Cards::normalize('SQ-1234') === null, 'short codes rejected');

$alice = make_user('Alice');
$bob = make_user('Bob');
$carl = make_user('Carl');

$printed = Cards::print($alice, 'patrick', 2);
check(count($printed) === 2 && $printed[0]['key'] === 'patrick', 'printing makes cards with the chosen saint');
expect_error(fn () => Cards::print($alice, 'michael', 1), 400, 'legendary saints cannot be printed');
check(count(Cards::printedUnclaimed($alice)) === 2, 'printed cards are listed until scanned');
$code = $printed[0]['code'];

// Unknown and bad codes
expect_error(fn () => Cards::scan($bob, 'SQ-0000-0000-0001'), 400, 'bad check character rejected before the database');
$fake = Cards::newCode();
expect_error(fn () => Cards::scan($bob, $fake), 404, 'a well-formed code that was never printed is rejected');

// First scan adds the card
$r = Cards::scan($bob, $code);
check($r['result'] === 'added' && $r['key'] === 'patrick', 'first scan adds the card to the scanner');
$saintId = $r['saintId'];
check(Db::value('SELECT owner_id FROM sq_saints WHERE id = ?', [$saintId]) === $bob, 'the card\'s saint belongs to the scanner');
check(Db::value('SELECT owner_id FROM sq_cards WHERE code = ?', [$code]) === $bob, 'the card is registered to the scanner');

// Same account rescans: no change, no claim
$again = Cards::scan($bob, $code);
check($again['result'] === 'already-yours', 'rescanning your own card changes nothing');
check((int) Db::value('SELECT COUNT(*) FROM sq_saints WHERE card_id IS NOT NULL') === 1, 'rescanning does not duplicate the saint');
check((int) Db::value('SELECT COUNT(*) FROM sq_card_claims') === 0, 'rescanning does not open a claim');

// Bob lists it, then Carl scans the same card: claim, freeze, listing pulled
$l = Market::list($bob, $saintId, 100, 'St. Patrick helped me learn about Ireland');
$c = Cards::scan($carl, $code);
check($c['result'] === 'claim-started', 'a different account scanning a registered card starts a claim');
check(Db::value('SELECT status FROM sq_saints WHERE id = ?', [$saintId]) === 'frozen', 'the saint is frozen during the claim');
check(Db::value('SELECT status FROM sq_listings WHERE id = ?', [$l['listingId']]) === 'cancelled', 'an open listing for the card is taken down');
expect_error(fn () => Market::list($bob, $saintId, 100, 'try again'), 409, 'owner cannot list a frozen saint');
$q = Cards::openQuestions($bob);
check(count($q) === 1 && $q[0]['role'] === 'owner' && $q[0]['status'] === 'pending', 'the owner is asked "Did you trade this card?"');
check((int) Db::value("SELECT COUNT(*) FROM sq_notifications WHERE user_id = ? AND kind = 'claim'", [$bob]) === 1, 'the owner gets a notification');
$qc = Cards::openQuestions($carl);
check(count($qc) === 1 && $qc[0]['role'] === 'claimant' && $qc[0]['code'] === null, 'the claimant sees the claim status (without the code)');
$c2 = Cards::scan($carl, $code);
check($c2['result'] === 'claim-open' && (int) Db::value('SELECT COUNT(*) FROM sq_card_claims') === 1, 'claimant rescanning does not open a second claim');
$dave = make_user('Dave');
expect_error(fn () => Cards::scan($dave, $code), 409, 'a third account cannot pile on while the card is frozen');
expect_error(fn () => Cards::respond($carl, $c['claimId'], true), 403, 'only the owner can answer the question');

// Owner says NO -> asked for photo -> disputed
$no = Cards::respond($bob, $c['claimId'], false);
check($no['status'] === 'awaiting_photo', 'answering No asks the owner for a photo of the card');
expect_error(fn () => Cards::addPhoto($dave, $c['claimId'], 'x'), 403, 'outsiders cannot add photos');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$img = \SaintQuest\Images::store($png, $bob, 'card_photo');
$d = Cards::addPhoto($bob, $c['claimId'], $img);
check($d['status'] === 'disputed', 'the owner\'s photo makes the claim disputed');
check(Db::value('SELECT status FROM sq_cards WHERE code = ?', [$code]) === 'disputed', 'the card is marked disputed');
check(Db::value('SELECT status FROM sq_saints WHERE id = ?', [$saintId]) === 'frozen', 'the saint stays frozen while disputed');
check(\SaintQuest\Images::canView($img, $carl) && \SaintQuest\Images::canView($img, $bob) && !\SaintQuest\Images::canView($img, $dave) && !\SaintQuest\Images::canView($img, null), 'the card photo is visible to the two players only');
check(Cards::openQuestions($carl)[0]['status'] === 'disputed' && Cards::openQuestions($bob)[0]['status'] === 'disputed', 'both players see the disputed status');

// Claimant withdraws -> unfrozen, stays with Bob
$w = Cards::withdraw($carl, $c['claimId']);
check($w['status'] === 'withdrawn', 'the claimant can withdraw');
check(Db::value('SELECT owner_id FROM sq_saints WHERE id = ?', [$saintId]) === $bob && Db::value('SELECT status FROM sq_saints WHERE id = ?', [$saintId]) === 'ok', 'after withdrawal the saint is back with its owner and unfrozen');
expect_error(fn () => Cards::scan($carl, $code), 429, 'a withdrawn claimant cannot reopen a claim on the same day');

// Yes path: Dave scans, Bob says YES -> card moves
$c3 = Cards::scan($dave, $code);
check($c3['result'] === 'claim-started', 'another player can claim after the card is unfrozen');
$yes = Cards::respond($bob, $c3['claimId'], true);
check($yes['status'] === 'accepted', 'answering Yes accepts the trade');
check(Db::value('SELECT owner_id FROM sq_saints WHERE id = ?', [$saintId]) === $dave, 'Yes moves the saint to the new player');
check(Db::value('SELECT owner_id FROM sq_cards WHERE code = ?', [$code]) === $dave, 'Yes moves the card to the new player');
check(Db::value('SELECT status FROM sq_saints WHERE id = ?', [$saintId]) === 'ok', 'the saint is unfrozen for its new owner');
check(count(Cards::openQuestions($bob)) === 0 && count(Cards::openQuestions($dave)) === 0, 'no open questions remain');
expect_error(fn () => Cards::respond($bob, $c3['claimId'], false), 409, 'a closed claim cannot be answered again');

// Rate limit on failed scans
$eve = make_user('Eve');
$limited = false;
for ($i = 0; $i < 15; $i++) {
    try { Cards::scan($eve, Cards::newCode()); } catch (\SaintQuest\HttpError $e) { if ($e->status === 429) { $limited = true; break; } }
}
check($limited, 'guessing codes is rate limited');

// Daily print limit
$printedMore = 0;
try { for ($i = 0; $i < 5; $i++) { Cards::print($alice, 'random', 6); $printedMore++; } } catch (\SaintQuest\HttpError $e) { check($e->status === 429, 'printing is limited per day'); }

// Buying a card saint moves the card with it
$fiona = make_user('Fiona', 1000);
$card2 = $printed[1]['code'];
$s2 = Cards::scan($alice, $card2)['saintId'];
$l2 = Market::list($alice, $s2, 50, 'Lucky card');
Market::buy($fiona, $l2['listingId']);
check(Db::value('SELECT owner_id FROM sq_cards WHERE code = ?', [$card2]) === $fiona, 'buying a card saint on the market moves the card too');
check(Cards::scan($fiona, $card2)['result'] === 'already-yours', 'the buyer can rescan the card without a claim');

finish();
