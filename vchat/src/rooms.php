<?php
declare(strict_types=1);

/**
 * Meeting rooms over plain HTTP (no WebSockets needed, so it runs on ordinary PHP hosting).
 * Clients join, then poll for events and post batches of messages. Media is peer-to-peer WebRTC;
 * this only carries the setup messages (offers/answers/ICE), chat and presence.
 */

const PEER_TIMEOUT = 20;   // seconds without a poll before someone is considered gone
const EVENT_TTL = 180;     // seconds events are kept

function event_add(string $code, ?string $to, string $from, string $type, array $payload = []): void
{
    q('INSERT INTO vchat_events (meeting_code, to_peer, from_peer, type, payload, created_at) VALUES (?,?,?,?,?,?)',
        [$code, $to, $from, $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), time()]);
}

function participant_by_peer(string $peerId): ?array
{
    $r = q('SELECT p.*, u.name, u.picture FROM vchat_participants p JOIN vchat_users u ON u.id = p.user_id WHERE p.peer_id = ?', [$peerId])->fetch();
    return $r ?: null;
}

function participants_of(string $code): array
{
    return q('SELECT p.peer_id, p.user_id, p.state, u.name, u.picture FROM vchat_participants p JOIN vchat_users u ON u.id = p.user_id
              WHERE p.meeting_code = ? ORDER BY p.joined_at, p.peer_id', [$code])->fetchAll();
}

function pub_peer(array $r): array
{
    return ['id' => $r['peer_id'], 'name' => $r['name'], 'picture' => $r['picture'],
        'state' => json_decode($r['state'], true) ?: ['muted' => true, 'cameraOff' => true, 'sharing' => false]];
}

function remove_peer(string $code, string $peerId, ?string $notifyType = null): void
{
    if (q('DELETE FROM vchat_participants WHERE peer_id = ? AND meeting_code = ?', [$peerId, $code])->rowCount() > 0) {
        if ($notifyType) { event_add($code, $peerId, $peerId, $notifyType); }
        event_add($code, null, $peerId, 'peer-left');
    }
}

function prune_stale(string $code): void
{
    $stale = q('SELECT peer_id FROM vchat_participants WHERE meeting_code = ? AND last_seen < ?', [$code, time() - PEER_TIMEOUT])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stale as $peerId) { remove_peer($code, $peerId); }
}

function live_count(string $code): int
{
    prune_stale($code);
    return (int)q('SELECT COUNT(*) FROM vchat_participants WHERE meeting_code = ?', [$code])->fetchColumn();
}

function clean_text(mixed $s, int $max): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1f<>]/u', '', (string)$s) ?? ''), 0, $max);
}

function valid_code(mixed $c): string
{
    $c = strtolower((string)$c);
    if (!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $c)) { throw new HttpError(404, 'Meeting not found or already ended'); }
    return $c;
}

function room_info(array $user, string $code): array
{
    $m = meeting_get($code);
    if (!$m || $m['ended']) { throw new HttpError(404, 'Meeting not found or already ended'); }
    $n = live_count($code);
    return ['code' => $code, 'title' => $m['title'], 'isHost' => $m['hostId'] === $user['id'], 'participants' => $n,
        'max' => cfg('max_participants'), 'full' => $n >= cfg('max_participants')];
}

function room_join(array $user, string $code): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = meeting_get($code, true);
        if (!$m || $m['ended']) { throw new HttpError(404, 'This meeting does not exist or has ended.'); }
        if (q('SELECT 1 FROM vchat_bans WHERE meeting_code = ? AND user_id = ?', [$code, $user['id']])->fetch()) {
            throw new HttpError(403, 'You were removed from this meeting.');
        }
        prune_stale($code);
        // one seat per account: a newer connection replaces an older one
        foreach (q('SELECT peer_id FROM vchat_participants WHERE meeting_code = ? AND user_id = ?', [$code, $user['id']])->fetchAll(PDO::FETCH_COLUMN) as $old) {
            remove_peer($code, $old, 'replaced');
        }
        $max = (int)cfg('max_participants');
        if ((int)q('SELECT COUNT(*) FROM vchat_participants WHERE meeting_code = ?', [$code])->fetchColumn() >= $max) {
            throw new HttpError(409, "This meeting is full ($max people max).");
        }
        $peerId = bin2hex(random_bytes(6));
        $state = ['muted' => true, 'cameraOff' => true, 'sharing' => false];
        $others = array_map('pub_peer', participants_of($code));
        $since = (int)q('SELECT COALESCE(MAX(id), 0) FROM vchat_events WHERE meeting_code = ?', [$code])->fetchColumn();
        q('INSERT INTO vchat_participants (peer_id, meeting_code, user_id, state, last_seen, joined_at) VALUES (?,?,?,?,?,?)',
            [$peerId, $code, $user['id'], json_encode($state), time(), time()]);
        event_add($code, null, $peerId, 'peer-joined', ['peer' => ['id' => $peerId, 'name' => $user['name'], 'picture' => $user['picture'], 'state' => $state]]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return ['peer' => $peerId, 'since' => $since, 'hostUserId' => $m['hostId'], 'title' => $m['title'], 'peers' => $others, 'max' => $max];
}

