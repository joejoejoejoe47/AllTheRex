<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

security_headers();

try {
    $a = (string)($_GET['a'] ?? '');
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';

    $login = function (array $r): never {
        set_session_cookie($r['user']['id']);
        $out = ['user' => public_user($r['user'])];
        foreach (['recoveryCodes', 'recoveryLeft'] as $k) { if (isset($r[$k])) { $out[$k] = $r[$k]; } }
        json_out(200, $out);
    };

    if ($a === 'config' && !$post) {
        $u = current_user();
        json_out(200, ['googleClientId' => cfg('google_client_id'), 'maxParticipants' => cfg('max_participants'),
            'iceServers' => $u ? ice_servers($u['id']) : [], 'user' => $u ? public_user($u) : null]);
    }

    if (!$post && $a !== 'meeting.get') { throw new HttpError(405, 'Use POST'); }

    switch ($a) {
        case 'auth.google':       $login(auth_google(read_json()));
        case 'auth.phone.start':  json_out(200, auth_phone_start(read_json()));
        case 'auth.phone.verify': json_out(200, auth_phone_verify(read_json()));
        case 'auth.phone.2fa':    $login(auth_phone_second_step(read_json()));
        case 'auth.logout':       clear_session_cookie(); json_out(200, ['ok' => true]);
    }

    $user = require_user();
    switch ($a) {
        case 'me.update':
            $name = clean_text(read_json()['name'] ?? '', 60);
            if ($name === '') { throw new HttpError(400, 'Name required'); }
            json_out(200, ['user' => public_user(user_update($user['id'], ['name' => $name]))]);
        case 'meeting.create':
            $title = clean_text(read_json()['title'] ?? '', 120) ?: $user['name'] . "'s meeting";
            json_out(201, meeting_create($user['id'], $title));
        case 'meeting.get':
            json_out(200, room_info($user, valid_code($_GET['code'] ?? '')));
        case 'room.join':
            json_out(200, room_join($user, valid_code(read_json()['code'] ?? '')));
        case 'room.poll':
            $in = read_json();
            json_out(200, room_poll($user, (string)($in['peer'] ?? ''), (int)($in['since'] ?? 0)));
        case 'room.send':
            $in = read_json(262144);
            room_send($user, (string)($in['peer'] ?? ''), is_array($in['msgs'] ?? null) ? $in['msgs'] : []);
            json_out(200, ['ok' => true]);
        case 'room.leave':
            room_leave($user, (string)(read_json()['peer'] ?? ''));
            json_out(200, ['ok' => true]);
    }
    throw new HttpError(404, 'Not found');
} catch (HttpError $e) {
    json_out($e->status, ['error' => $e->getMessage()] + $e->extra);
} catch (Throwable $e) {
    error_log('[vchat] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    json_out(500, ['error' => cfg('debug') ? $e->getMessage() : 'Server error']);
}
