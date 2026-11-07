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

There is no public installer. `install.php` is not shipped.

The game uses the same documented Morse Chess database contract:
`DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
and optional `DB_SOCKET`. `WD_DB_*` aliases are also accepted. Only those
keys are ever read from a sibling `chess/app/.env`; API tokens and other
Morse secrets are ignored. Tables are prefixed `wd_` and are created with
`CREATE TABLE IF NOT EXISTS` on a healthy connection. Morse Chess tables
are never modified.

Hosting decision (this site layout: `https://alltherex.com/worlddomination/`
next to `/chess/`):

1. Preferred, no new secrets: at runtime, reuse the existing Morse
   `chess/app/.env` `DB_*` keys (same MySQL). The FTP deploy workflow only
   has `PLESK_FTP_HOST` / `PLESK_FTP_USER` / `PLESK_FTP_PASSWORD` and does
   not write database credentials into the public tree.
2. Equivalent: set the same `DB_*` or `WD_DB_*` values in Plesk PHP
   environment / additional directives. Needed only if `open_basedir` or a
   separate docroot prevents reading the sibling Morse env file.
3. Do not assume `pdo_sqlite` on Plesk. Production Morse already requires
   PHP 8.1+ with `pdo_mysql`. SQLite is for local play and tests.
4. Do not place raw credentials in a publicly served file. A privately
   created `worlddomination/app/.env` is a last resort and is blocked by
   `app/.htaccess`, front-controller rules, and `PublicGuard`.

After merge, `deploy-worlddomination` uploads only `worlddomination/` to
`worlddomination/` and `alltherex/worlddomination/`. Smoke fails unless
`/api/health` returns HTTP 200 with `ok: true` and `db: true`, and unless
`install.php`, `app/.env`, and other private paths are not publicly
served. The first healthy request creates `wd_*` tables.

User-only prerequisite if the first deploy health check is 503: set the
existing Morse `DB_*` values (or `WD_DB_*`) in Plesk PHP settings, then
re-run the World Domination deploy workflow. No public form, and no new
database, if the Morse MySQL database is shared.

CLI operators with SSH can run `php app/bin/setup.php` to verify the
connection and create tables. It refuses HTTP and will not write an env
file.

## Controls

- World: drag, scroll to zoom, WASD or arrows to pan, click a country, then Play
- Country: WASD or arrows to walk; on a phone, use the left joystick
- Leave country records the match as a retreat
- Crossing your own exposed trail, or an enemy hitting that trail, knocks you out
- Claim 50% of the country to win. When time runs out, verified territory is
  recorded; leading without 50% is not a domination win.
