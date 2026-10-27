<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sx_out(int $code, array $data): never { http_response_code($code); echo json_encode($data); exit; }

if (!current_user()) {
    sx_out(401, ['ok' => false, 'error' => 'Log in to search.']);
}
// Each search calls an outside service: allow 40 per minute per person.
$w = $_SESSION['sx'] ?? ['t' => time(), 'n' => 0];
if (time() - $w['t'] > 60) { $w = ['t' => time(), 'n' => 0]; }
if (++$w['n'] > 40) {
    $_SESSION['sx'] = $w;
    sx_out(429, ['ok' => false, 'error' => 'Too many searches. Wait a moment.']);
}
$_SESSION['sx'] = $w;

$cat = valid_category($_GET['cat'] ?? null);
if (!$cat) {
    sx_out(422, ['ok' => false, 'error' => 'Pick movie, album or book.']);
}
$r = rex_external_search($cat, (string)($_GET['q'] ?? ''));
sx_out(200, ['ok' => true, 'failed' => $r['failed'], 'results' => $r['results']]);
