<?php
declare(strict_types=1);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $https,
]);
session_start();

require __DIR__ . '/lib/bootstrap.php';

if (($_GET['api'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(api_dispatch(), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'The club could not finish that.']);
    }
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handle_post();
    }
    echo render_page();
} catch (Throwable $e) {
    http_response_code(500);
    $_SESSION['flash'] = $e->getMessage();
    echo render_error('The club could not open its database. Check the host, name, user, and password.');
}
