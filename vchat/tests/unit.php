<?php
declare(strict_types=1);
// php tests/unit.php
putenv('VCHAT_CONFIG=' . __DIR__ . '/config.test.php');
require __DIR__ . '/../src/bootstrap.php';

function ok(bool $c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }
function throws(callable $f, string $needle): void
{
    try { $f(); } catch (Throwable $e) { ok(str_contains($e->getMessage(), $needle), "expected '$needle', got '{$e->getMessage()}'"); return; }
    ok(false, "expected exception '$needle'");
}

// RFC 4226 vectors
foreach (['755224', '287082', '359152', '969429'] as $i => $v) { ok(hotp('12345678901234567890', $i) === $v, "hotp $i"); }
ok(b32_decode(b32_encode('12345678901234567890')) === '12345678901234567890', 'base32');
$s = totp_new_secret(); $now = 1700000000; $step = intdiv($now, 30);
$code = hotp(b32_decode($s), $step);
ok(totp_check($s, $code, 0, $now) === $step, 'totp ok');
ok(totp_check($s, $code, $step, $now) === null, 'totp replay rejected');
ok(totp_check($s, 'abcdef', 0, $now) === null, 'totp junk');

ok(normalize_phone('+1 (555) 123-4567') === '+15551234567', 'phone ok');
ok(normalize_phone('5551234567') === null, 'phone needs +');

$t = sign_token(['a' => 1], 100);
ok(verify_token($t)['a'] === 1, 'token');
ok(verify_token(substr($t, 0, -2) . 'xx') === null, 'tampered token');
ok(verify_token(sign_token(['a' => 1], -5)) === null, 'expired token');

// Google token verification with a generated RSA key
$key = openssl_pkey_new(['private_key_bits' => 2048]);
$d = openssl_pkey_get_details($key)['rsa'];
$jwk = ['kid' => 'k1', 'kty' => 'RSA', 'n' => b64u($d['n']), 'e' => b64u($d['e'])];
$mk = function (array $claims, $priv = null) use ($key) {
    $b = fn($o) => b64u(json_encode($o));
    $body = $b(['alg' => 'RS256', 'kid' => 'k1']) . '.' . $b($claims);
    openssl_sign($body, $sig, $priv ?? $key, OPENSSL_ALGO_SHA256);
    return $body . '.' . b64u($sig);
};
$good = ['iss' => 'https://accounts.google.com', 'aud' => 'my-client', 'sub' => '123', 'email' => 'a@b.c', 'email_verified' => true, 'name' => 'A', 'exp' => time() + 600];
ok(google_verify($mk($good), 'my-client', [$jwk])['sub'] === '123', 'google ok');
throws(fn() => google_verify($mk($good), 'other', [$jwk]), 'audience');
throws(fn() => google_verify($mk(['iss' => 'evil'] + $good), 'my-client', [$jwk]), 'issuer');
throws(fn() => google_verify($mk(['exp' => 1] + $good), 'my-client', [$jwk]), 'expired');
throws(fn() => google_verify($mk(['email_verified' => false] + $good), 'my-client', [$jwk]), 'verified');
throws(fn() => google_verify($mk($good, openssl_pkey_new(['private_key_bits' => 2048])), 'my-client', [$jwk]), 'signature');
echo "unit tests passed\n";
