<?php
// ---------------------------------------------------------------
// config.php — fill in your real values, then set permissions 600
// and ideally move this file OUTSIDE the public web root and
// require_once it with an absolute path from the other scripts.
// ---------------------------------------------------------------

// --- Database (from your GoDaddy / phpMyAdmin panel) ---
define('DB_HOST', 'a2nlmysql55plsk.secureserver.net');
define('DB_PORT', 3306);
define('DB_NAME', 'urbank');   // e.g. the db shown in phpMyAdmin
define('DB_USER', 'urbank1');
define('DB_PASS', 'u02#3Mk6z');

// --- API Ninjas key (free signup at https://api-ninjas.com) ---
// Covers gold, silver, crude_oil, soybean, corn, lumber.
define('API_NINJAS_KEY', 'hfahr7hAXLkKL89pLhAsCfAWlnadnCnfZNug35AU');
define('API_NINJAS_ENDPOINT', 'https://api.api-ninjas.com/v1/commodityprice');

// --- Simple shared-secret for the manual water-price admin form ---
define('ADMIN_PASSWORD', 'change-me-to-something-strong');

// --- PDO connection helper, reused by every script ---
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
