<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SaintQuest\Auth;
use SaintQuest\Db;
use SaintQuest\Saints;

test_db();

$u = Auth::signUp('SaintlySam', 'Password-123');
check($u['username'] === 'SaintlySam', 'sign up returns the username');
$row = Db::one('SELECT * FROM sq_users WHERE id = ?', [$u['id']]);
check($row['password_hash'] !== 'Password-123' && str_starts_with((string) $row['password_hash'], '$2y$'), 'password is stored as a bcrypt hash, never plain');
check(Auth::verify('Password-123', (string) $row['password_hash']), 'hash verifies the right password');
check(!Auth::verify('password-123', (string) $row['password_hash']), 'hash rejects a wrong password');
check((int) $row['virtue'] === Auth::START_VIRTUE, 'new players start with ' . Auth::START_VIRTUE . ' virtue points');
$saints = Saints::ofOwner($u['id']);
check(count($saints) === 1 && $saints[0]['key'] === 'francis', 'every new player gets the starter saint (St. Francis)');
check(isset($_COOKIE[Auth::SESSION_COOKIE]) && strlen($_COOKIE[Auth::SESSION_COOKIE]) > 30, 'sign up starts a session');
$tokenHash = Db::value('SELECT token_hash FROM sq_sessions WHERE user_id = ?', [$u['id']]);
check($tokenHash === hash('sha256', $_COOKIE[Auth::SESSION_COOKIE]), 'only the session token hash is stored');
Auth::resetCache();
check(Auth::currentUser()['id'] === $u['id'], 'session cookie resolves to the user');

expect_error(fn () => Auth::signUp('saintlysam', 'Password-123'), 422, 'usernames are unique regardless of case');
expect_error(fn () => Auth::signUp('ab', 'Password-123'), 400, 'too-short username rejected');
expect_error(fn () => Auth::signUp('<script>', 'Password-123'), 400, 'username with markup rejected');
expect_error(fn () => Auth::signUp('Good Name', 'short'), 400, 'short password rejected');

Auth::signOut();
Auth::resetCache();
check(Auth::currentUser() === null, 'sign out ends the session');
check((int) Db::value('SELECT COUNT(*) FROM sq_sessions WHERE user_id = ?', [$u['id']]) === 0, 'sign out deletes the session row');

$in = Auth::signIn('SAINTLYSAM', 'Password-123');
check($in['id'] === $u['id'], 'sign in is case-insensitive for the username');
expect_error(fn () => Auth::signIn('SaintlySam', 'wrong-password'), 401, 'wrong password rejected');

// Rate limit: 10 failures per 10 minutes per ip+name.
$hit429 = false;
for ($i = 0; $i < 12; $i++) {
    try { Auth::signIn('SaintlySam', 'nope-nope-' . $i); } catch (\SaintQuest\HttpError $e) { if ($e->status === 429) { $hit429 = true; break; } }
}
check($hit429, 'repeated wrong passwords are rate limited (429)');

// CSRF
$_COOKIE[Auth::CSRF_COOKIE] = 'abcdefghijklmnopqrstuvwxyz012345';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'abcdefghijklmnopqrstuvwxyz012345';
try { Auth::assertCsrf(); check(true, 'matching CSRF token accepted'); } catch (Throwable $e) { check(false, 'matching CSRF token accepted'); }
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'not-the-same-token-at-all-000000';
expect_error(fn () => Auth::assertCsrf(), 403, 'mismatched CSRF token rejected');
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
expect_error(fn () => Auth::assertCsrf(), 403, 'missing CSRF token rejected');

// Cross-site request blocked
$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
expect_error(fn () => \SaintQuest\Http::assertSameSite(), 403, 'cross-site Origin rejected');
unset($_SERVER['HTTP_ORIGIN']);

// Level curve
check(Saints::level(0)['level'] === 1 && Saints::level(499)['level'] === 1 && Saints::level(500)['level'] === 2, 'level 2 at 500 lifetime virtue');
check(Saints::level(1250)['level'] === 3, 'level 3 at 1250 lifetime virtue');

// Public guard
foreach (['/app/.env', '/app/src/Auth.php', '/tests/AuthTest.php', '/app/storage/uploads/x.jpg', '/x.sqlite', '/.git/config'] as $p) {
    check(\SaintQuest\PublicGuard::isForbidden($p), "private path $p is blocked");
}
check(!\SaintQuest\PublicGuard::isForbidden('/assets/js/main.js'), 'public assets are allowed');

finish();