/** Events for this peer since $since. Terminal events (kicked/ended/replaced) set 'gone'. */
function room_poll(array $user, string $peerId, int $since): array
{
    $hold = max(0, min(25, (int)cfg('poll_hold_seconds')));
    $deadline = microtime(true) + $hold;
    $me = participant_by_peer($peerId);
    if ($me && $me['user_id'] !== $user['id']) { $me = null; } // someone else's peer id: treat as unknown
    if ($me) { q('UPDATE vchat_participants SET last_seen = ? WHERE peer_id = ?', [time(), $peerId]); }
    $code = $me['meeting_code'] ?? null;
    if ($code === null) { // maybe kicked/ended: look for a terminal event addressed to us
        $row = q("SELECT type FROM vchat_events WHERE to_peer = ? AND type IN ('kicked','ended','replaced') ORDER BY id DESC LIMIT 1", [$peerId])->fetch();
        return ['events' => [], 'gone' => $row['type'] ?? 'left'];
    }
    if (random_int(1, 4) === 1) { prune_stale($code); }
    if (random_int(1, 25) === 1) { q('DELETE FROM vchat_events WHERE created_at < ?', [time() - EVENT_TTL]); }

    while (true) {
        // Re-read a little behind `since` so events from slow concurrent inserts are never skipped;
        // the client de-duplicates by id.
        $rows = q('SELECT id, to_peer, from_peer, type, payload, created_at FROM vchat_events
                   WHERE meeting_code = ? AND (id > ? OR (id > ? AND created_at >= ?)) AND (to_peer = ? OR (to_peer IS NULL AND from_peer <> ?))
                   ORDER BY id LIMIT 300', [$code, $since, $since - 40, time() - 3, $peerId, $peerId])->fetchAll();
        if ($rows || microtime(true) >= $deadline) { break; }
        usleep(250000);
        q('UPDATE vchat_participants SET last_seen = ? WHERE peer_id = ?', [time(), $peerId]);
    }
    $events = []; $gone = null;
    foreach ($rows as $r) {
        $events[] = ['id' => (int)$r['id'], 'type' => $r['type'], 'from' => $r['from_peer'], 'data' => json_decode($r['payload'], true)];
        if (in_array($r['type'], ['kicked', 'ended', 'replaced'], true) && $r['to_peer'] === $peerId) { $gone = $r['type']; }
    }
    return ['events' => $events, 'gone' => $gone];
}

function room_send(array $user, string $peerId, array $msgs): void
{
    $me = participant_by_peer($peerId);
    if (!$me || $me['user_id'] !== $user['id']) { throw new HttpError(410, 'You are not in this meeting.'); }
    $code = $me['meeting_code'];
    $meeting = meeting_get($code);
    if (!$meeting || $meeting['ended']) { throw new HttpError(410, 'This meeting has ended.'); }
    $isHost = $meeting['hostId'] === $user['id'];
    if (!rl_hit("room-send:$peerId", 1200, 60)) { throw new HttpError(429, 'Slow down.'); }

    foreach (array_slice($msgs, 0, 80) as $m) {
        if (!is_array($m)) { continue; }
        switch ($m['type'] ?? '') {
            case 'signal':
                $to = (string)($m['to'] ?? '');
                $d = $m['data'] ?? null;
                if (!is_array($d) || $to === $peerId || strlen(json_encode($d)) > 40000) { break; }
                $t = q('SELECT 1 FROM vchat_participants WHERE peer_id = ? AND meeting_code = ?', [$to, $code])->fetch();
                if ($t) { event_add($code, $to, $peerId, 'signal', $d); }
                break;
            case 'state':
                $s = ['muted' => !empty($m['muted']), 'cameraOff' => !empty($m['cameraOff']), 'sharing' => !empty($m['sharing'])];
                q('UPDATE vchat_participants SET state = ? WHERE peer_id = ?', [json_encode($s), $peerId]);
                event_add($code, null, $peerId, 'state', $s);
                break;
            case 'chat':
                $text = mb_substr(trim((string)($m['text'] ?? '')), 0, 2000);
                if ($text !== '') { event_add($code, null, $peerId, 'chat', ['name' => $user['name'], 'text' => $text, 'ts' => time() * 1000]); }
                break;
            case 'kick':
                $target = participant_by_peer((string)($m['id'] ?? ''));
                if ($isHost && $target && $target['meeting_code'] === $code && $target['peer_id'] !== $peerId) {
                    if (!q('SELECT 1 FROM vchat_bans WHERE meeting_code = ? AND user_id = ?', [$code, $target['user_id']])->fetch()) {
                        q('INSERT INTO vchat_bans (meeting_code, user_id) VALUES (?, ?)', [$code, $target['user_id']]);
                    }
                    remove_peer($code, $target['peer_id'], 'kicked');
                }
                break;
            case 'end':
                if ($isHost) {
                    q('UPDATE vchat_meetings SET ended = 1 WHERE code = ?', [$code]);
                    foreach (participants_of($code) as $p) { event_add($code, $p['peer_id'], $peerId, 'ended'); }
                    q('DELETE FROM vchat_participants WHERE meeting_code = ?', [$code]);
                    return;
                }
                break;
        }
    }
}

function room_leave(array $user, string $peerId): void
{
    $me = participant_by_peer($peerId);
    if ($me && $me['user_id'] === $user['id']) { remove_peer($me['meeting_code'], $peerId); }
}
