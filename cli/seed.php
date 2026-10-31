<?php
declare(strict_types=1);
/** Import the ranked title lists in data/*.txt:  php cli/seed.php   (safe to repeat) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('REX_ROOT', dirname(__DIR__));
foreach (['db', 'schema', 'helpers', 'images', 'catalog', 'seed'] as $f) { require REX_ROOT . "/lib/$f.php"; }
mb_internal_encoding('UTF-8');
rex_install(db());
$t = microtime(true);
$s = rex_seed_bulk();
printf("Added %d, ranked %d existing, skipped %d, bad lines %d (%.1fs)\n", $s['added'], $s['ranked'], $s['skipped'], $s['bad'], microtime(true) - $t);
