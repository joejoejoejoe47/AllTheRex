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

if (str_starts_with($path, '/og/')) {
    try {
        if (\ComboCraft\Config::dbConfigured()) {
            \ComboCraft\Db::ensureSchema();
            \ComboCraft\Game::ensureBase();
        }
    } catch (\Throwable $e) {
        error_log('[combocraft og-setup] ' . $e->getMessage());
    }
    \ComboCraft\OgImage::handle($path);
}

if (isset($_GET['r'])) {
    \ComboCraft\Referral::remember((string) $_GET['r']);
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

// Share links (/e/123) and daily: give link previews the real combo / challenge.
$og = [
    'title' => 'ComboCraft — What if you could combine anything?',
    'desc' => 'Mix any two ideas and invent something new. Every first discovery is yours forever. Daily challenges, speedruns and First Discoveries to share.',
    'image' => null,
    'url' => null,
];
$scheme = \ComboCraft\Http::isHttps() ? 'https' : 'http';
$origin = $scheme . '://' . \ComboCraft\Http::host();
$abs = $origin . $base;
if (preg_match('#^/e/(\d+)#', $path, $m)) {
    try {
        if (\ComboCraft\Config::dbConfigured()) {
            $e = \ComboCraft\Game::element((int) $m[1]);
            if ($e !== null) {
                $v = \ComboCraft\Game::view($e, true);
                $recipe = $v['recipe'] ? $v['recipe'][0]['name'] . ' + ' . $v['recipe'][1]['name'] . ' = ' : '';
                $og['title'] = $recipe . $v['emoji'] . ' ' . $v['name'] . ' | ComboCraft';
                $og['desc'] = ($v['tagline'] ?: 'A ComboCraft discovery.') . ($v['discoveredBy'] ? ' First discovered by ' . $v['discoveredBy'] . '.' : '');
                $og['image'] = $abs . '/og/e/' . (int) $m[1] . '.png';
                $og['url'] = $abs . '/e/' . (int) $m[1];
            }
        }
    } catch (\Throwable $ex) {
        error_log('[combocraft og] ' . $ex->getMessage());
    }
}
if ($path === '/daily' || str_starts_with($path, '/daily')) {
    $og['title'] = 'ComboCraft Daily Challenge';
    $og['desc'] = 'Today\'s target is the same for everyone. How few combos can you do it in?';
    $og['image'] = $abs . '/og/daily.png';
    $og['url'] = $abs . '/daily';
    try {
        if (\ComboCraft\Config::dbConfigured()) {
            $d = \ComboCraft\Daily::target(\ComboCraft\Daily::today());
            if ($d) {
                $og['title'] = 'ComboCraft Daily: make ' . $d['emoji'] . ' ' . $d['name'];
                $og['desc'] = 'Today\'s challenge is ' . $d['name'] . '. Same target for everyone — share your result.';
            }
        }
    } catch (\Throwable $ex) {
        error_log('[combocraft daily-og] ' . $ex->getMessage());
    }
}
if (preg_match('#^/clash/([a-z0-9]+)#', $path, $m)) {
    $og['title'] = 'Combo Clash | ComboCraft';
    $og['desc'] = 'I just battled in Combo Clash — a real-time combo fight.';
    $og['image'] = $abs . '/og/clash/' . $m[1] . '.png';
    $og['url'] = $abs . '/clash/' . $m[1];
    try {
        if (\ComboCraft\Config::dbConfigured()) {
            $s = \ComboCraft\Clash::share($m[1]);
            if ($s) {
                $og['title'] = $s['headline'] . ' | ComboCraft';
                $og['desc'] = $s['headline'] . '. Best of 3 in Combo Clash.';
            }
        }
    } catch (\Throwable $ex) {
        error_log('[combocraft clash-og] ' . $ex->getMessage());
    }
}
$image = $og['image'] ?: ($abs . '/assets/og.png');
$url = $og['url'] ?: ($abs . '/');
$meta = '<title>' . htmlspecialchars($og['title'], ENT_QUOTES) . '</title>'
    . '<meta name="description" content="' . htmlspecialchars($og['desc'], ENT_QUOTES) . '" />'
    . '<meta property="og:title" content="' . htmlspecialchars($og['title'], ENT_QUOTES) . '" />'
    . '<meta property="og:description" content="' . htmlspecialchars($og['desc'], ENT_QUOTES) . '" />'
    . '<meta property="og:type" content="website" />'
    . '<meta property="og:url" content="' . htmlspecialchars($url, ENT_QUOTES) . '" />'
    . '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES) . '" />'
    . '<meta name="twitter:card" content="summary_large_image" />'
    . '<meta name="twitter:title" content="' . htmlspecialchars($og['title'], ENT_QUOTES) . '" />'
    . '<meta name="twitter:description" content="' . htmlspecialchars($og['desc'], ENT_QUOTES) . '" />'
    . '<meta name="twitter:image" content="' . htmlspecialchars($image, ENT_QUOTES) . '" />';
$html = str_replace('<!--CC_META-->', $meta, $html);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
