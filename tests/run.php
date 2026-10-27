<?php
declare(strict_types=1);
/**
 * Offline test suite (uses SQLite in memory, no MySQL needed):  php tests/run.php
 * Not needed on the server; safe to leave out of deployment.
 */
define('REX_ROOT', dirname(__DIR__));
foreach (['db', 'schema', 'helpers', 'auth', 'images', 'catalog', 'recommender', 'seed'] as $f) {
    require REX_ROOT . "/lib/$f.php";
}
mb_internal_encoding('UTF-8');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$GLOBALS['REX_PDO'] = $pdo;
rex_install($pdo);

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}
function id_of(string $title): int {
    $r = q1('SELECT id FROM items WHERE title = ?', [$title]);
    return (int)$r['id'];
}
function title_of(int $id): string { return q1('SELECT title FROM items WHERE id = ?', [$id])['title']; }

echo "Pure similarity maths\n";
// Three people rate X and Y both high, one rates them opposite.
$by = [
    1 => [10 => 5, 20 => 5, 30 => 1],
    2 => [10 => 4, 20 => 5, 30 => 2],
    3 => [10 => 5, 20 => 4, 30 => 1],
    4 => [10 => 2, 20 => 1, 30 => 5],
];
$pairs = [];
foreach (rex_pair_sims($by, 3.5) as [$a, $b, $s, $n]) { $pairs["$a|$b"] = [$s, $n]; }
check('liked-together pair is positive', $pairs['10|20'][0] > 0.2, json_encode($pairs['10|20']));
check('opposite-rated pair is negative', $pairs['10|30'][0] < -0.2, json_encode($pairs['10|30']));
check('co-rater count recorded', $pairs['10|20'][1] === 4);

$few  = rex_pair_sims([1 => [1 => 5, 2 => 5], 2 => [1 => 5, 2 => 5]], 3.5);
$many = rex_pair_sims(array_fill(1, 30, [1 => 5, 2 => 5]), 3.5);
check('more shared raters => stronger similarity', $many[0][2] > $few[0][2] + 0.2, "few={$few[0][2]} many={$many[0][2]}");
check('single shared rater is ignored', rex_pair_sims([1 => [1 => 5, 2 => 5]], 3.5) === []);
check('only-pairs filter works', count(rex_pair_sims($by, 3.5, 10)) === 2);
check('users with all-5s still count as liking both', ($many[0][2] ?? 0) > 0.5, (string)($many[0][2] ?? 'none'));

echo "Catalog\n";
check('seed adds titles', rex_seed() === 48);
check('seed is idempotent', rex_seed() === 0);
[$dupId, $dupErr] = add_item('book', '  dune ', 'FRANK herbert', '1965', null);
check('duplicate detected case/space-insensitively', $dupId === id_of('Dune') && isset($dupErr['duplicate']));
[, $err] = add_item('movie', '', '', '', null);
check('empty title rejected', isset($err['title']));
[, $err] = add_item('podcast', 'X', '', '', null);
check('bad category rejected', isset($err['category']));
[, $err] = add_item('movie', 'Future', '', '3000', null);
check('absurd year rejected', isset($err['year']));

echo "Accounts\n";
[$uid, $errs] = register_user('Me@Example.com', 'Me', 'correct horse');
check('register ok', $uid > 0 && !$errs);
[, $errs] = register_user('me@example.com', 'Me2', 'correct horse');
check('duplicate email rejected', isset($errs['email']));
[, $errs] = register_user('nope', 'X', 'short');
check('bad input rejected', isset($errs['email'], $errs['name'], $errs['password']));
check('login works', check_credentials('ME@example.com', 'correct horse') === $uid);
check('wrong password fails', check_credentials('me@example.com', 'wrong') === null);
check('unknown email fails', check_credentials('who@example.com', 'x') === null);

echo "Recommendations from taste clusters\n";
$scifi  = ['Blade Runner', 'Alien', 'Neuromancer', 'Dune', 'Arrival', 'OK Computer', 'Mad Max: Fury Road', 'The Left Hand of Darkness'];
$classy = ['Pride and Prejudice', 'Abbey Road', 'Rumours', 'The Great Gatsby', 'Blue', 'The Grand Budapest Hotel', 'To Kill a Mockingbird', 'Back to Black'];
mt_srand(7);
$mk = function (string $email, array $loved, array $hated) {
    [$u] = register_user($email, 'User', 'password123');
    foreach ($loved as $t) { if (mt_rand(0, 9) < 8) qx('REPLACE INTO ratings (user_id,item_id,rating) VALUES (?,?,?)', [$u, id_of($t), mt_rand(4, 5)]); }
    foreach ($hated as $t) { if (mt_rand(0, 9) < 5) qx('REPLACE INTO ratings (user_id,item_id,rating) VALUES (?,?,?)', [$u, id_of($t), mt_rand(1, 2)]); }
};
for ($i = 0; $i < 14; $i++) { $mk("sf$i@x.com", $scifi, $classy); }
for ($i = 0; $i < 14; $i++) { $mk("cl$i@x.com", $classy, $scifi); }

