<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/** Combo Clash Party Mode: teams, 3×90s target sessions, chat, voice rooms. */
final class Party
{
    public const SESSION_SECS = 90;
    public const INTRO_SECS = 5;
    public const INVITE_SECS = 12;
    public const MAX_SIZE = 4;
    public const QUEUE_STALE = 20;
    public const PLAY_GAP_MS = 400;
    public const DISCONNECT_SECS = 35;

    private const TARGETS = [
        ['Ocean', '🌊'], ['Steam', '💨'], ['Tsunami', '🌊'], ['Storm', '⛈️'],
        ['Mud', '🟤'], ['Lava', '🌋'], ['Dragon', '🐉'], ['Plant', '🌱'],
        ['Rain', '🌧️'], ['Cloud', '☁️'], ['Energy', '⚡'], ['Wave', '🌊'],
        ['Cat Army', '🐱'], ['Zoomies Apocalypse', '🌀'], ['Inferno Feline', '🔥'],
        ['Wood-Fired Pizza', '🍕'], ['Tears of Joy', '😂'], ['Hot Take', '🔥'],
        ['Meme Overload', '😂'], ['Life', '🌱'], ['Dust', '💨'], ['Brick', '🧱'],
    ];

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function decorateInbox(array $p): array
    {
        self::expireInvites();
        self::touchMember($p);
        $party = self::currentParty($p);
        if ($party) {
            $pm = self::tickPartyMatch($party, $p);
            if ($pm) {
                // keep snapshot after tick
            }
        }
        return [
            'party' => $party ? self::partyView($party, $p) : null,
            'partyInvites' => array_map(fn ($c) => self::inviteView($c, $p), self::incomingInvites($p)),
            'recentPeople' => self::recentPeople($p),
            'lastScore' => self::lastScore($p),
            'partyMatch' => ($party && ($pm = self::livePartyMatch($party))) ? self::matchView($pm, $p) : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function replays(int $limit = 8): array
    {
        $rows = Db::all('SELECT payload, kind, created_at FROM cc_clash_replays ORDER BY id DESC LIMIT ' . max(1, min(20, $limit)));
        $out = [];
        foreach ($rows as $r) {
            $p = json_decode((string) $r['payload'], true);
            if (is_array($p)) {
                $p['kind'] = $r['kind'];
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Hook from 1v1 Clash finish. @param array<string,mixed> $m */
    public static function recordDuel(array $m): void
    {
        try {
            $rounds = Db::all('SELECT * FROM cc_clash_rounds WHERE match_id = ? ORDER BY n', [$m['id']]);
            $plays = [];
            foreach (Db::all(
                'SELECT p.*, r.n, r.theme FROM cc_clash_plays p JOIN cc_clash_rounds r ON r.id = p.round_id WHERE r.match_id = ? ORDER BY p.id',
                [$m['id']]
            ) as $pl) {
                $plays[] = [
                    'side' => $pl['player_id'] === $m['a_id'] ? 'a' : 'b',
                    'a' => $pl['a_name'],
                    'b' => $pl['b_name'],
                    'name' => $pl['result_name'],
                    'emoji' => $pl['result_emoji'],
                    'score' => (int) $pl['score'],
                ];
            }
            $a = self::mini((string) $m['a_id']);
            $b = self::mini((string) $m['b_id']);
            $tot = Db::one('SELECT COALESCE(SUM(a_score),0) AS a, COALESCE(SUM(b_score),0) AS b FROM cc_clash_rounds WHERE match_id = ?', [$m['id']]);
            $payload = [
                'matchId' => $m['id'],
                'a' => $a,
                'b' => $b,
                'aScore' => (int) ($tot['a'] ?? 0),
                'bScore' => (int) ($tot['b'] ?? 0),
                'aWins' => (int) $m['a_wins'],
                'bWins' => (int) $m['b_wins'],
                'theme' => $rounds[0]['theme'] ?? 'Combo Clash',
                'plays' => $plays,
                'winner' => $m['winner_id'] ? self::mini((string) $m['winner_id']) : null,
            ];
            Db::run('INSERT INTO cc_clash_replays (kind, match_id, payload, created_at) VALUES (?,?,?,?)', ['duel', $m['id'], json_encode($payload, JSON_UNESCAPED_UNICODE), Db::now()]);
        } catch (Throwable $e) {
            error_log('[combocraft replay] ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $p */
    public static function invite(array $p, string $nickname): array
    {
        self::needName($p);
        $key = mb_strtolower(trim($nickname));
        $to = Db::one('SELECT * FROM cc_players WHERE nick_key = ?', [$key]);
        if (!$to) {
            throw new HttpError('No player with that nickname is on ComboCraft yet.', 404);
        }
        if ($to['id'] === $p['id']) {
            throw new HttpError('You cannot invite yourself.', 422);
        }
        $party = self::currentParty($p);
        if (!$party) {
            $party = self::createParty($p);
        } else {
            if ($party['host_id'] !== $p['id']) {
                throw new HttpError('Only the party host can invite people.', 403);
            }
            $n = (int) Db::value('SELECT COUNT(*) FROM cc_clash_party_members WHERE party_id = ?', [$party['id']]);
            if ($n >= self::MAX_SIZE) {
                throw new HttpError('This party is full (4).', 422);
            }
        }
        if (Db::one('SELECT player_id FROM cc_clash_party_members WHERE party_id = ? AND player_id = ?', [$party['id'], $to['id']])) {
            throw new HttpError('They are already in your party.', 409);
        }
        $id = self::id('i');
        Db::run(
            'INSERT INTO cc_clash_party_invites (id, party_id, from_id, to_id, created_at, expires_at, status) VALUES (?,?,?,?,?,?,?)',
            [$id, $party['id'], $p['id'], $to['id'], Db::now(), gmdate('Y-m-d H:i:s', time() + self::INVITE_SECS), 'pending']
        );
        return [
            'invite' => self::inviteView(Db::one('SELECT * FROM cc_clash_party_invites WHERE id = ?', [$id]), $p),
            'party' => self::partyView(self::getParty($party['id']), $p),
        ];
    }

    /** @param array<string,mixed> $p */
    public static function respond(array $p, string $id, bool $accept): array
    {
        self::needName($p);
        self::expireInvites();
        $c = Db::one('SELECT * FROM cc_clash_party_invites WHERE id = ?', [$id]);
        if (!$c || $c['to_id'] !== $p['id']) {
            throw new HttpError('That party invite is not for you.', 404);
        }
        if ($c['status'] !== 'pending' || strtotime((string) $c['expires_at'] . ' UTC') < time()) {
            Db::run("UPDATE cc_clash_party_invites SET status = 'expired' WHERE id = ? AND status = 'pending'", [$id]);
            throw new HttpError('That party invite expired.', 410);
        }
        if (!$accept) {
            Db::run("UPDATE cc_clash_party_invites SET status = 'declined' WHERE id = ?", [$id]);
            return ['accepted' => false];
        }
        if (self::currentParty($p)) {
            throw new HttpError('Leave your current party first.', 409);
        }
        $party = self::getParty((string) $c['party_id']);
        if ($party['status'] === 'disbanded') {
            throw new HttpError('That party is gone.', 410);
        }
        $n = (int) Db::value('SELECT COUNT(*) FROM cc_clash_party_members WHERE party_id = ?', [$party['id']]);
        if ($n >= self::MAX_SIZE) {
            throw new HttpError('That party just filled up.', 409);
        }
        Db::run(
            'INSERT INTO cc_clash_party_members (party_id, player_id, role, joined_at, last_seen) VALUES (?,?,?,?,?)',
            [$party['id'], $p['id'], 'member', Db::now(), Db::now()]
        );
        Db::run("UPDATE cc_clash_party_invites SET status = 'accepted' WHERE id = ?", [$id]);
        Db::run('UPDATE cc_clash_parties SET updated_at = ? WHERE id = ?', [Db::now(), $party['id']]);
        $party = self::getParty($party['id']);
        return [
            'accepted' => true,
            'needsName' => trim((string) $party['name']) === '',
            'party' => self::partyView($party, $p),
        ];
    }

    /** @param array<string,mixed> $p */
    public static function setName(array $p, string $name): array
    {
        $party = self::requireParty($p);
        if ($party['host_id'] !== $p['id']) {
            throw new HttpError('Only the host can name the party.', 403);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (!preg_match('/^[\p{L}\p{N}_.\- ]{2,20}$/u', $name)) {
            throw new HttpError('Party names are 2–20 letters, numbers, spaces, dots, dashes or underscores.', 422);
        }
        if (!Text::isSafe($name)) {
            throw new HttpError('Please pick a different party name.', 422);
        }
        Db::run('UPDATE cc_clash_parties SET name = ?, updated_at = ? WHERE id = ?', [$name, Db::now(), $party['id']]);
        return ['party' => self::partyView(self::getParty($party['id']), $p)];
    }

    /** @param array<string,mixed> $p */
    public static function leave(array $p): array
    {
        $party = self::currentParty($p);
        if (!$party) {
            return ['party' => null];
        }
        self::removeMember($party, $p['id'], false);
        return ['party' => null];
    }

    /** @param array<string,mixed> $p */
    public static function boot(array $p, string $playerId): array
    {
        $party = self::requireParty($p);
        if ($party['host_id'] !== $p['id']) {
            throw new HttpError('Only the host can boot players.', 403);
        }
        if ($playerId === $p['id']) {
            throw new HttpError('Use Leave Party if you want to go.', 422);
        }
        if (!Db::one('SELECT player_id FROM cc_clash_party_members WHERE party_id = ? AND player_id = ?', [$party['id'], $playerId])) {
            throw new HttpError('They are not in this party.', 404);
        }
        self::removeMember($party, $playerId, true);
        return ['party' => self::partyView(self::getParty($party['id']), $p)];
    }

    /** @param array<string,mixed> $p */
    public static function ready(array $p, string $pair = ''): array
    {
        self::needName($p);
        $pair = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $pair) ?? '');
        if (strlen($pair) > 32) {
            $pair = substr($pair, 0, 32);
        }
        $party = self::currentParty($p);
        if (!$party) {
            $party = self::createParty($p);
            $solo = ($p['nickname'] ?: 'Solo');
            Db::run('UPDATE cc_clash_parties SET name = ?, updated_at = ? WHERE id = ?', [$solo, Db::now(), $party['id']]);
            $party = self::getParty($party['id']);
        }
        if (self::livePartyMatch($party)) {
            return ['queued' => false, 'match' => self::matchView(self::livePartyMatch($party), $p), 'party' => self::partyView($party, $p)];
        }
        $size = (int) Db::value('SELECT COUNT(*) FROM cc_clash_party_members WHERE party_id = ?', [$party['id']]);
        $now = Db::now();
        $stale = gmdate('Y-m-d H:i:s', time() - self::QUEUE_STALE);
        Db::run('DELETE FROM cc_clash_party_queue WHERE last_seen < ?', [$stale]);
        $other = Db::one(
            'SELECT * FROM cc_clash_party_queue WHERE party_id <> ? AND last_seen >= ? AND pair = ? AND size = ? ORDER BY queued_at ASC',
            [$party['id'], $stale, $pair, $size]
        );
        if ($other) {
            Db::run('DELETE FROM cc_clash_party_queue WHERE party_id IN (?,?)', [$party['id'], $other['party_id']]);
            $m = self::createMatch($party, self::getParty((string) $other['party_id']));
            return ['queued' => false, 'match' => self::matchView($m, $p), 'party' => self::partyView($party, $p)];
        }
        Db::upsert(
            'cc_clash_party_queue',
            ['party_id' => $party['id'], 'size' => $size, 'pair' => $pair, 'queued_at' => $now, 'last_seen' => $now],
            ['party_id'],
            ['size', 'pair', 'last_seen']
        );
        Db::run("UPDATE cc_clash_parties SET status = 'queued', updated_at = ? WHERE id = ?", [$now, $party['id']]);
        return ['queued' => true, 'match' => null, 'party' => self::partyView(self::getParty($party['id']), $p)];
    }

    /** @param array<string,mixed> $p */
    public static function cancelReady(array $p): array
    {
        $party = self::currentParty($p);
        if ($party) {
            Db::run('DELETE FROM cc_clash_party_queue WHERE party_id = ?', [$party['id']]);
            Db::run("UPDATE cc_clash_parties SET status = 'open', updated_at = ? WHERE id = ? AND status = 'queued'", [Db::now(), $party['id']]);
            $party = self::getParty($party['id']);
        }
        return ['queued' => false, 'party' => $party ? self::partyView($party, $p) : null];
    }

    /** @param array<string,mixed> $p */
    public static function match(array $p, string $id): array
    {
        $m = self::getMatch($id);
        self::tickMatch($m);
        $m = self::getMatch($id);
        if (!self::inMatch($m, $p['id'])) {
            throw new HttpError('This is not your match.', 403);
        }
        self::touchMember($p);
        self::sweepDisconnect($m);
        $m = self::getMatch($id);
        return ['match' => self::matchView($m, $p), 'now' => Db::nowMs()];
    }

    /** @param array<string,mixed> $p */
    public static function play(array $p, string $matchId, string $aTok, string $bTok): array
    {
        $m = self::getMatch($matchId);
        $m = self::tickMatch($m);
        if (!self::inMatch($m, $p['id'])) {
            throw new HttpError('This is not your match.', 403);
        }
        $partyId = self::partyIdOf($m, $p['id']);
        $side = $m['a_party'] === $partyId ? 'a' : 'b';
        if ($m['status'] === 'intro') {
            throw new HttpError('The match is still introducing both parties.', 422);
        }
        if (!(int) $m[$side . '_alive']) {
            throw new HttpError('Your team is spectating — this session is over for you.', 422);
        }
        if ($m['status'] !== 'playing') {
            throw new HttpError('This session is not in play.', 422);
        }
        $ends = $m[$side . '_ends_at'] ?? null;
        if ($ends && strtotime((string) $ends . ' UTC') <= time()) {
            $m = self::tickMatch(self::getMatch($matchId));
            throw new HttpError('Time is up.', 422);
        }
        $hand = self::hand($p, $m, $side);
        $ea = $hand[$aTok] ?? null;
        $eb = $hand[$bTok] ?? null;
        if (!$ea || !$eb) {
            throw new HttpError('Combine two cards from your discoveries or this session.', 422);
        }
        $last = (int) (Store::get('pp:' . $p['id'] . ':' . $matchId) ?? 0);
        $nowMs = Db::nowMs();
        if ($last && ($nowMs - $last) < self::PLAY_GAP_MS) {
            throw new HttpError('A little slower — combos need a beat.', 429);
        }
        $made = self::craft($ea, $eb);
        $session = (int) $m[$side . '_session'] + 1; // current attempt session number
        // a_session is completed sessions; current is a_session+1 while alive
        $cur = (int) $m[$side . '_session'] + 1;
        $hit = Text::key($made['name']) === Text::key((string) $m['target_name']);
        Db::run(
            'INSERT INTO cc_clash_party_plays (match_id, session, party_id, player_id, a_name, b_name, result_name, result_emoji, result_tagline, rarity, is_hit, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$matchId, $cur, $partyId, $p['id'], $ea['name'], $eb['name'], $made['name'], $made['emoji'], $made['tagline'], $made['rarity'], $hit ? 1 : 0, Db::now()]
        );
        try {
            Store::set('pp:' . $p['id'] . ':' . $matchId, (string) $nowMs);
        } catch (Throwable $e) {
        }
        if ($hit) {
            self::advanceTeam($m, $side);
        }
        $m = self::getMatch($matchId);
        return [
            'play' => [
                'token' => 't:' . (int) Db::value('SELECT MAX(id) FROM cc_clash_party_plays WHERE match_id = ? AND player_id = ?', [$matchId, $p['id']]),
                'name' => $made['name'],
                'emoji' => $made['emoji'],
                'tagline' => $made['tagline'],
                'rarity' => $made['rarity'],
                'hit' => $hit,
                'inputs' => [$ea['name'], $eb['name']],
            ],
            'match' => self::matchView($m, $p),
            'now' => Db::nowMs(),
        ];
    }

    /** @param array<string,mixed> $p */
    public static function chatPost(array $p, string $body): array
    {
        $party = self::requireParty($p);
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));
        if ($body === '' || mb_strlen($body) > 240) {
            throw new HttpError('Type a short message for your team.', 422);
        }
        if (!Text::isSafe($body)) {
            throw new HttpError('Please keep team chat friendly.', 422);
        }
        Db::run(
            'INSERT INTO cc_clash_party_chat (party_id, match_id, player_id, body, created_at) VALUES (?,?,?,?,?)',
            [$party['id'], null, $p['id'], $body, Db::now()]
        );
        return self::chatList($p, 0);
    }

    /** @param array<string,mixed> $p */
    public static function chatList(array $p, int $after = 0): array
    {
        $party = self::currentParty($p);
        if (!$party) {
            return ['messages' => []];
        }
        $rows = Db::all(
            'SELECT * FROM cc_clash_party_chat WHERE party_id = ? AND id > ? ORDER BY id DESC LIMIT 50',
            [$party['id'], $after]
        );
        $rows = array_reverse($rows);
        $msgs = [];
        foreach ($rows as $r) {
            $who = self::mini((string) $r['player_id']);
            $msgs[] = [
                'id' => (int) $r['id'],
                'from' => $who,
                'mine' => $r['player_id'] === $p['id'],
                'body' => $r['body'],
                'at' => Db::toMs($r['created_at']),
            ];
        }
        return ['messages' => $msgs];
    }

    /** @param array<string,mixed> $p */
    public static function exitMatch(array $p, string $matchId): array
    {
        $m = self::getMatch($matchId);
        if (!self::inMatch($m, $p['id'])) {
            throw new HttpError('This is not your match.', 403);
        }
        // Leaving the match UI does not disband the party. If this was the last
        // living teammate still playing, the team fails the current session.
        return ['ok' => true, 'party' => self::currentParty($p) ? self::partyView(self::currentParty($p), $p) : null];
    }

    /** Morse-style WebRTC signaling over additive cc_clash_rtc_* tables. */
    public static function rtc(): never
    {
        if (Http::method() === 'GET') {
            $room = (string) ($_GET['room'] ?? '');
            $peer = (string) ($_GET['peer'] ?? '');
            $name = (string) ($_GET['name'] ?? '');
            $since = (int) ($_GET['since'] ?? 0);
            if (!self::rtcId($room) || !self::rtcId($peer) || mb_strlen($name) > 64 || $since < 0) {
                throw new HttpError('invalid query', 400);
            }
            self::assertRtcRoom($room, Player::require());
            if ($since === 0 || random_int(0, 49) === 0) {
                self::rtcPrune();
            }
            Db::upsert(
                'cc_clash_rtc_peers',
                ['room' => $room, 'peer_id' => $peer, 'name' => $name, 'last_seen' => Db::now()],
                ['room', 'peer_id'],
                ['name', 'last_seen']
            );
            $rows = Db::all(
                'SELECT id, from_peer, kind, payload FROM cc_clash_rtc_signals WHERE room = ? AND to_peer = ? AND id > ? ORDER BY id LIMIT 200',
                [$room, $peer, $since]
            );
            $peers = Db::all(
                'SELECT peer_id, name FROM cc_clash_rtc_peers WHERE room = ? AND last_seen > ? ORDER BY peer_id LIMIT 32',
                [$room, gmdate('Y-m-d H:i:s', time() - 30)]
            );
            Http::json([
                'ok' => true,
                'peers' => array_map(fn ($r) => ['id' => $r['peer_id'], 'name' => $r['name']], $peers),
                'signals' => array_map(fn ($r) => [
                    'id' => (int) $r['id'],
                    'from' => $r['from_peer'],
                    'kind' => $r['kind'],
                    'payload' => json_decode((string) $r['payload'], true),
                ], $rows),
            ]);
        }
        if (Http::method() !== 'POST') {
            throw new HttpError('Method not allowed', 405);
        }
        Http::assertSameSite();
        $b = Http::jsonBody();
        $op = $b['op'] ?? '';
        $room = (string) ($b['room'] ?? '');
        $p = Player::require();
        self::assertRtcRoom($room, $p);
        if ($op === 'signal') {
            $kind = $b['kind'] ?? '';
            $encoded = array_key_exists('payload', $b) ? json_encode($b['payload']) : false;
            if (!self::rtcId($room) || !self::rtcId((string) ($b['from'] ?? '')) || !self::rtcId((string) ($b['to'] ?? ''))
                || !in_array($kind, ['offer', 'answer', 'ice'], true)
                || $encoded === false || $b['payload'] === null || strlen($encoded) > 32768) {
                throw new HttpError('invalid request', 400);
            }
            if ((string) $b['from'] !== $p['id'] && (string) $b['from'] !== ('p' . $p['id'])) {
                // allow player id as peer
            }
            Db::run(
                'INSERT INTO cc_clash_rtc_signals (room, to_peer, from_peer, kind, payload, created_at) VALUES (?,?,?,?,?,?)',
                [$room, $b['to'], $b['from'], $kind, $encoded, Db::now()]
            );
            Http::json(['ok' => true]);
        }
        if ($op === 'leave' && self::rtcId($room) && self::rtcId((string) ($b['peer'] ?? ''))) {
            Db::run('DELETE FROM cc_clash_rtc_peers WHERE room = ? AND peer_id = ?', [$room, $b['peer']]);
            Http::json(['ok' => true]);
        }
        throw new HttpError('invalid request', 400);
    }

    private static function rtcId(string $v): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $v) === 1;
    }

    /** @param array<string,mixed> $p */
    private static function assertRtcRoom(string $room, array $p): void
    {
        if (!str_starts_with($room, 'party-')) {
            throw new HttpError('invalid room', 400);
        }
        $pid = substr($room, 6);
        $row = Db::one('SELECT player_id FROM cc_clash_party_members WHERE party_id = ? AND player_id = ?', [$pid, $p['id']]);
        if (!$row) {
            throw new HttpError('Join the party to use voice.', 403);
        }
    }

    private static function rtcPrune(): void
    {
        Db::run('DELETE FROM cc_clash_rtc_signals WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 60)]);
        Db::run('DELETE FROM cc_clash_rtc_peers WHERE last_seen < ?', [gmdate('Y-m-d H:i:s', time() - 30)]);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function createParty(array $p): array
    {
        $id = self::id('y');
        $now = Db::now();
        Db::run(
            'INSERT INTO cc_clash_parties (id, name, host_id, status, created_at, updated_at) VALUES (?,?,?,?,?,?)',
            [$id, '', $p['id'], 'open', $now, $now]
        );
        Db::run(
            'INSERT INTO cc_clash_party_members (party_id, player_id, role, joined_at, last_seen) VALUES (?,?,?,?,?)',
            [$id, $p['id'], 'host', $now, $now]
        );
        return self::getParty($id);
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function createMatch(array $a, array $b): array
    {
        $id = self::id('g');
        $now = time();
        $intro = gmdate('Y-m-d H:i:s', $now + self::INTRO_SECS);
        $aName = trim((string) $a['name']) !== '' ? (string) $a['name'] : 'Party';
        $bName = trim((string) $b['name']) !== '' ? (string) $b['name'] : 'Party';
        $target = self::TARGETS[abs(crc32($id . '|1')) % count(self::TARGETS)];
        Db::run(
            'INSERT INTO cc_clash_party_matches (id, a_party, b_party, a_name, b_name, status, session, target_name, target_emoji, a_session, b_session, a_alive, b_alive, a_ends_at, b_ends_at, intro_until, winner_party, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $a['id'], $b['id'], $aName, $bName, 'intro', 1, $target[0], $target[1], 0, 0, 1, 1, null, null, $intro, null, Db::now(), Db::now()]
        );
        Db::run("UPDATE cc_clash_parties SET status = 'playing', updated_at = ? WHERE id IN (?,?)", [Db::now(), $a['id'], $b['id']]);
        return self::getMatch($id);
    }

    /** @param array<string,mixed> $m @return array<string,mixed> */
    private static function tickMatch(array $m): array
    {
        if (in_array($m['status'], ['finished'], true)) {
            return $m;
        }
        $now = time();
        if ($m['status'] === 'intro') {
            $until = $m['intro_until'] ? strtotime((string) $m['intro_until'] . ' UTC') : 0;
            if ($until && $until <= $now) {
                $end = gmdate('Y-m-d H:i:s', $now + self::SESSION_SECS);
                Db::run(
                    "UPDATE cc_clash_party_matches SET status = 'playing', session = 1, a_ends_at = ?, b_ends_at = ?, updated_at = ? WHERE id = ?",
                    [$end, $end, Db::now(), $m['id']]
                );
                return self::getMatch($m['id']);
            }
            return $m;
        }
        if ($m['status'] === 'playing') {
            foreach (['a', 'b'] as $side) {
                if ((int) $m[$side . '_alive'] === 1 && !empty($m[$side . '_ends_at']) && strtotime((string) $m[$side . '_ends_at'] . ' UTC') <= $now) {
                    // Timed out this session without a hit → team defeated.
                    Db::run("UPDATE cc_clash_party_matches SET {$side}_alive = 0, updated_at = ? WHERE id = ? AND {$side}_alive = 1", [Db::now(), $m['id']]);
                }
            }
            $m = self::getMatch($m['id']);
            $aA = (int) $m['a_alive'];
            $bA = (int) $m['b_alive'];
            if ($aA === 0 || $bA === 0) {
                $winner = null;
                if ($aA && !$bA) {
                    $winner = $m['a_party'];
                } elseif ($bA && !$aA) {
                    $winner = $m['b_party'];
                } else {
                    // both dead this tick: higher session wins, else a
                    $winner = ((int) $m['a_session'] >= (int) $m['b_session']) ? $m['a_party'] : $m['b_party'];
                }
                self::finishMatch($m, (string) $winner);
                return self::getMatch($m['id']);
            }
            if ((int) $m['a_session'] >= 3 || (int) $m['b_session'] >= 3) {
                $winner = ((int) $m['a_session'] >= (int) $m['b_session']) ? $m['a_party'] : $m['b_party'];
                self::finishMatch($m, (string) $winner);
                return self::getMatch($m['id']);
            }
        }
        return $m;
    }

    /** @param array<string,mixed> $m */
    private static function advanceTeam(array $m, string $side): void
    {
        $done = (int) $m[$side . '_session'] + 1;
        if ($done >= 3) {
            Db::run("UPDATE cc_clash_party_matches SET {$side}_session = 3, updated_at = ? WHERE id = ?", [Db::now(), $m['id']]);
            $m = self::getMatch($m['id']);
            self::finishMatch($m, (string) $m[$side . '_party']);
            return;
        }
        // Next session: new target, new 90s. Opponent keeps their own clock.
        $nextN = $done + 1;
        $target = self::TARGETS[abs(crc32($m['id'] . '|' . $nextN . '|' . $side)) % count(self::TARGETS)];
        // If the other team is still on an earlier session, keep the displayed
        // global target as the trailing team's target; the advancing team
        // stores their next target in target_name when both are in sync, else
        // we keep per-side by updating target when this side is the 'session' leader.
        $end = gmdate('Y-m-d H:i:s', time() + self::SESSION_SECS);
        $other = $side === 'a' ? 'b' : 'a';
        $otherSess = (int) $m[$other . '_session'];
        if ($done > $otherSess) {
            Db::run(
                "UPDATE cc_clash_party_matches SET {$side}_session = ?, session = ?, target_name = ?, target_emoji = ?, {$side}_ends_at = ?, updated_at = ? WHERE id = ?",
                [$done, $nextN, $target[0], $target[1], $end, Db::now(), $m['id']]
            );
        } else {
            Db::run(
                "UPDATE cc_clash_party_matches SET {$side}_session = ?, {$side}_ends_at = ?, updated_at = ? WHERE id = ?",
                [$done, $end, Db::now(), $m['id']]
            );
        }
        // When both complete the same session, bump shared target.
        $m2 = self::getMatch($m['id']);
        if ((int) $m2['a_session'] === (int) $m2['b_session'] && (int) $m2['a_session'] === $done) {
            $t = self::TARGETS[abs(crc32($m['id'] . '|' . ($done + 1) . '|both')) % count(self::TARGETS)];
            $end2 = gmdate('Y-m-d H:i:s', time() + self::SESSION_SECS);
            Db::run(
                'UPDATE cc_clash_party_matches SET session = ?, target_name = ?, target_emoji = ?, a_ends_at = ?, b_ends_at = ?, updated_at = ? WHERE id = ?',
                [$done + 1, $t[0], $t[1], $end2, $end2, Db::now(), $m['id']]
            );
        }
    }

    /** @param array<string,mixed> $m */
    private static function finishMatch(array $m, string $winnerParty): void
    {
        Db::run(
            "UPDATE cc_clash_party_matches SET status = 'finished', winner_party = ?, updated_at = ? WHERE id = ? AND status <> 'finished'",
            [$winnerParty, Db::now(), $m['id']]
        );
        Db::run("UPDATE cc_clash_parties SET status = 'open', updated_at = ? WHERE id IN (?,?)", [Db::now(), $m['a_party'], $m['b_party']]);
        try {
            $payload = [
                'matchId' => $m['id'],
                'aName' => $m['a_name'],
                'bName' => $m['b_name'],
                'aSession' => (int) $m['a_session'],
                'bSession' => (int) $m['b_session'],
                'winner' => $winnerParty,
                'plays' => [],
            ];
            foreach (Db::all('SELECT * FROM cc_clash_party_plays WHERE match_id = ? ORDER BY id', [$m['id']]) as $pl) {
                $payload['plays'][] = [
                    'side' => $pl['party_id'] === $m['a_party'] ? 'a' : 'b',
                    'a' => $pl['a_name'],
                    'b' => $pl['b_name'],
                    'name' => $pl['result_name'],
                    'emoji' => $pl['result_emoji'],
                    'hit' => (int) $pl['is_hit'] === 1,
                ];
            }
            Db::run('INSERT INTO cc_clash_replays (kind, match_id, payload, created_at) VALUES (?,?,?,?)', ['party', $m['id'], json_encode($payload, JSON_UNESCAPED_UNICODE), Db::now()]);
        } catch (Throwable $e) {
        }
    }

    /** @param array<string,mixed> $party @param array<string,mixed> $p */
    private static function tickPartyMatch(array $party, array $p): ?array
    {
        $m = self::livePartyMatch($party);
        if (!$m) {
            return null;
        }
        return self::tickMatch($m);
    }

    /** @param array<string,mixed> $m */
    private static function sweepDisconnect(array $m): void
    {
        if ($m['status'] === 'finished') {
            return;
        }
        foreach ([$m['a_party'], $m['b_party']] as $pid) {
            $members = Db::all('SELECT player_id, last_seen FROM cc_clash_party_members WHERE party_id = ?', [$pid]);
            $fresh = 0;
            foreach ($members as $mem) {
                $t = strtotime((string) $mem['last_seen'] . ' UTC');
                if ($t && (time() - $t) <= self::DISCONNECT_SECS) {
                    $fresh++;
                }
            }
            if ($fresh === 0 && $members) {
                $side = $pid === $m['a_party'] ? 'a' : 'b';
                if ((int) $m[$side . '_alive']) {
                    Db::run("UPDATE cc_clash_party_matches SET {$side}_alive = 0, updated_at = ? WHERE id = ?", [Db::now(), $m['id']]);
                    $m = self::getMatch($m['id']);
                    $other = $side === 'a' ? 'b' : 'a';
                    if ((int) $m[$other . '_alive']) {
                        self::finishMatch($m, (string) $m[$other . '_party']);
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $p @param array<string,mixed> $m @return array<string,array<string,mixed>> */
    private static function hand(array $p, array $m, string $side): array
    {
        $out = [];
        foreach (Game::collection($p) as $e) {
            $tok = 'e:' . (int) $e['id'];
            $out[$tok] = $e + ['token' => $tok, 'temp' => false];
        }
        $cur = (int) $m[$side . '_session'] + 1;
        $plays = Db::all(
            'SELECT * FROM cc_clash_party_plays WHERE match_id = ? AND player_id = ? AND session = ? ORDER BY id',
            [$m['id'], $p['id'], $cur]
        );
        foreach ($plays as $pl) {
            $tok = 't:' . (int) $pl['id'];
            $out[$tok] = [
                'token' => $tok,
                'temp' => true,
                'name' => $pl['result_name'],
                'emoji' => Text::safeEmoji((string) $pl['result_emoji']),
                'tagline' => $pl['result_tagline'],
                'rarity' => $pl['rarity'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $ea @param array<string,mixed> $eb @return array{name:string,emoji:string,tagline:string,rarity:string} */
    private static function craft(array $ea, array $eb): array
    {
        $depth = max((int) ($ea['depth'] ?? 1), (int) ($eb['depth'] ?? 1)) + 1;
        if (empty($ea['temp']) && empty($eb['temp']) && !empty($ea['id']) && !empty($eb['id'])) {
            $x = min((int) $ea['id'], (int) $eb['id']);
            $y = max((int) $ea['id'], (int) $eb['id']);
            $recipe = Db::one('SELECT e.* FROM cc_recipes r JOIN cc_elements e ON e.id = r.result_id WHERE r.a_id = ? AND r.b_id = ?', [$x, $y]);
            if ($recipe) {
                $v = Game::view($recipe);
                return ['name' => $v['name'], 'emoji' => $v['emoji'], 'tagline' => $v['tagline'] ?? '', 'rarity' => $v['rarity'] ?? Game::rarity((int) $recipe['depth'])];
            }
            $seed = Seeds::RECIPES[Seeds::key((string) $ea['name'], (string) $eb['name'])] ?? null;
            if ($seed) {
                return ['name' => $seed[0], 'emoji' => Text::safeEmoji((string) $seed[1]), 'tagline' => $seed[2], 'rarity' => Game::rarity($depth)];
            }
        }
        $made = Remix::make($ea, $eb);
        return ['name' => $made['name'], 'emoji' => Text::safeEmoji((string) ($made['emoji'] ?? '✨')), 'tagline' => $made['tagline'], 'rarity' => Game::rarity($depth)];
    }

    /** @param array<string,mixed> $p */
    private static function currentParty(array $p): ?array
    {
        $row = Db::one(
            "SELECT py.* FROM cc_clash_party_members m JOIN cc_clash_parties py ON py.id = m.party_id WHERE m.player_id = ? AND py.status <> 'disbanded' ORDER BY m.joined_at DESC",
            [$p['id']]
        );
        return $row ?: null;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function requireParty(array $p): array
    {
        $party = self::currentParty($p);
        if (!$party) {
            throw new HttpError('You are not in a party.', 404);
        }
        return $party;
    }

    /** @return array<string,mixed> */
    private static function getParty(string $id): array
    {
        $p = Db::one('SELECT * FROM cc_clash_parties WHERE id = ?', [$id]);
        if (!$p) {
            throw new HttpError('Party not found.', 404);
        }
        return $p;
    }

    /** @param array<string,mixed> $party */
    private static function livePartyMatch(array $party): ?array
    {
        return Db::one(
            "SELECT * FROM cc_clash_party_matches WHERE status IN ('intro','playing') AND (a_party = ? OR b_party = ?) ORDER BY created_at DESC",
            [$party['id'], $party['id']]
        );
    }

    /** @return array<string,mixed> */
    private static function getMatch(string $id): array
    {
        $m = Db::one('SELECT * FROM cc_clash_party_matches WHERE id = ?', [$id]);
        if (!$m) {
            throw new HttpError('Match not found.', 404);
        }
        return $m;
    }

    /** @param array<string,mixed> $m */
    private static function inMatch(array $m, string $pid): bool
    {
        return (bool) Db::one(
            'SELECT player_id FROM cc_clash_party_members WHERE player_id = ? AND party_id IN (?,?)',
            [$pid, $m['a_party'], $m['b_party']]
        );
    }

    /** @param array<string,mixed> $m */
    private static function partyIdOf(array $m, string $pid): string
    {
        $row = Db::one('SELECT party_id FROM cc_clash_party_members WHERE player_id = ? AND party_id IN (?,?)', [$pid, $m['a_party'], $m['b_party']]);
        if (!$row) {
            throw new HttpError('This is not your match.', 403);
        }
        return (string) $row['party_id'];
    }

    /** @param array<string,mixed> $party */
    private static function removeMember(array $party, string $playerId, bool $booted): void
    {
        Db::run('DELETE FROM cc_clash_party_members WHERE party_id = ? AND player_id = ?', [$party['id'], $playerId]);
        $left = Db::all('SELECT player_id, role, joined_at FROM cc_clash_party_members WHERE party_id = ? ORDER BY joined_at', [$party['id']]);
        if (!$left) {
            Db::run("UPDATE cc_clash_parties SET status = 'disbanded', updated_at = ? WHERE id = ?", [Db::now(), $party['id']]);
            Db::run('DELETE FROM cc_clash_party_queue WHERE party_id = ?', [$party['id']]);
            return;
        }
        if ($party['host_id'] === $playerId) {
            $next = $left[0]['player_id'];
            Db::run("UPDATE cc_clash_party_members SET role = 'host' WHERE party_id = ? AND player_id = ?", [$party['id'], $next]);
            Db::run('UPDATE cc_clash_parties SET host_id = ?, updated_at = ? WHERE id = ?', [$next, Db::now(), $party['id']]);
        } else {
            Db::run('UPDATE cc_clash_parties SET updated_at = ? WHERE id = ?', [Db::now(), $party['id']]);
        }
        $n = count($left);
        Db::run('UPDATE cc_clash_party_queue SET size = ? WHERE party_id = ?', [$n, $party['id']]);
    }

    /** @param array<string,mixed> $p */
    private static function touchMember(array $p): void
    {
        Db::run('UPDATE cc_clash_party_members SET last_seen = ? WHERE player_id = ?', [Db::now(), $p['id']]);
    }

    private static function expireInvites(): void
    {
        Db::run("UPDATE cc_clash_party_invites SET status = 'expired' WHERE status = 'pending' AND expires_at < ?", [Db::now()]);
    }

    /** @param array<string,mixed> $p */
    private static function needName(array $p): void
    {
        if (trim((string) ($p['nickname'] ?? '')) === '') {
            throw new HttpError('Pick a display name first so people know who is in the party.', 422);
        }
    }

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private static function incomingInvites(array $p): array
    {
        return Db::all(
            "SELECT * FROM cc_clash_party_invites WHERE to_id = ? AND status = 'pending' ORDER BY created_at DESC",
            [$p['id']]
        );
    }

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private static function recentPeople(array $p): array
    {
        $ids = [];
        $rows = Db::all(
            "SELECT CASE WHEN a_id = ? THEN b_id ELSE a_id END AS oid, updated_at FROM cc_clash_matches WHERE (a_id = ? OR b_id = ?) AND status IN ('finished','forfeit') ORDER BY updated_at DESC LIMIT 20",
            [$p['id'], $p['id'], $p['id']]
        );
        foreach ($rows as $r) {
            $ids[(string) $r['oid']] = true;
        }
        $mates = Db::all(
            'SELECT DISTINCT m2.player_id AS oid FROM cc_clash_party_members m1 JOIN cc_clash_party_members m2 ON m2.party_id = m1.party_id AND m2.player_id <> m1.player_id WHERE m1.player_id = ? ORDER BY m2.joined_at DESC LIMIT 20',
            [$p['id']]
        );
        foreach ($mates as $r) {
            $ids[(string) $r['oid']] = true;
        }
        $opp = Db::all(
            'SELECT CASE WHEN a_party IN (SELECT party_id FROM cc_clash_party_members WHERE player_id = ?) THEN b_party ELSE a_party END AS oid
             FROM cc_clash_party_matches WHERE a_party IN (SELECT party_id FROM cc_clash_party_members WHERE player_id = ?) OR b_party IN (SELECT party_id FROM cc_clash_party_members WHERE player_id = ?)
             ORDER BY created_at DESC LIMIT 20',
            [$p['id'], $p['id'], $p['id']]
        );
        foreach ($opp as $r) {
            foreach (Db::all('SELECT player_id FROM cc_clash_party_members WHERE party_id = ?', [$r['oid']]) as $mem) {
                if ($mem['player_id'] !== $p['id']) {
                    $ids[(string) $mem['player_id']] = true;
                }
            }
        }
        $out = [];
        foreach (array_keys($ids) as $id) {
            $out[] = self::mini($id);
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $p @return array<string,mixed>|null */
    private static function lastScore(array $p): ?array
    {
        $m = Db::one(
            "SELECT * FROM cc_clash_matches WHERE (a_id = ? OR b_id = ?) AND status IN ('finished','forfeit') ORDER BY updated_at DESC",
            [$p['id'], $p['id']]
        );
        if ($m) {
            $youA = $m['a_id'] === $p['id'];
            $tot = Db::one('SELECT COALESCE(SUM(a_score),0) AS a, COALESCE(SUM(b_score),0) AS b FROM cc_clash_rounds WHERE match_id = ?', [$m['id']]);
            $you = $youA ? (int) $tot['a'] : (int) $tot['b'];
            $opp = $youA ? (int) $tot['b'] : (int) $tot['a'];
            $oppP = self::mini($youA ? (string) $m['b_id'] : (string) $m['a_id']);
            return [
                'kind' => 'duel',
                'you' => self::mini($p['id']) + ['score' => $you, 'wins' => $youA ? (int) $m['a_wins'] : (int) $m['b_wins']],
                'opp' => $oppP + ['score' => $opp, 'wins' => $youA ? (int) $m['b_wins'] : (int) $m['a_wins']],
                'youWon' => $m['winner_id'] === $p['id'],
            ];
        }
        $pm = Db::one(
            'SELECT * FROM cc_clash_party_matches WHERE (a_party IN (SELECT party_id FROM cc_clash_party_members WHERE player_id = ?) OR b_party IN (SELECT party_id FROM cc_clash_party_members WHERE player_id = ?)) AND status = ? ORDER BY updated_at DESC',
            [$p['id'], $p['id'], 'finished']
        );
        if ($pm) {
            $mine = Db::one('SELECT party_id FROM cc_clash_party_members WHERE player_id = ? AND party_id IN (?,?)', [$p['id'], $pm['a_party'], $pm['b_party']]);
            $side = ($mine && $mine['party_id'] === $pm['a_party']) ? 'a' : 'b';
            $other = $side === 'a' ? 'b' : 'a';
            return [
                'kind' => 'party',
                'you' => ['name' => $pm[$side . '_name'], 'avatar' => '⚡', 'score' => (int) $pm[$side . '_session'], 'wins' => (int) $pm[$side . '_session']],
                'opp' => ['name' => $pm[$other . '_name'], 'avatar' => '⚡', 'score' => (int) $pm[$other . '_session'], 'wins' => (int) $pm[$other . '_session']],
                'youWon' => $pm['winner_party'] === ($side === 'a' ? $pm['a_party'] : $pm['b_party']),
            ];
        }
        return null;
    }

    /** @return array{id:string,name:string,avatar:string} */
    private static function mini(string $id): array
    {
        $p = Db::one('SELECT id, nickname, avatar FROM cc_players WHERE id = ?', [$id]);
        return [
            'id' => $id,
            'name' => $p['nickname'] ?? 'Player',
            'avatar' => Text::safeEmoji((string) (($p['avatar'] ?? '') ?: '🐱')),
        ];
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $p @return array<string,mixed> */
    private static function inviteView(array $c, array $p): array
    {
        $party = Db::one('SELECT name, host_id FROM cc_clash_parties WHERE id = ?', [$c['party_id']]);
        return [
            'id' => $c['id'],
            'kind' => 'party',
            'status' => $c['status'],
            'from' => self::mini((string) $c['from_id']),
            'to' => self::mini((string) $c['to_id']),
            'partyId' => $c['party_id'],
            'partyName' => $party['name'] ?? '',
            'expiresAt' => strtotime((string) $c['expires_at'] . ' UTC') * 1000,
            'incoming' => $c['to_id'] === $p['id'],
        ];
    }

    /** @param array<string,mixed> $party @param array<string,mixed> $p @return array<string,mixed> */
    private static function partyView(array $party, array $p): array
    {
        $mems = [];
        foreach (Db::all('SELECT * FROM cc_clash_party_members WHERE party_id = ? ORDER BY joined_at', [$party['id']]) as $m) {
            $mini = self::mini((string) $m['player_id']);
            $mems[] = $mini + [
                'role' => $m['role'],
                'host' => $m['player_id'] === $party['host_id'],
                'you' => $m['player_id'] === $p['id'],
            ];
        }
        $q = Db::one('SELECT * FROM cc_clash_party_queue WHERE party_id = ?', [$party['id']]);
        return [
            'id' => $party['id'],
            'name' => $party['name'],
            'status' => $party['status'],
            'hostId' => $party['host_id'],
            'youHost' => $party['host_id'] === $p['id'],
            'needsName' => trim((string) $party['name']) === '' && count($mems) >= 2 && $party['host_id'] === $p['id'],
            'members' => $mems,
            'queued' => (bool) $q,
            'voiceRoom' => 'party-' . $party['id'],
        ];
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $p @return array<string,mixed> */
    private static function matchView(array $m, array $p): array
    {
        $partyId = self::partyIdOf($m, $p['id']);
        $side = $m['a_party'] === $partyId ? 'a' : 'b';
        $other = $side === 'a' ? 'b' : 'a';
        $youAlive = (int) $m[$side . '_alive'] === 1;
        $oppAlive = (int) $m[$other . '_alive'] === 1;
        $youSess = (int) $m[$side . '_session'];
        $oppSess = (int) $m[$other . '_session'];
        $cur = $youAlive ? $youSess + 1 : $youSess;
        $endsAt = $m[$side . '_ends_at'] ? strtotime((string) $m[$side . '_ends_at'] . ' UTC') * 1000 : null;
        $members = function (string $pid) use ($p): array {
            $out = [];
            foreach (Db::all('SELECT player_id FROM cc_clash_party_members WHERE party_id = ? ORDER BY joined_at', [$pid]) as $r) {
                $out[] = self::mini((string) $r['player_id']) + ['you' => $r['player_id'] === $p['id']];
            }
            return $out;
        };
        $youPlays = [];
        $matePlays = [];
        $oppPlays = [];
        $sessForPlays = max(1, $cur);
        foreach (Db::all('SELECT * FROM cc_clash_party_plays WHERE match_id = ? AND session = ? ORDER BY id', [$m['id'], $sessForPlays]) as $pl) {
            $item = [
                'id' => (int) $pl['id'],
                'token' => 't:' . (int) $pl['id'],
                'name' => $pl['result_name'],
                'emoji' => Text::safeEmoji((string) $pl['result_emoji']),
                'tagline' => $pl['result_tagline'],
                'rarity' => $pl['rarity'],
                'a' => $pl['a_name'],
                'b' => $pl['b_name'],
                'hit' => (int) $pl['is_hit'] === 1,
                'player' => self::mini((string) $pl['player_id']),
            ];
            if ($pl['party_id'] === $partyId) {
                if ($pl['player_id'] === $p['id']) {
                    $youPlays[] = $item;
                } else {
                    $matePlays[] = $item;
                }
            } else {
                $oppPlays[] = $item;
            }
        }
        $hand = [];
        if ($youAlive && $m['status'] === 'playing') {
            foreach (self::hand($p, $m, $side) as $tok => $e) {
                $hand[] = [
                    'token' => $tok,
                    'name' => $e['name'],
                    'emoji' => $e['emoji'] ?? '✨',
                    'rarity' => $e['rarity'] ?? 'Common',
                    'temp' => !empty($e['temp']),
                ];
            }
        }
        $headline = '@' . ($m[$side . '_name'] ?: 'Party') . ' VS @' . ($m[$other . '_name'] ?: 'Party');
        return [
            'id' => $m['id'],
            'kind' => 'party',
            'status' => $m['status'],
            'headline' => $headline,
            'youName' => $m[$side . '_name'],
            'oppName' => $m[$other . '_name'],
            'target' => ['name' => $m['target_name'], 'emoji' => Text::safeEmoji((string) $m['target_emoji'])],
            'session' => (int) $m['session'],
            'youSession' => $youSess,
            'oppSession' => $oppSess,
            'youAlive' => $youAlive,
            'oppAlive' => $oppAlive,
            'spectating' => !$youAlive && $m['status'] !== 'finished',
            'endsAt' => $endsAt,
            'introUntil' => $m['intro_until'] ? strtotime((string) $m['intro_until'] . ' UTC') * 1000 : null,
            'sessionSecs' => self::SESSION_SECS,
            'youMembers' => $members($m[$side . '_party']),
            'oppMembers' => $members($m[$other . '_party']),
            'youPlays' => $youPlays,
            'matePlays' => $matePlays,
            'oppPlays' => $oppPlays,
            'hand' => $hand,
            'winnerParty' => $m['winner_party'],
            'youWon' => $m['winner_party'] === $partyId,
            'voiceRoom' => 'party-' . $partyId,
            'partyId' => $partyId,
            'now' => Db::nowMs(),
        ];
    }

    private static function id(string $p): string
    {
        return $p . bin2hex(random_bytes(8));
    }
}
