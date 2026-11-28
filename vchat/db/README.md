# Plugging in your database

The app talks to storage only through the object exported from `db/index.js`.
It currently uses `db/memory.js` (data is lost on restart). To use a real database:

1. Copy `memory.js` to e.g. `mysql.js` and implement the methods below with your driver.
2. Change `db/index.js` to import your file. Nothing else in the app needs to change.
3. `schema.sql` has a matching MySQL schema.

All methods may be `async`. Return plain objects (or `null` when not found).

| Method | Purpose |
|---|---|
| `findUserById(id)` | session lookup |
| `findUserByGoogleSub(sub)` | Google login |
| `findUserByPhone(e164)` | phone login |
| `createUser(fields)` | returns the new user; generate `id` (UUID) and defaults as in `memory.js` |
| `updateUser(id, patch)` | returns the updated user |
| `putOtp(phone, {hash, expiresAt, attempts})` / `getOtp(phone)` / `deleteOtp(phone)` | one pending SMS code per phone |
| `createMeeting({code, hostId, title})` / `getMeeting(code)` / `endMeeting(code)` | meetings |

User fields: `id, googleSub, email, phone, name, picture, totpSecret, totpEnabled, totpLastStep, recoveryHashes[], createdAt`.
Meeting fields: `code, hostId, title, ended, createdAt`.

Notes: OTP codes and recovery codes are stored only as hashes. Consider encrypting `totpSecret` at rest.
Rate-limit counters live in memory (`lib/limiter.js`); if you run several server processes, move them to a shared store.
