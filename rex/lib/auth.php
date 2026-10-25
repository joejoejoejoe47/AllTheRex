<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $cache = [];
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = q1('SELECT id, email, display_name FROM users WHERE id = ?', [$id]);
        if (!$cache[$id]) {
            unset($_SESSION['uid']);
        }
    }
    return $cache[$id];
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('Please log in first.', 'err');
        redirect('login.php?next=' . rawurlencode(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')));
    }
    return $u;
}

function log_in(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
}

function log_out(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** @return array{0:?int,1:array<string,string>} [new user id, errors by field] */
function register_user(string $email, string $name, string $password): array
{
    $errors = [];
    $email = mb_strtolower(trim($email));
    $name  = clean_text($name, 60);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (mb_strlen($name) < 2) {
        $errors['name'] = 'Pick a display name (at least 2 characters).';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    } elseif (strlen($password) > 200) {
        $errors['password'] = 'That password is too long.';
    }
    if (!$errors && q1('SELECT id FROM users WHERE email = ?', [$email])) {
        $errors['email'] = 'An account with that email already exists.';
    }
    if ($errors) {
        return [null, $errors];
    }

    qx('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)',
        [$email, $name, password_hash($password, PASSWORD_DEFAULT)]);
    return [(int)db()->lastInsertId(), []];
}

function login_blocked(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    qx('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 86400]);
    $r = q1('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND attempted_at > ?', [$ip, time() - 900]);
    return (int)($r['c'] ?? 0) >= 10;
}

function note_failed_login(): void
{
    qx('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)', [$_SERVER['REMOTE_ADDR'] ?? 'cli', time()]);
}

/** Returns the user id on success, null on failure. */
function check_credentials(string $email, string $password): ?int
{
    $u = q1('SELECT id, password_hash FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    // Always run a hash comparison so timing doesn't reveal whether the email exists.
    $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltandotherwiseitwillfailXXXXXXXXXXXXXXXXXX';
    $ok = password_verify($password, $hash);
    if ($u && $ok) {
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            qx('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        return (int)$u['id'];
    }
    return null;
}
