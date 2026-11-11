# Saint Quest

A kid-friendly saint-collecting game served at https://alltherex.com/saintquest/.

* Username + password accounts (no email). Every new player gets St. Francis.
* Home: the village picture with your highest-rated saint as a real three.js
  3D model on the path. The signpost arrow switches between your saints.
* Walking: hold W to walk (real video: generated first-person clips,
  motion-interpolated to 60 fps, with a reversed copy so S plays backward
  smoothly; `assets/walk/v2/`, `walk.json`), A/D to step aside. At the
  crossroads A turns into Market Street and D into Park Square (each its own
  video; walking back out returns to the crossroads). The saint keeps a
  constant size. The end of the main path (the cathedral doors) opens into a
  3D cathedral interior (no pews, `church.js`, swappable for a model later).
  E helps a nearby villager on the home picture.
* People on the paths (`street.js`): 3D KayKit townsfolk placed in the video
  with a road perspective. Walking up to them plays their recorded voice
  (`assets/audio/voice/`, Piper TTS libritts_r, CC BY 4.0) with a caption and
  a moving mouth, and shows a button for their case: Rosie is lost, Old
  Martha is lonely, Simon the beggar ("Give 50 virtue points to the poor";
  the next Store visit pays 100 back: "Give, and it shall be given to you"),
  Jakob wants to steal an apple, Ezra is sick, Tom and Leo are fighting (your
  saint shouts "Stop!"), Mia has no one to play with. Townsfolk gossip gives
  three clues for the Holy Mystery (the silent bell); with all clues and three
  people helped, Saint Clare's lantern can be lit in the cathedral. Cases are
  server-side (`Cases.php`, `sq_cases`, `sq_blessings`): kindness once a day,
  clues and the mystery once ever.
* Sound (`audio.js`): the home theme (the walk video's music, extended into a
  48 s loop) plays at home; one mute button in the top bar.
* Virtue points (wallet), level + virtue meter (lifetime virtue), Good Deeds
  (help the three villagers once a day), podium leaderboard.
* Store (buy saints with virtue), Trade (golden plate: set a price and a
  "how this saint helped me" story; public listings, atomic purchase),
  search bar (store saints, trades, players).
* Saint pages with comments: text plus pasted or uploaded pictures. Pictures
  are sniffed by magic bytes, size/pixel limited, re-encoded by GD (metadata
  stripped) and served with `nosniff` + sandbox CSP. Report (auto-hide at 3)
  and owner hide; owners get notifications.
* Add: camera (getUserMedia + jsQR), photo-of-card and typed-code fallbacks.
  Printable cards carry an unguessable `SQ-XXXX-XXXX-XXXX` code (Crockford
  base32 + check character) and a QR. If a different account adds a card
  that is already registered, the owner is asked "Did you trade this card?":
  Yes moves the saint; No asks for a photo and the saint stays frozen
  ("disputed") until one side gives it up. Scans are rate limited; re-scans
  by the owner do nothing; a withdrawn claimant is blocked for 24 h.
* Holy Battle: three 3D mini-games (Arena Duel, Light-Orb Race, Chapel
  Defense). The server issues a seeded, token-protected session and checks
  minimum time and maximum score before paying virtue and changing rating.

## Layout

```
saintquest/
  index.php, app.html, .htaccess, web.config    front controller + page
  assets/   css, js (ES modules), models (glb), thumbs, data/saints.json, vendor
  app/src   PHP (namespace SaintQuest)          app/sql  MySQL + SQLite schema
  tests/    PHP unit tests, health + browser tests, asset tools
```

Production uses the Morse Chess MySQL database (own `sq_*` tables). The deploy
workflow copies only the `DB_*` lines of `chess/app/.env` into
`saintquest/app/.env` if that file does not exist yet. `SQ_DB_*` env names
also work. Locally: `DB_DRIVER=sqlite DB_SQLITE=/tmp/sq.sqlite`.

## Run locally

```
DB_DRIVER=sqlite DB_SQLITE=/tmp/sq.sqlite php -S 127.0.0.1:8851 -t saintquest saintquest/index.php
```

## Tests

```
php saintquest/tests/run.php                    # unit tests (needs gd, pdo_sqlite)
PHP_BIN=php node saintquest/tests/health.test.mjs
npm i --no-save playwright-core@1.65.0
PHP_BIN=php node saintquest/tests/browser/saintquest.e2e.mjs   # real pointer e2e
```

## Credits and licenses

* 3D saints: KayKit Adventurers Character Pack 2.0 by Kay Lousberg
  (www.kaylousberg.com), CC0 1.0 — recoloured, with a halo added. See
  `assets/models/LICENSE-KayKit.txt`. Animations: KayKit Rig Medium (same pack, CC0).
* three.js r186 (MIT), jsQR 1.4.0 (Apache-2.0), qrcode-generator 2.0.4 (MIT).
* Fonts: Fredoka and Nunito (SIL OFL) via Google Fonts.
* Village background: concept art created for Saint Quest (AI-generated).
* Cathedral interior: primitives + canvas textures made for Saint Quest.
* Walk-up-the-path video: generated for Saint Quest (AI-generated), stored as WebP frames.
