# Morse Chess on Plesk (alltherex.com/chess)

The full Morse Chess club — 3D boards, avatar studio, clubs and tournaments, MorseBot,
Morse coins, Elo, timed games and calls — as a static React build served by a PHP
backend. Nothing runs on Node at the server.

* `index.php`, `install.php`, `.htaccess` — front controller, one-page installer, rewrites
* `app/` — PHP code, schema and settings (blocked from the web; `app/.env` holds the database login and is never committed)
* everything else — the built front end (`index.html`, `assets/`, 3D models, textures)

## First install
1. Plesk → Databases → add a MySQL database and user.
2. Open `https://alltherex.com/chess/install.php`, enter them. It creates the tables and saves `app/.env`.
   If PHP may not write that file, the page shows the exact text — create `app/.env` in File Manager and paste it.
3. Delete `install.php` from the server.

Needs PHP 8.1+ with pdo_mysql, served over HTTPS.

## Where the front end comes from
The React source lives in `joejoejoejoe47/morse-chess` (branch `php-port`). The workflow
`.github/workflows/build-chess.yml` builds it and commits the result into this folder
(Actions → "Build Morse Chess site" → Run workflow). The PHP files here are refreshed from the same branch.
