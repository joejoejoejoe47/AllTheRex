# World Domination

A separate game at `/worlddomination/`, not part of Morse Chess.

Create a real account, look around a flat Earth under a blue sky, click a
country, press Play, then walk your explorer. Leaving your land draws a trail;
looping back claims everything inside, including enclosed bot land.

Opponents in this first release are clearly labeled **bots**. There is no live
multiplayer. The podium on the right shows the real top three scores from
finished matches (champion on the tall center stand, then 2nd and 3rd). It
starts empty until someone plays.

Country outlines are bundled from Natural Earth 1:110m Admin 0 Countries
(public domain). See `assets/data/ATTRIBUTION.md`.

## Local play

From this folder, with PHP 8.3+ and pdo_sqlite:

```
cp app/.env.example app/.env
```

Set these in `app/.env`:

```
DB_DRIVER=sqlite
DB_SQLITE=storage/worlddom.sqlite
APP_DEBUG=1
```

Then:

```
php -S 127.0.0.1:8847 -t . index.php
```

Open http://127.0.0.1:8847/

Accounts are stored in the SQLite file, not in the browser.

## Tests

```
php tests/run.php
node tests/capture.test.mjs
```

## Production (Plesk)

1. Merge the pull request. The `deploy-worlddomination` workflow uploads only
   `worlddomination/` to `worlddomination/` and `alltherex/worlddomination/`
   via the existing Plesk FTP secrets.
2. Create a MySQL database (or reuse one — tables are prefixed `wd_` and do
   not touch Morse Chess tables).
3. Open `https://alltherex.com/worlddomination/install.php`, enter the
   database details, then **delete `install.php`**.
4. Open `https://alltherex.com/worlddomination/` and create an account.

PHP needs PDO with the MySQL driver (already used by Morse Chess on this host).

## Controls

- World: drag, scroll to zoom, WASD or arrows to pan, click a country, then Play
- Country: WASD or arrows to walk; on a phone, use the left joystick
- Leave country records the match as a retreat
- Crossing your own exposed trail, or an enemy hitting that trail, knocks you out
- Claim 50% of the country to win, or hold the most land when time runs out
