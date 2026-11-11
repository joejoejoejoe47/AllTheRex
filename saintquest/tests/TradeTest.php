<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SaintQuest\Db;
use SaintQuest\Market;
use SaintQuest\Saints;

test_db();

$ann = make_user('Ann', 1000);
$ben = make_user('Ben', 300);
$cat = make_user('Cat', 5000);

// Store
$r = Saints::buyFromStore($ann, 'george');
check(virtue($ann) === 1000 - 650, 'store purchase debits the exact price');
check(count(Saints::ofOwner($ann)) === 2, 'store purchase adds the saint');
expect_error(fn () => Saints::buyFromStore($ben, 'michael'), 402, 'cannot buy a saint you cannot afford');
check(virtue($ben) === 300, 'failed purchase leaves the wallet untouched');
expect_error(fn () => Saints::buyFromStore($ben, 'not-a-saint'), 404, 'unknown saint rejected');

// Listing
$george = $r['saintId'];
expect_error(fn () => Market::list($ben, $george, 100, 'Not mine'), 403, 'cannot list someone else\'s saint');
expect_error(fn () => Market::list($ann, $george, 5, 'Too cheap'), 400, 'price below minimum rejected');
expect_error(fn () => Market::list($ann, $george, 100, ''), 400, 'a benefit story is required');
$l = Market::list($ann, $george, 400, 'St. George gave me courage at my swim test!');
check(Db::value('SELECT status FROM sq_saints WHERE id = ?', [$george]) === 'listed', 'listed saint is marked listed');
expect_error(fn () => Market::list($ann, $george, 300, 'Again'), 409, 'cannot list the same saint twice');
$open = Market::open();
check(count($open) === 1 && $open[0]['benefit'] === 'St. George gave me courage at my swim test!' && $open[0]['seller'] === 'Ann', 'listing is public with its story and seller');
check(count(Market::open('courage')) === 1 && count(Market::open('dragonfruit')) === 0, 'listings can be searched');

// Buying
expect_error(fn () => Market::buy($ann, $l['listingId']), 400, 'cannot buy your own listing');
expect_error(fn () => Market::buy($ben, $l['listingId']), 402, 'buyer without enough points is refused');
check(Db::value('SELECT status FROM sq_listings WHERE id = ?', [$l['listingId']]) === 'open', 'refused purchase leaves the listing open (rolled back)');
check(Db::value('SELECT owner_id FROM sq_saints WHERE id = ?', [$george]) === $ann, 'refused purchase leaves the saint with the seller');
check(virtue($ben) === 300, 'refused purchase leaves the buyer wallet untouched');

$annBefore = virtue($ann);
$res = Market::buy($cat, $l['listingId']);
check(virtue($cat) === 5000 - 400, 'buyer pays the listed price');
check(virtue($ann) === $annBefore + 400, 'seller receives the price');
check(Db::value('SELECT owner_id FROM sq_saints WHERE id = ?', [$george]) === $cat, 'saint moves to the buyer');
check(Db::value('SELECT status FROM sq_saints WHERE id = ?', [$george]) === 'ok', 'bought saint is no longer listed');
expect_error(fn () => Market::buy($ben, $l['listingId']), 409, 'a sold listing cannot be bought again');
check((int) Db::value('SELECT COUNT(*) FROM sq_notifications WHERE user_id = ? AND kind = ?', [$ann, 'sold']) === 1, 'seller is notified of the sale');
$total = (int) Db::value('SELECT SUM(virtue) FROM sq_users');
check($total === (1000 - 650) + 300 + 5000, 'points are conserved by a trade (no points created or lost)');

// Double-spend: a buyer with points for only one of two listings.
$s1 = Saints::grant($ann, 'patrick', 'store');
$s2 = Saints::grant($ann, 'clare', 'store');
$l1 = Market::list($ann, $s1, 200, 'Helped me be brave');
$l2 = Market::list($ann, $s2, 200, 'Helped me be kind');
Db::run('UPDATE sq_users SET virtue = 300 WHERE id = ?', [$ben]);
Market::buy($ben, $l1['listingId']);
expect_error(fn () => Market::buy($ben, $l2['listingId']), 402, 'second purchase refused when the first used up the points');
check(virtue($ben) === 100, 'wallet never goes negative');
check(Db::value('SELECT owner_id FROM sq_saints WHERE id = ?', [$s2]) === $ann, 'unaffordable saint stays with the seller');

// Concurrent race: two separate PDO connections to one SQLite file try to buy the same listing.
$file = sys_get_temp_dir() . '/sq-race-' . bin2hex(random_bytes(4)) . '.sqlite';
$mk = function () use ($file) {
    $p = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('PRAGMA busy_timeout = 5000');
    return $p;
};
$pa = $mk();
Db::useConnection($pa, 'sqlite');
Db::runSchema((string) file_get_contents(__DIR__ . '/../app/sql/schema.sql'));
$seller = make_user('Seller', 0);
$b1 = make_user('Buyer One', 500);
$b2 = make_user('Buyer Two', 500);
$sx = Saints::grant($seller, 'george', 'store');
$lx = Market::list($seller, $sx, 300, 'Race test');
$wins = 0;
$conflicts = 0;
foreach ([[$pa, $b1], [$mk(), $b2]] as [$conn, $buyer]) {
    Db::useConnection($conn, 'sqlite');
    try { Market::buy($buyer, $lx['listingId']); $wins++; } catch (\SaintQuest\HttpError $e) { if ($e->status === 409) { $conflicts++; } }
}
check($wins === 1 && $conflicts === 1, 'two buyers on separate connections: exactly one wins, the other gets 409');
Db::useConnection($pa, 'sqlite');
check(virtue($seller) === 300 && virtue($b1) + virtue($b2) === 700, 'only one buyer paid, seller paid once');
@unlink($file);

finish();
