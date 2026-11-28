# vchat — group video meetings (up to 20 people)

A small Zoom-style app: sign in, start a meeting, share the link, talk. Lives in this folder and runs
separately from the PHP site (it needs a long-running Node process for live signalling).

**Features:** Google sign-in · phone sign-in with SMS code **plus** an authenticator-app second step
(with single-use recovery codes) · meetings with shareable links · up to 20 people per meeting ·
camera / mic / screen share · in-meeting chat · host can remove people or end the meeting for everyone.

**Zero npm dependencies** (Node 20+): the HTTP server, WebSocket server, TOTP and Google token
verification are all included. Nothing to `npm install`.

## Run it

```bash
cd vchat
cp .env.sample .env      # then edit
node server.js           # http://localhost:3000
```

Camera/mic only work on `https://` pages (or `localhost`). In production put it behind HTTPS
(Plesk/nginx reverse proxy with WebSocket upgrade for `/ws`), set `NODE_ENV=production`, a long
`SESSION_SECRET`, and `TRUST_PROXY=1`.

## Sign-in setup

* **Google:** create a Web OAuth client in Google Cloud Console, add your site URL under
  *Authorized JavaScript origins*, put the client ID in `GOOGLE_CLIENT_ID`. Google's own 2-Step
  Verification protects those accounts.
* **Phone + 2-step:** step 1 is a 6-digit SMS code (5 min expiry, rate limited). Step 2 is an authenticator
  app code (TOTP). First login walks the user through enrolling the app and shows 8 recovery codes.
  Set `SMS_MODE=twilio` + Twilio credentials for real texts; `SMS_MODE=console` prints codes in the
  server log for development.
* Google and phone logins are separate accounts (no linking yet).

## Your database

All storage goes through `db/index.js`. It uses an in-memory store now (data is lost on restart).
Implement the methods listed in [`db/README.md`](db/README.md) for your database, then change one import.
[`db/schema.sql`](db/schema.sql) is a ready MySQL schema.

## Important limits (read before inviting 20 people)

* **Media is peer-to-peer mesh.** Each person sends their video to every other person, so upload bandwidth
  grows with the call size. The app lowers video quality automatically as people join (1.2 Mbps each at ≤3
  people down to ~90 kbps at 16–20). Small calls look great; 15–20 person calls are workable on good
  connections but not Zoom quality. For that, swap the media layer for an SFU (LiveKit, mediasoup);
  auth, meetings, chat and UI can stay. The client media code is isolated in `public/meeting.js`.
* **Set up TURN** (`TURN_URLS` + `TURN_SECRET`, e.g. coturn). Without it, people behind strict
  firewalls/NATs (roughly 1 in 8) can't connect.
* No recording, waiting room, breakout rooms, or virtual backgrounds.
* Rate-limit counters are in memory (single process).

## Tests

```bash
node test/unit.test.js          # TOTP vectors, Google token checks, session tokens
node test/integration.test.js   # starts the server: login + 2-step, recovery codes, 20-person cap, kick, origin checks
node test/browser.test.mjs      # optional, needs Playwright: real Chromium peers with fake cameras (set N=8 for the participant count)
```

## Layout

```
server.js         HTTP API, static files, WebSocket upgrade
lib/              config, sessions, auth (Google/phone/2FA), TOTP, rooms (signalling+chat), mini WebSocket
db/               storage adapter (replace memory.js)
public/           the web app (no build step)
```
