<?php
declare(strict_types=1);

const REX_CATEGORIES = ['movie' => 'Movies', 'album' => 'Albums', 'book' => 'Books'];
const REX_SINGULAR   = ['movie' => 'Movie', 'album' => 'Album', 'book' => 'Book'];
const REX_CREATOR    = ['movie' => 'Director', 'album' => 'Artist', 'book' => 'Author'];

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Base URL of this app, so it works at /rex/ now and at the site root later. */
function base_url(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/') . '/';
}

function url(string $path = ''): string
{
    return base_url() . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? '/' : url($path)));
    exit;
}

/** Only allow redirects back to one of our own pages. */
function safe_back(?string $back, string $fallback = 'index.php'): string
{
    return ($back && preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $back)) ? $back : $fallback;
}

function rex_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    session_name('rexsid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_url(),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'] ?? '', $sent);
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }
    if (!csrf_ok()) {
        http_response_code(400);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function clean_text(?string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    return mb_substr($s, 0, $max);
}

function valid_category(?string $c): ?string
{
    return ($c !== null && isset(REX_CATEGORIES[$c])) ? $c : null;
}

/** Deterministic cover gradient, since we don't store artwork. */
function cover_style(string $title, string $category): string
{
    $h = crc32($category . '|' . $title) % 360;
    return sprintf('background:linear-gradient(145deg,hsl(%d 52%% 34%%),hsl(%d 58%% 14%%))', $h, ($h + 48) % 360);
}

function initials(string $title): string
{
    $title = preg_replace('/^(the|a|an)\s+/i', '', $title);
    $words = preg_split('/\s+/', trim($title)) ?: [];
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out ?: '?';
}

function fmt_avg($v): string
{
    return ($v === null || (float)$v <= 0) ? '–' : number_format((float)$v, 1);
}

/** This page (file + query) in a form safe_back() will accept, for "return here after rating". */
function current_back(): string
{
    $file = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $qs   = $_SERVER['QUERY_STRING'] ?? '';
    return safe_back($file . ($qs !== '' ? '?' . $qs : ''), $file);
}
