<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WorldDomination\Auth;
use WorldDomination\Db;
use WorldDomination\HttpError;

test_db();

$user = Auth::signUp('Alex@Example.com', 'correct-horse', 'Alex');
check($user['email'] === 'alex@example.com', 'email is normalized');
check($user['name'] === 'Alex', 'name stored');
check(Auth::currentUser()['id'] === $user['id'], 'signup starts a session');

$token = $_COOKIE[Auth::SESSION_COOKIE] ?? '';
check($token !== '', 'session cookie issued');
$hash = Db::value('SELECT token_hash FROM wd_sessions WHERE user_id = ?', [$user['id']]);
check(is_string($hash) && $hash === hash('sha256', $token), 'only the session hash is stored');
check($hash !== $token, 'raw session token is not stored');

$stored = (string) Db::value('SELECT password_hash FROM wd_users WHERE id = ?', [$user['id']]);
check(!str_contains($stored, 'correct-horse'), 'password is not stored in plaintext');
check(Auth::verify('correct-horse', $stored), 'password verifies');

$dup = null;
try {
    Auth::signUp('alex@example.com', 'another-pass', 'Alex2');
} catch (HttpError $e) {
    $dup = $e;
}
check($dup instanceof HttpError && $dup->status === 422, 'duplicate email rejected');

$short = null;
try {
    Auth::signUp('new@example.com', 'short', 'Sam');
} catch (HttpError $e) {
    $short = $e;
}
check($short instanceof HttpError && $short->status === 400, 'short password rejected');

Auth::signOut();
check(Auth::currentUser() === null, 'sign out clears the session');

$back = Auth::signIn('alex@example.com', 'correct-horse');
check($back['id'] === $user['id'], 'login succeeds with email and password');

$bad = null;
try {
    Auth::signIn('alex@example.com', 'wrong-password');
} catch (HttpError $e) {
    $bad = $e;
}
check($bad instanceof HttpError && $bad->status === 401, 'bad password rejected');

// Rate limit: more failed attempts than the window allows.
$hitLimit = false;
for ($i = 0; $i < 12; $i++) {
    try {
        Auth::signIn('alex@example.com', 'wrong-password');
    } catch (HttpError $e) {
        if ($e->status === 429) {
            $hitLimit = true;
            break;
        }
    }
}
check($hitLimit, 'repeated failed logins are rate limited');

// CSRF
$_COOKIE[Auth::CSRF_COOKIE] = 'csrf-token-value-aaaaaaaa';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'csrf-token-value-aaaaaaaa';
try {
    Auth::assertCsrf();
    check(true, 'matching CSRF header is accepted');
} catch (HttpError $e) {
    check(false, 'matching CSRF header is accepted');
}
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
$csrfBad = null;
try {
    Auth::assertCsrf();
} catch (HttpError $e) {
    $csrfBad = $e;
}
check($csrfBad instanceof HttpError && $csrfBad->status === 403, 'mismatched CSRF is rejected');

finish();
