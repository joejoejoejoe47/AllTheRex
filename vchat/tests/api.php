<?php
declare(strict_types=1);
// php tests/api.php  - starts PHP's built-in server with a throwaway SQLite DB and exercises the HTTP API.
$db = sys_get_temp_dir() . '/vchat-api-' . getmypid() . '.sqlite';
@unlink($db);
putenv("VCHAT_CONFIG=" . __DIR__ . '/config.test.php');
putenv("VCHAT_TEST_DB=$db");
require __DIR__ . '/../src/bootstrap.php';
foreach (vchat_schema('sqlite') as $sql) { db()->exec($sql); }

$port = 3997; $log = sys_get_temp_dir() . '/vchat-api-' . getmypid() . '.log';
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
    $pipes, null, ['VCHAT_CONFIG' => __DIR__ . '/config.test.php', 'VCHAT_TEST_DB' => $db, 'PHP_CLI_SERVER_WORKERS' => '8', 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $db, $log) { proc_terminate($proc); @unlink($db); @unlink("$db-wal"); @unlink("$db-shm"); @unlink($log); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }

function ok(bool $c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }

function call(string $action, ?array $body = null, ?string $cookie = null, array $query = [], ?string $ctype = 'application/json'): array
{
    global $port;
    $ch = curl_init("http://127.0.0.1:$port/api.php?" . http_build_query(['a' => $action] + $query));
    $hdr = [];
    if ($ctype) { $hdr[] = "Content-Type: $ctype"; }
    if ($cookie) { $hdr[] = "Cookie: $cookie"; }
    $setCookie = null;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$setCookie) { if (stripos($h, 'set-cookie: vchat_sid=') === 0) { $setCookie = trim(explode(';', substr($h, 12))[0]); } return strlen($h); }]);
    if ($body !== null) { curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]); }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string)$raw, true) ?? [], 'cookie' => $setCookie];
}

function last_code(string $phone): string
{
    global $log;
    for ($i = 0; $i < 30; $i++) {
        if (preg_match_all('/to ' . preg_quote($phone, '/') . ': Your vchat code is (\d{6})/', (string)file_get_contents($log), $m)) { return end($m[1]); }
        usleep(50000);
    }
    ok(false, "no SMS code for $phone");
}

function login(string $phone, ?string $secret = null): array
{
    ok(call('auth.phone.start', ['phone' => $phone])['status'] === 200, 'start');
    ok(call('auth.phone.verify', ['phone' => $phone, 'code' => '000000'])['status'] === 401, 'wrong sms code');
    $v = call('auth.phone.verify', ['phone' => $phone, 'code' => last_code($phone)]);
    ok($v['status'] === 200 && $v['body']['step'] === ($secret ? 'totp' : 'enroll'), 'verify step ' . json_encode($v['body']));
    $secret ??= $v['body']['secret'];
    $bad = call('auth.phone.2fa', ['ticket' => $v['body']['ticket'], 'code' => '123456']);
    ok($bad['status'] === 401 && !$bad['cookie'], 'wrong 2nd step gives no session');
    $r = call('auth.phone.2fa', ['ticket' => $v['body']['ticket'], 'code' => hotp(b32_decode($secret), intdiv(time(), 30))]);
    ok($r['status'] === 200 && $r['cookie'], '2fa ' . json_encode($r['body']));
    return ['cookie' => $r['cookie'], 'secret' => $secret, 'recovery' => $r['body']['recoveryCodes'] ?? null, 'user' => $r['body']['user']];
}

// ---- access control basics ----
ok(call('meeting.get', null, null, ['code' => 'abc-defg-hij'])['status'] === 401, 'meeting.get needs login');
ok(call('meeting.create', [])['status'] === 401, 'create needs login');
ok(call('auth.phone.start', ['phone' => '+15550000000'], null, [], 'text/plain')['status'] === 415, 'non-JSON rejected');
ok(call('auth.phone.start', ['phone' => 'nope'])['status'] === 400, 'bad phone');
ok(call('auth.google', ['credential' => 'x.y.z'])['status'] === 401, 'bad google token');

// ---- phone + 2-step ----
$host = login('+15550000001');
ok(count($host['recovery']) === 8, '8 recovery codes');
call('auth.phone.start', ['phone' => '+15550000001']);
$v2 = call('auth.phone.verify', ['phone' => '+15550000001', 'code' => last_code('+15550000001')]);
ok($v2['body']['step'] === 'totp', 'second login asks for authenticator');
ok(call('auth.phone.2fa', ['ticket' => $v2['body']['ticket'], 'code' => $host['recovery'][0]])['status'] === 200, 'recovery code works');
call('auth.phone.start', ['phone' => '+15550000001']);
$v3 = call('auth.phone.verify', ['phone' => '+15550000001', 'code' => last_code('+15550000001')]);
ok(call('auth.phone.2fa', ['ticket' => $v3['body']['ticket'], 'code' => $host['recovery'][0]])['status'] === 401, 'recovery code single-use');
// TOTP replay: the same code can't be used twice
$step = intdiv(time(), 30);
user_update($host['user']['id'], ['totp_last_step' => $step]);
ok(call('auth.phone.2fa', ['ticket' => $v3['body']['ticket'], 'code' => hotp(b32_decode($host['secret']), $step)])['status'] === 401, 'totp replay rejected');
// lockout after repeated wrong 2nd-step codes
for ($i = 0; $i < 6; $i++) { $r = call('auth.phone.2fa', ['ticket' => $v3['body']['ticket'], 'code' => '000000']); }
ok($r['status'] === 429, 'lockout after repeated failures');
rl_clear('step2:' . $host['user']['id']);