check('no recommendations before any ratings', rec_for_user($uid) === []);
$t0 = microtime(true);
$n = rec_rebuild_all();
printf("  (rebuilt %d pairs in %.0f ms)\n", $n, (microtime(true) - $t0) * 1000);
check('rebuild produced similarities', $n > 50);

// New user rates two sci-fi titles.
set_rating($uid, id_of('Blade Runner'), 5);
set_rating($uid, id_of('Alien'), 5);
$recs = rec_for_user($uid, null, 8);
$top  = array_map(fn($r) => $r['title'], array_slice($recs, 0, 5));
echo '  top 5: ' . implode(' | ', $top) . "\n";
$sciSet = array_flip($scifi);
$hits = count(array_filter($top, fn($t) => isset($sciSet[$t])));
check('top 5 are the sci-fi cluster', $hits >= 4, "hits=$hits");
check('rated titles never recommended', !array_intersect($top, ['Blade Runner', 'Alien']));
check('recommendations explain themselves', !empty($recs[0]['because']) && $recs[0]['reason'] === 'similar');
check('predicted score is within 1-5', $recs[0]['score'] >= 1 && $recs[0]['score'] <= 5);

$cat = rec_for_user($uid, 'book', 8);
check('category filter respected', $cat && !array_filter($cat, fn($r) => $r['category'] !== 'book'));

// "vice versa": rating a classic high & sci-fi low flips the picture for a second user.
[$u2] = register_user('second@x.com', 'Second', 'password123');
set_rating($u2, id_of('Pride and Prejudice'), 5);
set_rating($u2, id_of('Rumours'), 5);
set_rating($u2, id_of('Alien'), 1);
$top2 = array_map(fn($r) => $r['title'], array_slice(rec_for_user($u2, null, 5), 0, 5));
echo '  second user top 5: ' . implode(' | ', $top2) . "\n";
$classSet = array_flip($classy);
check('opposite taste gets the other cluster', count(array_filter($top2, fn($t) => isset($classSet[$t]))) >= 4);

echo "Incremental update\n";
$before = (int)q1('SELECT COUNT(*) c FROM item_sim')['c'];
set_rating($u2, id_of('Dune'), 1);
check('rating a title updates its similarities', (int)q1('SELECT COUNT(*) c FROM item_sim')['c'] >= $before - 5);
set_rating($u2, id_of('Dune'), 0);
check('removing a rating deletes it', q1('SELECT 1 x FROM ratings WHERE user_id=? AND item_id=?', [$u2, id_of('Dune')]) === null);
$sim = rec_similar_items(id_of('Blade Runner'), 5);
check('similar-titles list is populated and sorted', count($sim) === 5 && $sim[0]['sim'] >= $sim[4]['sim']);
echo '  similar to Blade Runner: ' . implode(' | ', array_map(fn($r) => $r['title'], $sim)) . "\n";

echo "Cold start / listings\n";
[$u3] = register_user('new@x.com', 'Newbie', 'password123');
set_rating($u3, id_of('Educated'), 5);          // a title nobody else rated => no neighbours
$fallback = rec_for_user($u3, null, 6);
check('falls back to popular titles when no similarities', count($fallback) === 6 && $fallback[0]['reason'] === 'popular');
[$rows, $total] = catalog_list('movie', '', 'popular', 1, $u3);
check('list + count', $total === 16 && count($rows) === 16);
[$rows] = catalog_list(null, '100%', 'az', 1, null);
check('LIKE wildcards are escaped', count($rows) === 0);
[$rows] = catalog_list(null, 'nolan', 'az', 1, null);
check('search matches creator', count($rows) === 2);
check('invalid rating rejected', (function () { try { set_rating(1, 1, 9); } catch (InvalidArgumentException $e) { return true; } return false; })());

