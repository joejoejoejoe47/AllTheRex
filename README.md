# AllTheRex recommender (site root)

Rate movies, albums and books; get recommendations from people with similar taste.
Plain PHP 8 + MySQL, no framework, no Composer.

## How the recommendations work

Item-based collaborative filtering. For every pair of titles, look at the people who rated **both**.
If they tend to rate them the same way (both high, or both low) the pair is *correlated*; if they
disagree it is *anti-correlated*. When you rate X high, titles strongly correlated with X rise to the top
of your list (and the reverse for ones you rate low). Details and tuning constants are at the top of
`lib/recommender.php`:

* ratings are measured from a centre halfway between each user's own average and the scale midpoint;
* similarity is shrunk by `n / (n + 2)` where `n` is the number of shared raters, so two people agreeing is
  a weak signal and fifty agreeing is a strong one;
* rating a title refreshes that title's similarities immediately; `cli/rebuild.php` re-syncs everything.

Cross-category on purpose: a book can be recommended from a movie you loved. Use the category tabs to filter.

## One-time setup on the server

1. **Deploy** this repo to the site (Plesk → Git → Pull/Deploy). The app now lives at the site root; the old World Builder page is kept as `index.html_old` and `index.php_old/`.
2. **Create `config.php`** in Plesk → Files, by copying `config.sample.php`, and fill in your
   database name, user and password. Set `setup_token` to a long random string.
   *This file is git-ignored on purpose. Never commit it, especially while the repo is public.*
3. Open `https://alltherex.com/setup.php`, enter the token, tick **starter titles**, click **Create tables**.
4. **Delete `setup.php`** from the server.
5. (Optional) Plesk → Scheduled Tasks → run PHP script `cli\rebuild.php` nightly.

## Tests

`php tests/run.php` (SQLite in memory, no database needed).

## Cover images

Only the image URL is stored (`items.image_url`); the picture is loaded from the source by the visitor's browser.
Movies: Apple iTunes Search. Albums: Deezer. Books: Open Library. No API keys. Titles with no match, or whose image
later breaks, show the generated colour cover. Images are fetched when a title is added, in `setup.php`
("Fetch cover images"), or by `php cli/fetch_images.php` (also usable as a Plesk Scheduled Task). Anyone logged in can
fix a wrong image from the title's page ("Wrong or missing image?").

## Adding titles from public databases

`add.php` searches as you type (logged-in users only, 40 searches/min): movies via Wikipedia film articles, albums via
Deezer, books via Open Library. Picking a result adds it with year, creator and cover; titles already in the catalog
are flagged "Already added". Manual entry remains as a fallback. Code: `lib/lookup.php`, `search_external.php`,
`add_external.php`, `assets/add.js`.
