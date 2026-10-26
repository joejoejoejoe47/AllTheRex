<?php
declare(strict_types=1);
/**
 * Recompute every title-to-title similarity from scratch.
 * Rating a title already refreshes that title's similarities instantly; this full rebuild
 * re-syncs everything (users' averages drift as they rate). Run it nightly:
 *   Plesk -> Tools & Settings (or the domain) -> Scheduled Tasks -> "Run a PHP script"
 *   script path: <your site folder>\rex\cli\rebuild.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('REX_ROOT', dirname(__DIR__));
require REX_ROOT . '/lib/db.php';
require REX_ROOT . '/lib/schema.php';
require REX_ROOT . '/lib/helpers.php';
require REX_ROOT . '/lib/catalog.php';
require REX_ROOT . '/lib/recommender.php';

$t = microtime(true);
$n = rec_rebuild_all();
printf("Rebuilt %d similarity pairs in %.1fs\n", $n, microtime(true) - $t);
