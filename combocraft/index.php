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

if (\ComboCraft\PublicGuard::isForbidden($uriPath)) {
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
    \ComboCraft\Api::handle($path);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$index = is_file(__DIR__ . '/app.html') ? __DIR__ . '/app.html' : __DIR__ . '/index.html';
if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ComboCraft front end is missing.\n";
    exit;
}

$html = (string) file_get_contents($index);
$inject = '<base href="' . htmlspecialchars($base . '/', ENT_QUOTES) . '" />'
    . '<script>window.CC_BASE=' . json_encode($base, JSON_UNESCAPED_SLASHES) . ';</script>';
$html = str_contains($html, '<!--CC_BASE-->')
    ? str_replace('<!--CC_BASE-->', $inject, $html)
    : preg_replace('/<head([^>]*)>/i', '<head$1>' . $inject, $html, 1);

// Share links (/e/123): give link previews the real combo.
$og = ['title' => 'ComboCraft — What if you could combine anything?', 'desc' => 'Mix any two ideas and invent something new. Every first discovery is yours forever.'];
if (preg_match('#^/e/(\d+)#', $path, $m)) {
    try {
        if (\ComboCraft\Config::dbConfigured()) {
            $e = \ComboCraft\Game::element((int) $m[1]);
            if ($e !== null) {
                $v = \ComboCraft\Game::view($e, true);
                $recipe = $v['recipe'] ? $v['recipe'][0]['name'] . ' + ' . $v['recipe'][1]['name'] . ' = ' : '';
                $og['title'] = $recipe . $v['emoji'] . ' ' . $v['name'] . ' | ComboCraft';
                $og['desc'] = ($v['tagline'] ?: 'A ComboCraft discovery.') . ($v['discoveredBy'] ? ' First discovered by ' . $v['discoveredBy'] . '.' : '');
            }
        }
    } catch (\Throwable $ex) {
        error_log('[combocraft og] ' . $ex->getMessage());
    }
}
$scheme = \ComboCraft\Http::isHttps() ? 'https' : 'http';
$origin = $scheme . '://' . \ComboCraft\Http::host();
$meta = '<title>' . htmlspecialchars($og['title'], ENT_QUOTES) . '</title>'
    . '<meta name="description" content="' . htmlspecialchars($og['desc'], ENT_QUOTES) . '" />'
    . '<meta property="og:title" content="' . htmlspecialchars($og['title'], ENT_QUOTES) . '" />'
    . '<meta property="og:description" content="' . htmlspecialchars($og['desc'], ENT_QUOTES) . '" />'
    . '<meta property="og:type" content="website" />'
    . '<meta property="og:image" content="' . htmlspecialchars($origin . $base . '/assets/og.png', ENT_QUOTES) . '" />'
    . '<meta name="twitter:card" content="summary_large_image" />';
$html = str_replace('<!--CC_META-->', $meta, $html);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
