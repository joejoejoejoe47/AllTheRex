<?php
declare(strict_types=1);

const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string
{
    $bits = ''; $out = '';
    foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 5) as $chunk) { $out .= B32_ALPHABET[bindec(str_pad($chunk, 5, '0'))]; }
    return $out;
}

function b32_decode(string $s): string
{
    $bits = '';
    foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
        $i = strpos(B32_ALPHABET, $c);
        if ($i !== false) { $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT); }
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $out .= chr(bindec($byte)); } }
    return $out;
}

function totp_new_secret(): string { return b32_encode(random_bytes(20)); }

/** RFC 4226 HOTP, 6 digits. */
function hotp(string $key, int $counter): string
{
    $h = hash_hmac('sha1', pack('J', $counter), $key, true);
    $o = ord($h[19]) & 15;
    $n = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
    return str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

/**
 * Check a 6-digit code (RFC 6238; 30 s steps, +-1 step of clock drift).
 * Returns the matching time step or null. Pass the last accepted step so a code can't be replayed.
 */
function totp_check(string $secret, string $code, int $lastStep = 0, ?int $now = null): ?int
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $key = b32_decode($secret);
    $step = intdiv($now ?? time(), 30);
    foreach ([0, -1, 1] as $d) {
        $s = $step + $d;
        if ($s > $lastStep && hash_equals(hotp($key, $s), $code)) {
            return $s;
        }
    }
    return null;
}

function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode("$issuer:$account") . "?secret=$secret&issuer=" . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
