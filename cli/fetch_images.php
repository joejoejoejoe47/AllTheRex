<?php
declare(strict_types=1);
/** Fetch cover images for every title that doesn't have one yet:  php cli/fetch_images.php  (or a Plesk Scheduled Task) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('REX_ROOT', dirname(__DIR__));
foreach (['db', 'schema', 'helpers', 'images'] as $f) { require REX_ROOT . "/lib/$f.php"; }
[$done, $left] = rex_backfill_images(3000.0);
echo "Looked up $done titles; $left left.\n";
$have = (int)q1('SELECT COUNT(*) AS c FROM items WHERE image_url IS NOT NULL')['c'];
$all  = (int)q1('SELECT COUNT(*) AS c FROM items')['c'];
echo "$have of $all titles have an image.\n";
