<?php
declare(strict_types=1);

require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/google.php';

function normalize_phone(mixed $p): ?string
{
    $s = preg_replace('/[\s().-]/', '', (string)$p);
    return preg_match('/^\+[1-9]\d{7,14}$/', $s) ? $s : null;
}

function public_user(array $u): array
{
    return ['id' => $u['id'], 'name' => $u['name'], 'picture' => $u['picture'], 'email' => $u['email'],
        'phone' => $u['phone'] ? '•••' . substr($u['phone'], -4) : null];
}

function otp_hash(string $phone, string $code): string { return hash_hmac('sha256', "$phone:$code", secret()); }

function send_sms(string $to, string $body): void
{
    if (cfg('sms_mode') !== 'twilio') {
        error_log("[sms:dev] to $to: $body");
        return;
    }
    $sid = (string)cfg('twilio_sid');
    $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => $sid . ':' . cfg('twilio_token'),
        CURLOPT_POSTFIELDS => http_build_query(['To' => $to, 'From' => cfg('twilio_from'), 'Body' => $body]),
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { throw new HttpError(502, 'Could not send the text message. Try again later.'); }
}

/** Returns the user; the caller sets the session cookie. */
function auth_google(array $in): array
{
    try {
        $g = google_verify((string)($in['credential'] ?? ''));
    } catch (Throwable $e) {
        if (cfg('debug')) { error_log('[vchat google] ' . $e->getMessage()); }
        throw new HttpError(401, 'Google sign-in failed');
    }
    $user = user_by_google($g['sub']);
    if (!$user) {
        return ['user' => user_create(['google_sub' => $g['sub'], 'email' => $g['email'], 'name' => mb_substr($g['name'], 0, 60), 'picture' => $g['picture']])];
    }
    if ($g['picture'] !== $user['picture']) { $user = user_update($user['id'], ['picture' => $g['picture']]); }
    return ['user' => $user]; // Google enforces the account's own 2-Step Verification
}

function auth_phone_start(array $in): array
{
    $phone = normalize_phone($in['phone'] ?? '');
    if (!$phone) { throw new HttpError(400, 'Enter your number with country code, e.g. +15551234567'); }
    if (!rl_hit('sms-ip:' . client_ip(), (int)cfg('sms_ip_limit'), 3600) || !rl_hit("sms-phone:$phone", 5, 3600)) {
        throw new HttpError(429, 'Too many codes requested. Try again later.');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    otp_put($phone, otp_hash($phone, $code), time() + 300, 0);
    send_sms($phone, "Your vchat code is $code. It expires in 5 minutes.");
    return ['ok' => true]; // identical whether or not the number already has an account
}

function auth_phone_verify(array $in): array
{
    $phone = normalize_phone($in['phone'] ?? '');
    $code = (string)($in['code'] ?? '');
    if (!$phone || !preg_match('/^\d{6}$/', $code)) { throw new HttpError(400, 'Enter the 6-digit code.'); }
    if (rl_blocked("otp-try:$phone", 10, 900)) { throw new HttpError(429, 'Too many attempts. Request a new code later.'); }
    $rec = otp_get($phone);
    if (!$rec || (int)$rec['expires_at'] < time()) { throw new HttpError(401, 'Code expired. Request a new one.'); }
    if ((int)$rec['attempts'] >= 5 || !hash_equals($rec['hash'], otp_hash($phone, $code))) {
        rl_hit("otp-try:$phone", 10, 900);
        otp_put($phone, $rec['hash'], (int)$rec['expires_at'], (int)$rec['attempts'] + 1);
        throw new HttpError(401, 'Wrong code.');
    }
    otp_delete($phone);
    rl_clear("otp-try:$phone");

    $user = user_by_phone($phone) ?? user_create(['phone' => $phone, 'name' => 'User ' . substr($phone, -4)]);
    $ticket = sign_token(['uid' => $user['id'], 'k' => 'step2'], 300);
    if ($user['totpEnabled']) { return ['step' => 'totp', 'ticket' => $ticket]; }

    // First time: enrol an authenticator app. It is not active until a code is confirmed.
    $secret = totp_new_secret();
    user_update($user['id'], ['totp_secret' => $secret, 'totp_enabled' => false, 'totp_last_step' => 0]);
    return ['step' => 'enroll', 'ticket' => $ticket, 'secret' => $secret, 'otpauth' => totp_uri($secret, $phone, (string)cfg('totp_issuer'))];
}

function auth_phone_second_step(array $in): array
{
    $t = verify_token((string)($in['ticket'] ?? ''));
    if (!$t || ($t['k'] ?? '') !== 'step2') { throw new HttpError(401, 'Session expired. Start again.'); }
    $uid = (string)$t['uid'];
    if (rl_blocked("step2:$uid", 5, 900)) { throw new HttpError(429, 'Too many wrong codes. Try again in 15 minutes.'); }
    $user = user_by_id($uid);
    if (!$user || !$user['totpSecret']) { throw new HttpError(401, 'Start again.'); }
    $input = trim((string)($in['code'] ?? ''));

    $step = totp_check($user['totpSecret'], $input, $user['totpLastStep']);
    if ($step !== null) {
        rl_clear("step2:$uid");
        if (!$user['totpEnabled']) { // enrolment confirmed -> issue recovery codes
            $recovery = [];
            for ($i = 0; $i < 8; $i++) { $recovery[] = bin2hex(random_bytes(5)); }
            $u = user_update($uid, ['totp_enabled' => true, 'totp_last_step' => $step, 'recovery_hashes' => array_map(fn($c) => hash('sha256', $c), $recovery)]);
            return ['user' => $u, 'recoveryCodes' => $recovery];
        }
        return ['user' => user_update($uid, ['totp_last_step' => $step])];
    }
    $h = hash('sha256', strtolower($input));
    if ($user['totpEnabled'] && in_array($h, $user['recoveryHashes'], true)) { // single-use recovery code
        rl_clear("step2:$uid");
        $left = array_values(array_diff($user['recoveryHashes'], [$h]));
        $u = user_update($uid, ['recovery_hashes' => $left]);
        return ['user' => $u, 'recoveryLeft' => count($left)];
    }
    rl_hit("step2:$uid", 5, 900);
    throw new HttpError(401, 'Wrong code.');
}

function ice_servers(string $userId): array
{
    $list = [['urls' => array_values((array)cfg('stun_urls'))]];
    $turn = array_values((array)cfg('turn_urls'));
    if ($turn) {
        if (cfg('turn_secret')) { // coturn "use-auth-secret": short-lived credentials
            $user = (time() + 6 * 3600) . ':' . $userId;
            $list[] = ['urls' => $turn, 'username' => $user, 'credential' => base64_encode(hash_hmac('sha1', $user, (string)cfg('turn_secret'), true))];
        } elseif (cfg('turn_username')) {
            $list[] = ['urls' => $turn, 'username' => cfg('turn_username'), 'credential' => cfg('turn_credential')];
        }
    }
    return $list;
}
