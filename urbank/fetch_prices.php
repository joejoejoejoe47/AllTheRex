<?php
/**
 * fetch_prices.php
 * -----------------
 * Run on a schedule (cPanel > Cron Jobs). With 11 basket items pulled via 10
 * Alpha Vantage calls (GOLD_SILVER_SPOT covers gold+silver in one call), and a
 * free-tier cap of 25 requests/day, run this AT MOST TWICE A DAY.
 *
 * CLI:  php /home/USER/urbank/fetch_prices.php
 * HTTP: https://alltherex.com/urbank/fetch_prices.php?key=YOUR_CRON_SECRET
 *       (only if CRON_SECRET is defined in config.php)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (php_sapi_name() !== 'cli' && defined('CRON_SECRET')) {
    if (($_GET['key'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$pdo = get_db();
$commodities = $pdo->query('SELECT * FROM commodities WHERE active = 1 ORDER BY sort_order')->fetchAll();

$insertPrice = $pdo->prepare(
    'INSERT INTO commodity_prices (commodity_id, price_per_basket_unit, raw_price, raw_unit, price_date, source)
     VALUES (:commodity_id, :price, :raw_price, :raw_unit, :price_date, :source)'
);

$umuValue = 0.0;
$breakdown = [];
$errors = [];
$goldSilverCache = null; // fetched once, reused for both gold and silver rows

foreach ($commodities as $c) {
    try {
        if ($c['av_function'] === 'GOLD_SILVER_SPOT') {
            if ($goldSilverCache === null) {
                $goldSilverCache = fetch_av_gold_silver();
            }
            $metal = $c['av_field']; // 'gold' or 'silver'
            $point = $goldSilverCache[$metal];
        } else {
            $point = fetch_av_commodity($c['av_function'], $c['av_interval']);
        }

        $pricePerUnit = normalize_av_value($point['value'], $point['unit']);

        $insertPrice->execute([
            ':commodity_id' => $c['id'],
            ':price'        => $pricePerUnit,
            ':raw_price'    => $point['value'],
            ':raw_unit'     => $point['unit'],
            ':price_date'   => $point['date'],
            ':source'       => 'alpha_vantage',
        ]);

        $contribution = $pricePerUnit * (float) $c['basket_quantity'];
        $umuValue += $contribution;
        $breakdown[$c['slug']] = [
            'display_name'     => $c['display_name'],
            'quantity'         => (float) $c['basket_quantity'],
            'unit'             => $c['basket_unit'],
            'price_per_unit'   => round($pricePerUnit, 6),
            'contribution_usd' => round($contribution, 6),
        ];

        // Be polite to the API between calls (skip the extra wait after the last one).
        usleep(1200000); // 1.2s
    } catch (Throwable $e) {
        $errors[] = "{$c['slug']}: {$e->getMessage()}";
        // Fall back to the most recent stored price so one bad call doesn't zero out the basket.
        $last = $pdo->prepare(
            'SELECT price_per_basket_unit FROM commodity_prices
             WHERE commodity_id = :id ORDER BY fetched_at DESC LIMIT 1'
        );
        $last->execute([':id' => $c['id']]);
        $row = $last->fetch();
        if ($row) {
            $umuValue += (float) $row['price_per_basket_unit'] * (float) $c['basket_quantity'];
        }
    }
}

$insertUmu = $pdo->prepare(
    'INSERT INTO umu_value_history (value_usd, detail_json) VALUES (:value, :detail)'
);
$insertUmu->execute([
    ':value'  => $umuValue,
    ':detail' => json_encode($breakdown),
]);

echo "UMU value: \$" . number_format($umuValue, 4) . "\n";
if ($errors) {
    echo "Errors:\n" . implode("\n", $errors) . "\n";
}