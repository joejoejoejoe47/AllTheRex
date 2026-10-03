<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/club.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    $sent = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_MORSE_TOKEN'] ?? ''));
    $real = (string) ($_SESSION['csrf'] ?? '');
    return $real !== '' && hash_equals($real, $sent);
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function current_user(): ?array
{
    if (empty($_SESSION['uid']) || !app_ready()) {
        return null;
    }
    $st = db()->prepare('SELECT id, username, coins, rating FROM users WHERE id = ?');
    $st->execute([(int) $_SESSION['uid']]);
    $row = $st->fetch();
    return $row ?: null;
}
