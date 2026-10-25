# AllTheRex recommender (`/rex/`)

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

1. **Deploy** this repo to the site (Plesk → Git → Pull/Deploy). The app lives in the `rex` folder.
2. **Create `rex/config.php`** in Plesk → Files, by copying `config.sample.php`, and fill in your
   database name, user and password. Set `setup_token` to a long random string.
   *This file is git-ignored on purpose. Never commit it, especially while the repo is public.*
3. Open `https://alltherex.com/rex/setup.php`, enter the token, tick **starter titles**, click **Create tables**.
4. **Delete `rex/setup.php`** from the server.
5. (Optional) Plesk → Scheduled Tasks → run PHP script `rex\cli\rebuild.php` nightly.

## Moving it to the site root later

The site root currently holds the World Builder (`index.html`) and a folder named `index.php`.
When you're ready: move the contents of `rex/` up to the root (keeping `config.php`), retire or rename the
old root files, and keep `web.config`'s `defaultDocument` entry. All links are relative to the app's own folder, so nothing
else needs editing.

## Tests

`php rex/tests/run.php` (SQLite in memory, no database needed).