// ---- meetings + 20-person cap ----
$m = call('meeting.create', ['title' => 'Test'], $host['cookie']);
ok($m['status'] === 201, 'create meeting');
$code = $m['body']['code'];
ok(call('meeting.get', null, $host['cookie'], ['code' => $code])['body']['isHost'] === true, 'host flag');
ok(call('meeting.get', null, $host['cookie'], ['code' => 'zzz-zzzz-zzz'])['status'] === 404, 'unknown meeting');

$people = [];
for ($i = 0; $i < 21; $i++) {
    $u = $i === 0 ? $host : login('+1555000' . (100 + $i));
    $j = call('room.join', ['code' => $code], $u['cookie']);
    if ($i < 20) { ok($j['status'] === 200 && count($j['body']['peers']) === $i, "join $i: " . json_encode($j['body'])); $u['peer'] = $j['body']['peer']; $u['since'] = $j['body']['since']; $people[] = $u; }
    else { ok($j['status'] === 409, '21st person refused'); $extra = $u; }
}
[$a, $b] = $people;
ok(call('meeting.get', null, $host['cookie'], ['code' => $code])['body']['full'] === true, 'meeting reports full');

// signalling relay / chat / state
$send = fn($p, $msgs) => call('room.send', ['peer' => $p['peer'], 'msgs' => $msgs], $p['cookie']);
$poll = fn($p, $since) => call('room.poll', ['peer' => $p['peer'], 'since' => $since], $p['cookie'])['body'];
ok($send($a, [['type' => 'signal', 'to' => $b['peer'], 'data' => ['sdp' => ['type' => 'offer', 'sdp' => 'x']]], ['type' => 'chat', 'text' => 'hello'], ['type' => 'state', 'muted' => false, 'cameraOff' => false, 'sharing' => false]])['status'] === 200, 'send');
$ev = $poll($b, $b['since'])['events'];
$types = array_column($ev, 'type');
ok(in_array('signal', $types, true) && in_array('chat', $types, true) && in_array('state', $types, true), 'b receives signal+chat+state: ' . json_encode($types));
ok(!in_array('signal', array_column($poll($people[5], $people[5]['since'])['events'], 'type'), true), 'signals are private to the target');
ok(!in_array('chat', array_column($poll($a, $a['since'])['events'], 'type'), true) || count(array_filter($poll($a, $a['since'])['events'], fn($e) => $e['type'] === 'chat' && $e['from'] === $a['peer'])) === 0, 'sender does not get own chat');
// someone else cannot use my peer id
ok(call('room.send', ['peer' => $a['peer'], 'msgs' => [['type' => 'chat', 'text' => 'spoof']]], $b['cookie'])['status'] === 410, 'cannot send as another peer');
$spy = $poll(['peer' => $a['peer'], 'cookie' => $b['cookie']], 0);
ok($spy['events'] === [], 'cannot poll as another peer');

// non-host cannot kick; host can, and kicked user cannot rejoin; freed seat is usable
$send($b, [['type' => 'kick', 'id' => $a['peer']]]);
ok(participant_by_peer($a['peer']) !== null, 'non-host kick ignored');
$send($people[2], [['type' => 'kick', 'id' => $b['peer']]]);
ok(participant_by_peer($b['peer']) !== null, 'kick by another non-host ignored');
$send($a, [['type' => 'kick', 'id' => $b['peer']]]); // $a is the host
ok(participant_by_peer($b['peer']) === null, 'host kicked b');
ok($poll($b, $b['since'])['gone'] === 'kicked', 'b is told it was kicked');
ok(call('room.join', ['code' => $code], $b['cookie'])['status'] === 403, 'kicked user cannot rejoin');
ok(call('room.join', ['code' => $code], $extra['cookie'])['status'] === 200, '21st person fits after a seat frees');

// same account twice: older connection replaced
$c = $people[2];
$j2 = call('room.join', ['code' => $code], $c['cookie']);
ok($j2['status'] === 200 && participant_by_peer($c['peer']) === null, 'old session replaced');
ok($poll($c, $c['since'])['gone'] === 'replaced', 'old session told it was replaced');

// stale peers are dropped
q('UPDATE vchat_participants SET last_seen = ? WHERE peer_id = ?', [time() - 100, $people[3]['peer']]);
ok(call('meeting.get', null, $host['cookie'], ['code' => $code])['status'] === 200 && participant_by_peer($people[3]['peer']) === null, 'stale peer pruned');

// end for all
$send($a, [['type' => 'end']]); // $a is the host
ok($poll($people[4], $people[4]['since'])['gone'] === 'ended', 'meeting ended for everyone');
ok(call('room.join', ['code' => $code], $host['cookie'])['status'] === 404, 'cannot join ended meeting');
echo "api tests passed\n";
