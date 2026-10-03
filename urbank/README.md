# UMU Basket Tracker — deployment guide

Files in this bundle:

- `schema.sql` — creates the 3 tables and seeds the basket (gold, silver, water, crude oil, soybeans, lumber, corn) with the exact quantities you specified.
- `config.php` — **edit this first.** DB credentials + API key + admin password.
- `functions.php` — shared helpers (API call + unit conversion).
- `fetch_prices.php` — the worker. Pulls live prices and writes a new row to `commodity_prices` and `umu_value_history`. Run this on a schedule.
- `index.php` — the public dashboard at `alltherex.com/urbank/`.
- `admin_water.php` — small password form to update water's manual price (no market exists for potable water, so it can't be pulled from an API).

## 1. Database

1. In phpMyAdmin (linked from your GoDaddy panel), select or create your database.
2. Open the **SQL** tab, paste the contents of `schema.sql`, and run it.
3. Note the exact database name, username, and password GoDaddy assigned — you'll need them in step 3. The host is already `a2nlmysql55plsk.secureserver.net`, port `3306`.

## 2. Get an API key

Sign up free at **https://api-ninjas.com** (their Commodity Price API covers gold, silver, crude oil, corn, soybeans, and lumber in one endpoint, with the exact units — troy ounces, barrels, bushels, board feet — needed here). Copy your API key.

Free-tier data is ~15-minutes delayed rather than tick-by-tick live; that's normal and fine for a basket meant to move slowly.

## 3. Configure

Edit `config.php`:
```php
define('DB_NAME', '...');
define('DB_USER', '...');
define('DB_PASS', '...');
define('API_NINJAS_KEY', '...');
define('ADMIN_PASSWORD', '...');
```

## 4. Upload

Upload all files to `public_html/urbank/` on your host (FTP or the File Manager in cPanel). That gives you `alltherex.com/urbank/index.php` as the dashboard.

**Security note:** `config.php` holds real credentials. At minimum, set its file permission to 600. Better: move `config.php` one directory above `public_html` (outside the web root) and change the two `require_once __DIR__ . '/config.php'` lines in the other files to point to that path — then it can never be served directly even if PHP execution is misconfigured.

## 5. Schedule the price fetcher

In cPanel → **Cron Jobs**, add a job (every 30 or 60 minutes is plenty for a slow-moving basket):

```
*/30 * * * * php /home/YOURUSER/public_html/urbank/fetch_prices.php >> /home/YOURUSER/urbank_cron.log 2>&1
```

If your plan doesn't expose CLI PHP, you can instead hit it over HTTP with a cron-capable `wget`/`curl` job — in that case add `define('CRON_SECRET', 'some-long-random-string');` to `config.php` and call:
```
wget -q -O /dev/null "https://alltherex.com/urbank/fetch_prices.php?key=some-long-random-string"
```

## 6. First run

Trigger `fetch_prices.php` once manually (visit the URL, or run it via SSH) to populate the first row. Then load `index.php` — you should see `1 UMU = $X.XXXX` and a chart that fills in as more cron runs accumulate.

## Notes on the basket math

- Gold and silver are quoted by the API in **troy ounces**; the code divides by 31.1034768 to get price-per-gram, matching your 0.001g / 0.01g basket units.
- Corn and soybeans are sometimes quoted in **USX (cents)** rather than USD by grain markets; `price_in_cents` in the `commodities` table flags that so the conversion is applied automatically.
- Crude oil and lumber map directly to barrel and board-foot, matching your basket units — no conversion needed.
- Water has **no exchange-traded price** anywhere; it's stored as a manually-edited value in the same table/schema so it behaves identically everywhere else in the app. Update it via `admin_water.php`, or swap in a real regional utility-rate feed later if you find one.
- Every fetch also writes a snapshot to `umu_value_history` so you get the USD-value time series shown in the chart, without needing to reconstruct it from raw commodity prices later.
