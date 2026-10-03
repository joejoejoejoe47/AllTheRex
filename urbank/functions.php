<?php
require_once __DIR__ . '/config.php';

/**
 * Low-level GET against Alpha Vantage.
 */
function av_request(array $params): array {
    $params['apikey'] = ALPHA_VANTAGE_KEY;
    $url = ALPHA_VANTAGE_ENDPOINT . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("cURL error calling Alpha Vantage ({$params['function']}): {$err}");
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Non-JSON response from Alpha Vantage ({$params['function']}): {$body}");
    }
    if (isset($data['Note']) || isset($data['Information'])) {
        // Rate limit / entitlement messages come back as 200 OK with these keys instead of an HTTP error.
        $msg = $data['Note'] ?? $data['Information'];
        throw new RuntimeException("Alpha Vantage rejected the request ({$params['function']}): {$msg}");
    }
    return $data;
}

/**
 * Standard commodities endpoint: WTI, NATURAL_GAS, COPPER, ALUMINUM,
 * WHEAT, CORN, COTTON, SUGAR, COFFEE. Well-established, stable response shape:
 * { "name": ..., "interval": ..., "unit": ..., "data": [ {"date":..., "value":...}, ... ] }
 */
function fetch_av_commodity(string $function, string $interval = 'daily'): array {
    $data = av_request(['function' => $function, 'interval' => $interval]);

    if (empty($data['data']) || !is_array($data['data'])) {
        throw new RuntimeException("Unexpected response shape for {$function}: " . json_encode($data));
    }
    $latest = $data['data'][0];
    return [
        'value' => (float) $latest['value'],
        'unit'  => $data['unit'] ?? '',
        'date'  => $latest['date'] ?? null,
    ];
}

/**
 * GOLD_SILVER_SPOT — newly launched at the time this was written, so the exact
 * response shape wasn't verified against a live call. This tries a few plausible
 * shapes defensively. If it throws "Unrecognized GOLD_SILVER_SPOT response shape",
 * run the curl command from the README, paste the raw JSON back, and the parsing
 * below can be corrected to match exactly.
 */
function fetch_av_gold_silver(): array {
    $data = av_request(['function' => 'GOLD_SILVER_SPOT']);

    $result = [];

    // Shape A: top-level "gold" / "silver" objects, e.g. {"gold": {"price":..,"unit":..,"date":..}, "silver": {...}}
    foreach (['gold', 'silver'] as $metal) {
        $key = null;
        foreach ([$metal, ucfirst($metal), strtoupper($metal)] as $candidate) {
            if (isset($data[$candidate])) { $key = $candidate; break; }
        }
        if ($key !== null) {
            $entry = $data[$key];
            $result[$metal] = [
                'value' => (float) ($entry['price'] ?? $entry['value'] ?? 0),
                'unit'  => $entry['unit'] ?? 'USD per troy ounce',
                'date'  => $entry['date'] ?? $entry['updated'] ?? null,
            ];
        }
    }

    // Shape B: a "data" array with a single latest row containing both fields,
    // e.g. {"data": [{"date":.., "gold": .., "silver": ..}]}
    if (empty($result) && !empty($data['data'][0])) {
        $row = $data['data'][0];
        if (isset($row['gold']) && isset($row['silver'])) {
            $result['gold']   = ['value' => (float) $row['gold'],   'unit' => 'USD per troy ounce', 'date' => $row['date'] ?? null];
            $result['silver'] = ['value' => (float) $row['silver'], 'unit' => 'USD per troy ounce', 'date' => $row['date'] ?? null];
        }
    }

    if (empty($result['gold']) || empty($result['silver'])) {
        throw new RuntimeException(
            "Unrecognized GOLD_SILVER_SPOT response shape — please run the curl test in README.md " .
            "and share the raw JSON so parsing can be corrected. Raw response: " . json_encode($data)
        );
    }

    return $result; // ['gold' => ['value','unit','date'], 'silver' => [...]]
}

/**
 * Some Alpha Vantage commodity values are quoted in cents rather than dollars
 * (unit string contains "cent"). Normalize to USD per basket_unit.
 */
function normalize_av_value(float $rawValue, string $unit): float {
    if (stripos($unit, 'cent') !== false) {
        return $rawValue / 100.0;
    }
    return $rawValue;
}