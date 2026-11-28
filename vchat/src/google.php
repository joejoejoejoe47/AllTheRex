<?php
declare(strict_types=1);

/** Verify a Google Identity Services ID token (RS256 JWT) locally against Google's public keys. */

function der_len(int $l): string { return $l < 128 ? chr($l) : chr(0x80 | strlen(ltrim(pack('N', $l), "\0"))) . ltrim(pack('N', $l), "\0"); }
function der(int $tag, string $v): string { return chr($tag) . der_len(strlen($v)) . $v; }
function der_int(string $bin): string
{
    $bin = ltrim($bin, "\0");
    if ($bin === '' || ord($bin[0]) > 127) { $bin = "\0" . $bin; }
    return der(0x02, $bin);
}

function jwk_to_pem(array $jwk): string
{
    $rsa = der(0x30, der_int(b64u_dec($jwk['n'])) . der_int(b64u_dec($jwk['e'])));
    $algo = der(0x30, hex2bin('06092a864886f70d01010105') . hex2bin('00'));
    $spki = der(0x30, $algo . der(0x03, "\0" . $rsa));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function google_keys(bool $force = false): array
{
    $file = sys_get_temp_dir() . '/vchat_google_jwks_' . substr(sha1(__DIR__), 0, 8) . '.json';
    if (!$force && is_file($file) && filemtime($file) > time() - 3600) {
        $d = json_decode((string)file_get_contents($file), true);
        if (!empty($d['keys'])) { return $d['keys']; }
    }
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/certs');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $body = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    $d = $ok ? json_decode((string)$body, true) : null;
    if (empty($d['keys'])) { throw new RuntimeException('Could not fetch Google keys'); }
    @file_put_contents($file, json_encode($d));
    return $d['keys'];
}

/** @return array{sub:string,email:?string,name:string,picture:?string} */
function google_verify(string $idToken, ?string $aud = null, ?array $keys = null, ?int $now = null): array
{
    $aud ??= (string)cfg('google_client_id');
    if ($aud === '') { throw new RuntimeException('Google sign-in is not configured'); }
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) { throw new RuntimeException('Malformed token'); }
    $header = json_decode(b64u_dec($parts[0]), true);
    $claims = json_decode(b64u_dec($parts[1]), true);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256') { throw new RuntimeException('Bad token'); }

    $find = fn(array $ks) => current(array_filter($ks, fn($k) => ($k['kid'] ?? null) === ($header['kid'] ?? '')));
    $jwk = $find($keys ?? google_keys());
    if (!$jwk && $keys === null) { $jwk = $find(google_keys(true)); }
    if (!$jwk) { throw new RuntimeException('Unknown signing key'); }

    if (openssl_verify($parts[0] . '.' . $parts[1], b64u_dec($parts[2]), jwk_to_pem($jwk), OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Bad signature');
    }
    if (($claims['aud'] ?? '') !== $aud) { throw new RuntimeException('Wrong audience'); }
    if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) { throw new RuntimeException('Wrong issuer'); }
    if (($claims['exp'] ?? 0) <= ($now ?? time())) { throw new RuntimeException('Token expired'); }
    if (empty($claims['sub'])) { throw new RuntimeException('No subject'); }
    if (!empty($claims['email']) && !in_array($claims['email_verified'] ?? false, [true, 'true'], true)) { throw new RuntimeException('Email not verified'); }
    return ['sub' => (string)$claims['sub'], 'email' => $claims['email'] ?? null,
        'name' => (string)($claims['name'] ?? $claims['email'] ?? 'Google user'), 'picture' => $claims['picture'] ?? null];
}
