<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function rate_fail(bool $json, int $code, string $msg): never
{
    http_response_code($code);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg]);
    } else {
        echo e($msg);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rate_fail($wantsJson, 405, 'POST only');
}
if (!csrf_ok()) {
    rate_fail($wantsJson, 400, 'Your session expired. Refresh the page and try again.');
}
$u = current_user();
if (!$u) {
    if ($wantsJson) {
        rate_fail(true, 401, 'Log in to rate titles.');
    }
    flash('Log in to rate titles.', 'err');
    redirect('login.php?next=' . rawurlencode(safe_back((string)($_POST['back'] ?? ''))));
}

$itemId = (int)($_POST['item_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? -1);
if ($itemId <= 0 || $rating < 0 || $rating > 5) {
    rate_fail($wantsJson, 422, 'Invalid rating.');
}
$item = q1('SELECT id FROM items WHERE id = ?', [$itemId]);
if (!$item) {
    rate_fail($wantsJson, 404, 'That title no longer exists.');
}

set_rating((int)$u['id'], $itemId, $rating);

if ($wantsJson) {
    $s = q1('SELECT COUNT(*) AS n, COALESCE(AVG(rating), 0) AS avg FROM ratings WHERE item_id = ?', [$itemId]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'rating' => $rating, 'n' => (int)$s['n'], 'avg' => round((float)$s['avg'], 1)]);
    exit;
}
header('Location: ' . url(safe_back((string)($_POST['back'] ?? ''))));
