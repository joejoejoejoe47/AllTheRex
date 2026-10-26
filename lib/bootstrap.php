<?php
declare(strict_types=1);

if (!defined('REX_ROOT')) {
    define('REX_ROOT', dirname(__DIR__));
}

require __DIR__ . '/db.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/images.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/recommender.php';
require __DIR__ . '/view.php';

mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    rex_session_start();
}