echo "Cover images (sample API responses; live services can't be reached from the build sandbox)\n";
$itunes = json_encode(['results' => [
    ['trackName' => 'Inception: The Making Of', 'artistName' => 'Someone Else', 'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/Video/aa/bad.jpg/100x100bb.jpg'],
    ['trackName' => 'Inception', 'artistName' => 'Christopher Nolan', 'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/Video/bb/good.jpg/100x100bb.jpg'],
]]);
$deezer = json_encode(['data' => [
    ['title' => 'Abbey Road (Remastered)', 'artist' => ['name' => 'The Beatles'], 'cover_big' => 'https://cdn-images.dzcdn.net/images/cover/abc/500x500-000000-80-0-0.jpg'],
]]);
$ol = json_encode(['docs' => [
    ['title' => 'Dune Messiah', 'author_name' => ['Frank Herbert'], 'cover_i' => 111],
    ['title' => 'Dune', 'author_name' => ['Frank Herbert'], 'cover_i' => 222],
    ['title' => 'Dune', 'author_name' => ['Frank Herbert']],
]]);
$GLOBALS['REX_HTTP'] = function (string $url) use ($itunes, $deezer, $ol) {
    return match (true) { str_contains($url, 'itunes') => $itunes, str_contains($url, 'deezer') => $deezer, str_contains($url, 'openlibrary') => $ol, default => null };
};
$m = rex_find_image('movie', 'Inception', 'Christopher Nolan', 2010);
check('movie: picks the exact title, upsizes artwork', $m === 'https://is1-ssl.mzstatic.com/image/thumb/Video/bb/good.jpg/400x600bb.jpg', (string)$m);
$a = rex_find_image('album', 'Abbey Road', 'The Beatles', 1969);
check('album: matches despite "(Remastered)"', $a !== null && str_contains($a, 'dzcdn.net'), (string)$a);
$b = rex_find_image('book', 'Dune', 'Frank Herbert', 1965);
check('book: exact title with a cover beats longer title', $b === 'https://covers.openlibrary.org/b/id/222-L.jpg', (string)$b);
check('no match => null (falls back to generated cover)', rex_find_image('movie', 'Zzyzx Unknown Film', 'Nobody', null) === null);
check('same title by a different artist still accepted at lower rank (no crash)', is_string(rex_find_image('album', 'Abbey Road', 'Completely Different Band', null)));
$GLOBALS['REX_HTTP'] = fn($u) => null;
check('network failure => null, no exception', rex_find_image('movie', 'Inception', '', null) === null);
$GLOBALS['REX_HTTP'] = fn($u) => 'not json {{{';
check('garbage response => null', rex_find_image('book', 'Dune', '', null) === null);
check('host allowlist rejects other hosts', rex_clean_image_url('https://evil.example/x.jpg') === null && rex_clean_image_url('http://is1-ssl.mzstatic.com/x.jpg') === null);
check('host allowlist accepts known hosts', rex_clean_image_url('https://is1-ssl.mzstatic.com/x.jpg') !== null);
check('pasted URL: https only, no quotes/scripts', rex_user_image_url('javascript:alert(1)') === null && rex_user_image_url('https://a.com/x"onerror="y') === null && rex_user_image_url('https://a.com/x.jpg') !== null);

$GLOBALS['REX_HTTP'] = fn(string $url) => str_contains($url, 'itunes')
    ? json_encode(['results' => [['trackName' => 'Heat', 'artistName' => 'Michael Mann', 'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/Video/cc/good.jpg/100x100bb.jpg']]]) : '{}';   // other services: reachable, no match
[$newId] = add_item('movie', 'Heat', 'Michael Mann', '1995', null, true);
check('add_item saves the image URL', str_contains((string)q1('SELECT image_url FROM items WHERE id=?', [$newId])['image_url'], 'good.jpg'));
$tried = (int)q1("SELECT COUNT(*) c FROM items WHERE image_tried = 0")['c'];
[$done, $left] = rex_backfill_images(30.0);
check('backfill processes every untried title once', $done === $tried && $left === 0, "done=$done left=$left");
check('titles with no match are marked tried (no endless retries)', rex_backfill_images(5.0) === [0, 0]);
unset($GLOBALS['REX_HTTP']);

$wiki = json_encode(['query' => ['pages' => [
    '1' => ['title' => 'Inception (soundtrack)', 'index' => 2, 'description' => '2010 soundtrack album', 'thumbnail' => ['source' => 'https://upload.wikimedia.org/wikipedia/en/x/snd.jpg']],
    '2' => ['title' => 'Inception', 'index' => 1, 'description' => '2010 film by Christopher Nolan', 'thumbnail' => ['source' => 'https://upload.wikimedia.org/wikipedia/en/2/2e/Inception_(2010)_theatrical_poster.jpg']],
]]]);
$GLOBALS['REX_HTTP'] = fn(string $url) => str_contains($url, 'wikipedia') ? $wiki : null;   // iTunes unreachable
check('movie falls back to Wikipedia when iTunes is unreachable', rex_find_image('movie', 'Inception', 'Christopher Nolan', 2010) === 'https://upload.wikimedia.org/wikipedia/en/2/2e/Inception_(2010)_theatrical_poster.jpg');
check('Wikipedia fallback rejects wrong year and non-films', rex_find_image('movie', 'Inception', '', 1999) === null);
$GLOBALS['REX_HTTP'] = fn(string $url) => null;
[$rid] = add_item('movie', 'Retry Me', 'X', '2001', null, false);
rex_fetch_image_for($rid);
check('network failure leaves the title untried (will retry later)', (int)q1('SELECT image_tried t FROM items WHERE id=?', [$rid])['t'] === 0);
$GLOBALS['REX_HTTP'] = fn(string $url) => json_encode(['results' => []]);
rex_fetch_image_for($rid);
check('a successful lookup with no match is final (marked tried)', (int)q1('SELECT image_tried t FROM items WHERE id=?', [$rid])['t'] === 1);
unset($GLOBALS['REX_HTTP']);

echo "Upgrading an older database\n";
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$old->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, category TEXT, title TEXT, creator TEXT, year INTEGER, dedupe_key TEXT, created_by INTEGER, created_at TEXT)');
rex_migrate($old, 'sqlite'); rex_migrate($old, 'sqlite');
$cols = array_column($old->query('PRAGMA table_info(items)')->fetchAll(), 'name');
check('migration adds image columns, and is repeatable', in_array('image_url', $cols) && in_array('image_tried', $cols));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
