# vchat — group video meetings (up to 20 people)

Zoom-style meetings: sign in, start a meeting, share the link. **PHP 8.1+ / MySQL backend, React front end.**
Runs on ordinary Plesk PHP hosting; nothing to keep running and no Node on the server.

**Features:** Google sign-in · phone sign-in with an SMS code **plus** an authenticator-app second step
(single-use recovery codes) · shareable meeting links · up to 20 people · camera / mic / screen share ·
chat · host can remove people or end the meeting for everyone.

## Install on Plesk

1. Deploy the repo as usual (this folder becomes `https://yoursite/vchat/`).
2. **Create a new, empty MySQL database** for vchat in Plesk.
3. In Plesk → Files, copy `vchat/config.sample.php` to `vchat/config.php` (never via Git; it is git-ignored) and fill in
   the database details, a long random `session_secret`, and a `setup_token`.
4. Open `https://yoursite/vchat/setup.php`, enter the token, click **Create tables**, then **delete `setup.php`**.
5. Turn on HTTPS for the site (browsers block camera/mic on plain http).
6. Google sign-in: create a *Web* OAuth client in Google Cloud Console, add your site URL under
   *Authorized JavaScript origins*, put the client ID in `google_client_id`.
7. Phone sign-in: set `sms_mode` to `twilio` and fill in the Twilio fields. (`console` only writes codes to the PHP
   error log — development only.)

Phone login = step 1 SMS code, step 2 authenticator-app code. First login walks the user through setting up the
app and shows 8 recovery codes. Google accounts rely on Google's own 2-Step Verification. Google and phone logins are
separate accounts.

## How it works (and what to know before inviting 20 people)

* **Call setup runs over HTTP polling stored in MySQL**, because PHP hosting can't hold WebSocket connections.
  Idle clients poll about every 1.2 s, and every 0.25 s while people are joining/talking in chat. A full 20-person
  meeting is roughly 15–30 light requests/second. If your host limits PHP workers, set `poll_hold_seconds` (e.g. 5)
  only if it allows many concurrent processes (each viewer then holds one while waiting).
* **Video itself is peer-to-peer (mesh)**: everyone sends their video to everyone else, so upload grows with call size.
  Quality drops automatically as people join (≈1.2 Mbps per stream at ≤3 people → ≈90 kbps at 16–20). Small calls look
  great; 15–20 people works on good connections but is not Zoom quality. For that you need a media server (SFU such as
  LiveKit); the media code is isolated in `frontend/src/room.js`.
* **Add a TURN server** (`turn_urls` + `turn_secret`, e.g. coturn on a small VPS — it needs UDP ports, so it can't run
  inside shared hosting). Without it roughly 1 in 8 users behind strict networks can't connect.
* Not included: recording, waiting room, breakout rooms, virtual backgrounds.

## Changing the front end

The browser app is React in `frontend/src`. The built bundle is committed in `assets/` so the server needs no build step.
After editing the source, on any computer with Node:

```bash
cd vchat/frontend && npm install && npm run build   # rewrites ../assets/app.js and app.css; commit them
```

## Layout

```
index.php  api.php  setup.php   entry page (CSP headers), JSON API, one-time table installer
src/                            PHP: core (db, tokens, rate limits), auth (Google/phone/2-step), rooms, store, schema
frontend/src/                   React app + room.js (WebRTC mesh + polling transport)
assets/                         built bundle (committed)
tests/                          see below
```

## Tests

```bash
php tests/unit.php      # TOTP vectors, Google token checks, signed tokens
php tests/api.php       # starts php -S on a throwaway SQLite DB: login + 2-step, recovery codes, lockout,
                        # 20-person cap, signalling privacy, kick/ban, replace, stale cleanup, end-for-all
node tests/browser.mjs  # optional, needs Playwright: real Chromium peers with fake cameras (N=8 for 8 people)
```

The automated tests use SQLite; the MySQL statements are plain portable SQL (the only dialect differences are in
`src/schema.php`), but run `setup.php` and a test call on your server to confirm.
