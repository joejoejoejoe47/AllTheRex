# Morse Chess for Plesk

This folder is what Plesk serves at `alltherex.com/chess`. The Node app cannot run there.

1. In Plesk, create a MySQL database and a user that can use it.
2. Pull this repository so `chess/` is on the site.
3. Open `https://alltherex.com/chess/install.php` once and enter the database.
4. Settings are saved in `morse-private/config.php`, one folder above the site, so a later pull does not wipe them.

If that folder cannot be written, set these in Plesk instead:

```
MORSE_DB_HOST=localhost
MORSE_DB_NAME=the database name
MORSE_DB_USER=the database user
MORSE_DB_PASS=the database password
```

PHP 8 and `pdo_mysql` are required.

Accounts, Morse coins, board colors, MorseBot, and friend games by code work here. The 3D colosseum and avatar studio stay on the Node app.
