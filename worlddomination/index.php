<?php

declare(strict_types=1);

/**
 * Front controller for Plesk / shared hosting.
 *   <base>/api/*    -> PHP JSON API
 *   everything else -> the game page
 */

$appDir = is_dir(__DIR__ . '/app/src') ? __DIR__ . '/app' : dirname(__DIR__);
require $appDir . '/src/bootstrap.php';

$uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// The built-in server sends every request here. Serve real static files from the
// original URL so a request like /assets/data/countries.json is not rewritten.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $uriPath;
    if ($uriPath !== '/' && is_file($file) && !str_ends_with(strtolower($file), '.php')) {
        return false;
    }
}

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
if (PHP_SAPI === 'cli-server' && !str_ends_with($scriptName, 'index.php')) {
    $base = '';
} else {
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
}
$path = $uriPath;
if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
    $path = substr($path, strlen($base)) ?: '/';
}

if ($path === '/api' || str_starts_with($path, '/api/')) {
    \WorldDomination\Api::handle($path);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$index = is_file(__DIR__ . '/app.html') ? __DIR__ . '/app.html' : __DIR__ . '/index.html';
if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "World Domination front end is missing.\n";
    exit;
}

$html = (string) file_get_contents($index);
$inject = '<base href="' . htmlspecialchars($base . '/', ENT_QUOTES) . '" />'
    . '<script>window.WD_BASE=' . json_encode($base, JSON_UNESCAPED_SLASHES) . ';</script>';
$html = str_contains($html, '<!--WD_BASE-->')
    ? str_replace('<!--WD_BASE-->', $inject, $html)
    : preg_replace('/<head([^>]*)>/i', '<head$1>' . $inject, $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
