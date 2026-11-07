<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WorldDomination\Auth;
use WorldDomination\Config;
use WorldDomination\HttpError;
use WorldDomination\PublicGuard;

check(!is_file(__DIR__ . '/../install.php'), 'public install.php is not shipped');

$setup = (string) file_get_contents(__DIR__ . '/../app/bin/setup.php');
check(str_contains($setup, 'PHP_SAPI !== \'cli\''), 'setup script refuses non-CLI access');

check(PublicGuard::isForbidden('/app/.env'), 'app/.env is forbidden');
check(PublicGuard::isForbidden('/app/storage/worlddom.sqlite'), 'sqlite storage is forbidden');
check(PublicGuard::isForbidden('/app/src/Auth.php'), 'PHP sources are forbidden');
check(PublicGuard::isForbidden('app/sql/schema.sql'), 'schema files are forbidden');
check(PublicGuard::isForbidden('/tests/AuthTest.php'), 'tests are forbidden');
check(PublicGuard::isForbidden('/install.php'), 'installer path is forbidden');
check(PublicGuard::isForbidden('/assets/.env'), 'hidden env files are forbidden');
check(!PublicGuard::isForbidden('/assets/js/game.js'), 'game script remains public');
check(!PublicGuard::isForbidden('/assets/data/countries.json'), 'country data remains public');
check(!PublicGuard::isForbidden('/api/health'), 'health API remains routable');

$tmp = sys_get_temp_dir() . '/wd-config-test-' . bin2hex(random_bytes(4)) . '.env';
file_put_contents($tmp, "DB_NAME=wd_test\nDB_USER=wd_user\nOPENAI_API_KEY=sk-should-never-load\nMAIL_URL=smtp://secret\n");
$loaded = Config::loadFile($tmp);
$only = Config::dbKeysOnly($loaded);
unlink($tmp);
check(($only['DB_NAME'] ?? '') === 'wd_test', 'DB contract keys can be read');
check(!isset($only['OPENAI_API_KEY']), 'non-DB secrets are not imported from a shared env file');
check(!isset($only['MAIL_URL']), 'mail secrets are not imported');

test_db();
$_COOKIE[Auth::SESSION_COOKIE] = 'attacker-fixed-session-token';
$_SERVER['REMOTE_ADDR'] = '203.0.113.22';
Auth::resetCache();
Auth::signUp('fixate@example.com', 'correct-horse', 'Fixate');
$session = $_COOKIE[Auth::SESSION_COOKIE] ?? '';
check($session !== '' && $session !== 'attacker-fixed-session-token', 'login/signup issues a new session instead of adopting a supplied token');

Auth::signOut();
$_COOKIE[Auth::SESSION_COOKIE] = 'attacker-fixed-session-token';
Auth::resetCache();
Auth::signIn('fixate@example.com', 'correct-horse');
check(($_COOKIE[Auth::SESSION_COOKIE] ?? '') !== 'attacker-fixed-session-token', 'sign-in rotates the session token');

$bypass = null;
$_COOKIE = [];
Auth::resetCache();
try {
    Auth::requireUser();
} catch (HttpError $e) {
    $bypass = $e;
}
check($bypass instanceof HttpError && $bypass->status === 401, 'protected actions require a logged-in user');

$_COOKIE[Auth::CSRF_COOKIE] = 'csrf-aaa';
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$csrfMissing = null;
try {
    Auth::assertCsrf();
} catch (HttpError $e) {
    $csrfMissing = $e;
}
check($csrfMissing instanceof HttpError && $csrfMissing->status === 403, 'state-changing requests need a CSRF token');

finish();
