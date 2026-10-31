<?php
declare(strict_types=1);

/** Starter titles so the catalog isn't empty. Users can add more from the site. */
function rex_seed_titles(): array
{
    return [
        // movies: [title, director, year]
        'movie' => [
            ['The Shawshank Redemption', 'Frank Darabont', 1994], ['The Godfather', 'Francis Ford Coppola', 1972],
            ['Pulp Fiction', 'Quentin Tarantino', 1994], ['The Dark Knight', 'Christopher Nolan', 2008],
            ['Inception', 'Christopher Nolan', 2010], ['Parasite', 'Bong Joon-ho', 2019],
            ['Spirited Away', 'Hayao Miyazaki', 2001], ['Blade Runner', 'Ridley Scott', 1982],
            ['Alien', 'Ridley Scott', 1979], ['Moonlight', 'Barry Jenkins', 2016],
            ['Everything Everywhere All at Once', 'Daniel Kwan and Daniel Scheinert', 2022], ['Mad Max: Fury Road', 'George Miller', 2015],
            ['The Grand Budapest Hotel', 'Wes Anderson', 2014], ['Arrival', 'Denis Villeneuve', 2016],
            ['Whiplash', 'Damien Chazelle', 2014], ['Get Out', 'Jordan Peele', 2017],
        ],
        // albums: [title, artist, year]
        'album' => [
            ['Abbey Road', 'The Beatles', 1969], ['The Dark Side of the Moon', 'Pink Floyd', 1973],
            ['Rumours', 'Fleetwood Mac', 1977], ['Kind of Blue', 'Miles Davis', 1959],
            ['Thriller', 'Michael Jackson', 1982], ['Nevermind', 'Nirvana', 1991],
            ['OK Computer', 'Radiohead', 1997], ['To Pimp a Butterfly', 'Kendrick Lamar', 2015],
            ['Blue', 'Joni Mitchell', 1971], ['Illmatic', 'Nas', 1994],
            ['Random Access Memories', 'Daft Punk', 2013], ['Lemonade', 'Beyoncé', 2016],
            ['Currents', 'Tame Impala', 2015], ['Back to Black', 'Amy Winehouse', 2006],
            ['Purple Rain', 'Prince and The Revolution', 1984], ['Blonde', 'Frank Ocean', 2016],
        ],
        // books: [title, author, year]
        'book' => [
            ['1984', 'George Orwell', 1949], ['To Kill a Mockingbird', 'Harper Lee', 1960],
            ['Dune', 'Frank Herbert', 1965], ['The Hobbit', 'J.R.R. Tolkien', 1937],
            ['Pride and Prejudice', 'Jane Austen', 1813], ['The Great Gatsby', 'F. Scott Fitzgerald', 1925],
            ['Beloved', 'Toni Morrison', 1987], ['Neuromancer', 'William Gibson', 1984],
            ['The Handmaid\'s Tale', 'Margaret Atwood', 1985], ['One Hundred Years of Solitude', 'Gabriel García Márquez', 1967],
            ['Sapiens', 'Yuval Noah Harari', 2011], ['The Left Hand of Darkness', 'Ursula K. Le Guin', 1969],
            ['Project Hail Mary', 'Andy Weir', 2021], ['Circe', 'Madeline Miller', 2018],
            ['The Name of the Wind', 'Patrick Rothfuss', 2007], ['Educated', 'Tara Westover', 2018],
        ],
    ];
}

/** @return int number of new titles added (already-present ones are skipped) */
function rex_seed(): int
{
    $added = 0;
    foreach (rex_seed_titles() as $cat => $rows) {
        foreach ($rows as [$title, $creator, $year]) {
            [$id, $err] = add_item($cat, $title, $creator, (string)$year, null, false);
            if ($id && !$err) {
                $added++;
            }
        }
    }
    return $added;
}

/**
 * Bulk-import the ranked title lists in data/{movies,albums,books}.txt  (one "Title | Creator | Year" per line,
 * most popular first; '#' = comment). Safe to run repeatedly: titles already present are skipped (their
 * popularity rank is filled in), nothing is deleted. Fast: one read of the catalogue, then multi-row inserts.
 * @return array{added:int, ranked:int, skipped:int, bad:int}
 */
function rex_seed_bulk(?string $dataDir = null): array
{
    $dataDir = $dataDir ?? REX_ROOT . '/data';
    $pdo    = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $stat   = ['added' => 0, 'ranked' => 0, 'skipped' => 0, 'bad' => 0];

    $byKey = [];    // dedupe_key => [id, seed_rank]
    $byName = [];   // "cat|normalised title" => [[id, year, seed_rank], ...]
    foreach (q('SELECT id, category, title, year, dedupe_key, seed_rank FROM items') as $r) {
        $byKey[$r['dedupe_key']] = [(int)$r['id'], $r['seed_rank']];
        $byName[$r['category'] . '|' . rex_norm($r['title'])][] = [(int)$r['id'], $r['year'] === null ? null : (int)$r['year'], $r['seed_rank']];
    }

    foreach (['movie' => 'movies', 'album' => 'albums', 'book' => 'books'] as $cat => $file) {
        $path = "$dataDir/$file.txt";
        if (!is_file($path)) {
            continue;
        }
        $rank = 0;
        $batch = [];
        $flush = function () use (&$batch, $pdo, $sqlite, &$stat) {
            foreach (array_chunk($batch, 100) as $chunk) {
                $sql = ($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO items (category, title, creator, year, dedupe_key, seed_rank) VALUES '
                     . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?)'));
                $params = [];
                foreach ($chunk as $row) {
                    array_push($params, ...$row);
                }
                $stat['added'] += qx($sql, $params);
            }
            $batch = [];
        };
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $title = clean_text($parts[0] ?? '', 190);
            $by    = clean_text($parts[1] ?? '', 190);
            $yr    = (isset($parts[2]) && ctype_digit($parts[2]) && (int)$parts[2] >= 1000 && (int)$parts[2] <= 2100) ? (int)$parts[2] : null;
            if ($title === '') {
                $stat['bad']++;
                continue;
            }
            $rank++;
            $key = item_dedupe_key($cat, $title, $by);
            $existing = $byKey[$key] ?? null;
            if (!$existing) {
                foreach ($byName[$cat . '|' . rex_norm($title)] ?? [] as [$id, $y, $rk]) {
                    if ($yr === null || $y === null || abs($y - $yr) <= 1) { $existing = [$id, $rk]; break; }
                }
            }
            if ($existing) {
                if ($existing[1] === null) {
                    qx('UPDATE items SET seed_rank = ? WHERE id = ?', [$rank, $existing[0]]);
                    $stat['ranked']++;
                } else {
                    $stat['skipped']++;
                }
                continue;
            }
            $byKey[$key] = [0, $rank];
            $byName[$cat . '|' . rex_norm($title)][] = [0, $yr, $rank];
            $batch[] = [$cat, $title, $by, $yr, $key, $rank];
        }
        $flush();
    }
    return $stat;
}
