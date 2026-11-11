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

if (\SaintQuest\PublicGuard::isForbidden($uriPath)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Forbidden\n";
    exit;
}

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
    \SaintQuest\Api::handle($path);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$index = is_file(__DIR__ . '/app.html') ? __DIR__ . '/app.html' : __DIR__ . '/index.html';
if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Saint Quest front end is missing.\n";
    exit;
}

$html = (string) file_get_contents($index);
$inject = '<base href="' . htmlspecialchars($base . '/', ENT_QUOTES) . '" />'
    . '<script>window.SQ_BASE=' . json_encode($base, JSON_UNESCAPED_SLASHES) . ';</script>';
$html = str_contains($html, '<!--SQ_BASE-->')
    ? str_replace('<!--SQ_BASE-->', $inject, $html)
    : preg_replace('/<head([^>]*)>/i', '<head$1>' . $inject, $html, 1);

// Content-hash versions for the scripts and stylesheet. The CDN in front of
// the site caches assets/ for hours, so a deploy would otherwise keep serving
// old modules. An import map versions every relative module import too.
$ver = static fn (string $f): string => substr((string) @md5_file($f), 0, 10);
$map = [];
$jsDir = __DIR__ . '/assets/js';
if (is_dir($jsDir)) {
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jsDir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
            $rel = 'assets/js/' . str_replace('\\', '/', substr($file->getPathname(), strlen($jsDir) + 1));
            $map[$base . '/' . $rel] = $base . '/' . $rel . '?v=' . $ver($file->getPathname());
        }
    }
}
if ($map) {
    ksort($map);
    $importMap = '<script type="importmap">' . json_encode(['imports' => $map], JSON_UNESCAPED_SLASHES) . '</script>';
    $html = preg_replace('/(<link rel="modulepreload")/', $importMap . '$1', $html, 1, $n);
    if (!$n) {
        $html = preg_replace('/<\/head>/i', $importMap . '</head>', $html, 1);
    }
    $html = str_replace('src="assets/js/main.js"', 'src="assets/js/main.js?v=' . $ver($jsDir . '/main.js') . '"', $html);
}
if (is_file(__DIR__ . '/assets/css/sq.css')) {
    $html = str_replace('href="assets/css/sq.css"', 'href="assets/css/sq.css?v=' . $ver(__DIR__ . '/assets/css/sq.css') . '"', $html);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
