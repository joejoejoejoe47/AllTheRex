<?php
declare(strict_types=1);

/** Users, OTP codes and meetings. All SQL for these lives here. */

function user_row(?array $r): ?array
{
    if (!$r) {
        return null;
    }
    return [
        'id' => $r['id'], 'googleSub' => $r['google_sub'], 'email' => $r['email'], 'phone' => $r['phone'],
        'name' => $r['name'], 'picture' => $r['picture'], 'totpSecret' => $r['totp_secret'],
        'totpEnabled' => (bool)$r['totp_enabled'], 'totpLastStep' => (int)$r['totp_last_step'],
        'recoveryHashes' => json_decode($r['recovery_hashes'] ?: '[]', true) ?: [],
    ];
}
function user_by_id(string $id): ?array { return user_row(q('SELECT * FROM vchat_users WHERE id = ?', [$id])->fetch() ?: null); }
function user_by_google(string $sub): ?array { return user_row(q('SELECT * FROM vchat_users WHERE google_sub = ?', [$sub])->fetch() ?: null); }
function user_by_phone(string $phone): ?array { return user_row(q('SELECT * FROM vchat_users WHERE phone = ?', [$phone])->fetch() ?: null); }

function user_create(array $f): array
{
    $id = uuid();
    q('INSERT INTO vchat_users (id, google_sub, email, phone, name, picture, recovery_hashes, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [$id, $f['google_sub'] ?? null, $f['email'] ?? null, $f['phone'] ?? null, $f['name'], $f['picture'] ?? null, '[]', time()]);
    return user_by_id($id);
}

/** $patch keys are column names. */
function user_update(string $id, array $patch): array
{
    $allowed = ['name', 'picture', 'totp_secret', 'totp_enabled', 'totp_last_step', 'recovery_hashes'];
    $sets = []; $vals = [];
    foreach ($patch as $k => $v) {
        if (!in_array($k, $allowed, true)) {
            throw new InvalidArgumentException("bad column $k");
        }
        $sets[] = "$k = ?";
        $vals[] = is_bool($v) ? (int)$v : (is_array($v) ? json_encode(array_values($v)) : $v);
    }
    $vals[] = $id;
    q('UPDATE vchat_users SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    return user_by_id($id);
}

function otp_put(string $phone, string $hash, int $expiresAt, int $attempts): void
{
    q('DELETE FROM vchat_otps WHERE phone = ?', [$phone]);
    q('INSERT INTO vchat_otps (phone, hash, expires_at, attempts) VALUES (?,?,?,?)', [$phone, $hash, $expiresAt, $attempts]);
}
function otp_get(string $phone): ?array { return q('SELECT * FROM vchat_otps WHERE phone = ?', [$phone])->fetch() ?: null; }
function otp_delete(string $phone): void { q('DELETE FROM vchat_otps WHERE phone = ?', [$phone]); }

function meeting_create(string $hostId, string $title): array
{
    $alpha = 'abcdefghjkmnpqrstuvwxyz';
    $part = function (int $n) use ($alpha): string {
        $s = '';
        for ($i = 0; $i < $n; $i++) { $s .= $alpha[random_int(0, strlen($alpha) - 1)]; }
        return $s;
    };
    $code = $part(3) . '-' . $part(4) . '-' . $part(3);
    q('INSERT INTO vchat_meetings (code, host_id, title, ended, created_at) VALUES (?,?,?,0,?)', [$code, $hostId, $title, time()]);
    return ['code' => $code, 'title' => $title];
}

function meeting_get(string $code, bool $lock = false): ?array
{
    $sql = 'SELECT * FROM vchat_meetings WHERE code = ?' . ($lock && is_mysql() ? ' FOR UPDATE' : '');
    $r = q($sql, [$code])->fetch();
    return $r ? ['code' => $r['code'], 'hostId' => $r['host_id'], 'title' => $r['title'], 'ended' => (bool)$r['ended']] : null;
}
