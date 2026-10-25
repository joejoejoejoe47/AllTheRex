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
            [$id, $err] = add_item($cat, $title, $creator, (string)$year, null);
            if ($id && !$err) {
                $added++;
            }
        }
    }
    return $added;
}
