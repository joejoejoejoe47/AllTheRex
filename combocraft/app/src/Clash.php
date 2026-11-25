<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/**
 * Combo Clash: real-time PvP. Server-authoritative timers and scores.
 * Additive cc_clash_* tables only.
 */
final class Clash
{
    public const ROUND_SECS = 75;
    public const KICKOFF_SECS = 3;
    public const OT_SECS = 45;
    public const BOOST_MAX = 100;
    public const BOOST_ATTACK = 30;
    public const BOOST_BURST = 50;
    public const BOOST_COMBO = 22;
    public const BOOST_CHAIN = 35;
    public const ACCEPT_SECS = 5;
    public const INVITE_ACCEPT_SECS = 8;
    public const OPEN_INVITE_SECS = 600;
    public const PLAY_GAP_MS = 400;
    public const DISCONNECT_PLAY_SECS = 25;
    public const DISCONNECT_LOADOUT_SECS = 45;
    public const INTER_SECS = 5;
    public const MIN_LOADOUT = 8;
    public const MAX_LOADOUT = 12;
    public const QUEUE_STALE_SECS = 20;

    /** Reserved bot opponents for Challenge AI. Ids stay stable so rematches work. */
    public const AI = [
        'easy' => ['id' => 'pai_easy', 'name' => 'Easy Bot', 'avatar' => '🤖', 'nick' => 'Easy Bot', 'cards' => 8, 'firstMs' => 6000, 'gapMs' => 9000, 'attack' => 0.12, 'chain' => 0.15],
        'medium' => ['id' => 'pai_med', 'name' => 'Medium Bot', 'avatar' => '🤖', 'nick' => 'Medium Bot', 'cards' => 10, 'firstMs' => 2800, 'gapMs' => 5000, 'attack' => 0.35, 'chain' => 0.45],
        'hard' => ['id' => 'pai_hard', 'name' => 'Hard Bot', 'avatar' => '🤖', 'nick' => 'Hard Bot', 'cards' => 12, 'firstMs' => 1100, 'gapMs' => 2200, 'attack' => 0.6, 'chain' => 0.7],
    ];

    private const MULT = ['Common' => 1.0, 'Rare' => 1.35, 'Epic' => 1.7, 'Legendary' => 2.2];

    private const THEMES = [
        'Create the most powerful warrior',
        'Make the funniest disaster',
        'Build the ultimate meme',
        'Create something that would destroy the opponent\'s creation',
        'Invent the coziest comfort',
        'Brew the weirdest snack',
        'Summon the cutest calamity',
        'Design the richest flex',
        'Spark the biggest plot twist',
        'Craft the ultimate underdog',
        'Make the loudest party',
        'Build a legendary hero no one asked for',
    ];

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function inbox(array $p): array
    {
        self::touch($p, null);
        self::expireChallenges();
        self::sweep($p);
        $incoming = Db::all(
            'SELECT * FROM cc_clash_challenges WHERE to_id = ? AND status = ? ORDER BY created_at DESC',
            [$p['id'], 'pending']
        );
        $outgoing = Db::all(
            'SELECT * FROM cc_clash_challenges WHERE from_id = ? AND status IN (?,?) ORDER BY created_at DESC',
            [$p['id'], 'pending', 'waiting']
        );
        $match = self::activeMatch($p);
        $out = [
            'needName' => self::needName($p),
            'nickname' => $p['nickname'] ?: '',
            'incoming' => array_map(fn ($c) => self::challengeView($c, $p), $incoming),
            'outgoing' => array_map(fn ($c) => self::challengeView($c, $p), $outgoing),
            'match' => $match ? self::matchView($match, $p) : null,
            'rank' => ClashRank::of($p['id']),
            'now' => Db::nowMs(),
        ];
        try {
            $out += Party::decorateInbox($p);
        } catch (\Throwable $e) {
            error_log('[combocraft party inbox] ' . $e->getMessage());
        }
        return $out;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function queue(array $p, string $pair = ''): array
    {
        self::requireName($p);
        self::expireChallenges();
        self::sweep($p);
        $pair = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $pair) ?? '');
        if (strlen($pair) > 32) {
            $pair = substr($pair, 0, 32);
        }
        if ($live = self::activeMatch($p)) {
            return ['queued' => false, 'match' => self::matchView($live, $p)];
        }
        $now = Db::now();
        $stale = gmdate('Y-m-d H:i:s', time() - self::QUEUE_STALE_SECS);
        Db::run('DELETE FROM cc_clash_queue WHERE last_seen < ? OR player_id = ?', [$stale, $p['id']]);
        $other = Db::one(
            'SELECT * FROM cc_clash_queue WHERE player_id <> ? AND last_seen >= ? AND pair = ? ORDER BY queued_at ASC',
            [$p['id'], $stale, $pair]
        );
        if ($other) {
            Db::run('DELETE FROM cc_clash_queue WHERE player_id IN (?,?)', [$p['id'], $other['player_id']]);
            $m = self::createMatch($p['id'], (string) $other['player_id']);
            return ['queued' => false, 'match' => self::matchView($m, $p)];
        }
        Db::upsert(
            'cc_clash_queue',
            ['player_id' => $p['id'], 'queued_at' => $now, 'last_seen' => $now, 'pair' => $pair],
            ['player_id'],
            ['last_seen', 'pair']
        );
        return ['queued' => true, 'match' => null, 'now' => Db::nowMs()];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function cancelQueue(array $p): array
    {
        Db::run('DELETE FROM cc_clash_queue WHERE player_id = ?', [$p['id']]);
        return ['queued' => false];
    }

    /** Start a best-of-3 against a timed bot. @param array<string,mixed> $p @return array<string,mixed> */
    public static function challengeAi(array $p, string $level): array
    {
        self::requireName($p);
        $level = self::normLevel($level);
        if ($level === null) {
            throw new HttpError('Pick Easy, Medium, or Hard.', 422);
        }
        if ($live = self::activeMatch($p)) {
            return ['match' => self::matchView($live, $p), 'ai' => self::aiLevelOf($live)];
        }
        self::ensureAiPlayer($level);
        $m = self::createMatch($p['id'], self::AI[$level]['id']);
        self::prepareAiLoadout($m, $level);
        return ['match' => self::matchView(self::getMatch($m['id']), $p), 'ai' => $level];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function challenge(array $p, string $nickname): array
    {
        self::requireName($p);
        $key = mb_strtolower(trim($nickname));
        if ($key === '') {
            throw new HttpError('Type a nickname to challenge.', 422);
        }
        $to = Db::one('SELECT * FROM cc_players WHERE nick_key = ?', [$key]);
        if (!$to) {
            throw new HttpError('No player with that nickname is on ComboCraft yet.', 404);
        }
        if ($to['id'] === $p['id']) {
            throw new HttpError('You cannot challenge yourself.', 422);
        }
        $aiLevel = self::levelFromId((string) $to['id']);
        if ($aiLevel) {
            return self::challengeAi($p, $aiLevel);
        }
        $recent = Db::value(
            'SELECT id FROM cc_clash_challenges WHERE from_id = ? AND to_id = ? AND created_at > ?',
            [$p['id'], $to['id'], gmdate('Y-m-d H:i:s', time() - 2)]
        );
        if ($recent) {
            throw new HttpError('Easy — wait a second before challenging again.', 429);
        }
        $pending = (int) Db::value(
            'SELECT COUNT(*) FROM cc_clash_challenges WHERE from_id = ? AND status = ?',
            [$p['id'], 'pending']
        );
        if ($pending >= 8) {
            throw new HttpError('You already have too many open challenges.', 429);
        }
        if ($live = self::activeMatch($p)) {
            return ['ok' => false, 'error' => 'You are already in a match.', 'match' => self::matchView($live, $p)];
        }
        $id = self::id('c');
        $now = time();
        Db::run(
            'INSERT INTO cc_clash_challenges (id, from_id, to_id, created_at, expires_at, status, match_id) VALUES (?,?,?,?,?,?,?)',
            [$id, $p['id'], $to['id'], Db::now(), gmdate('Y-m-d H:i:s', $now + self::ACCEPT_SECS), 'pending', null]
        );
        $c = Db::one('SELECT * FROM cc_clash_challenges WHERE id = ?', [$id]);
        return ['challenge' => self::challengeView($c, $p)];
    }

    /** Create an open invite link the other player can accept. @param array<string,mixed> $p */
    public static function makeInvite(array $p): array
    {
        self::requireName($p);
        $id = self::id('c');
        Db::run(
            'INSERT INTO cc_clash_challenges (id, from_id, to_id, created_at, expires_at, status, match_id) VALUES (?,?,?,?,?,?,?)',
            [$id, $p['id'], null, Db::now(), gmdate('Y-m-d H:i:s', time() + self::OPEN_INVITE_SECS), 'waiting', null]
        );
        return ['challenge' => self::challengeView(Db::one('SELECT * FROM cc_clash_challenges WHERE id = ?', [$id]), $p), 'code' => $id];
    }

    /** Bind an open invite to the visitor and start their accept window. @param array<string,mixed> $p */
    public static function openInvite(array $p, string $id): array
    {
        self::requireName($p);
        self::expireChallenges();
        $c = Db::one('SELECT * FROM cc_clash_challenges WHERE id = ?', [$id]);
        if (!$c) {
            throw new HttpError('That invite expired or is not real.', 404);
        }
        if ($c['from_id'] === $p['id']) {
            return ['challenge' => self::challengeView($c, $p), 'self' => true];
        }
        if (in_array($c['status'], ['accepted', 'declined', 'expired'], true)) {
            throw new HttpError('That invite is no longer open.', 410);
        }
        if ($c['to_id'] && $c['to_id'] !== $p['id']) {
            throw new HttpError('Someone else already claimed this invite.', 409);
        }
        if (!$c['to_id'] || $c['status'] === 'waiting') {
            $exp = gmdate('Y-m-d H:i:s', time() + self::INVITE_ACCEPT_SECS);
            Db::run('UPDATE cc_clash_challenges SET to_id = ?, status = ?, expires_at = ? WHERE id = ? AND status IN (?,?)', [$p['id'], 'pending', $exp, $id, 'waiting', 'pending']);
            $c = Db::one('SELECT * FROM cc_clash_challenges WHERE id = ?', [$id]);
        }
        return ['challenge' => self::challengeView($c, $p)];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function respond(array $p, string $id, bool $accept): array
    {
        self::requireName($p);
        self::expireChallenges();
        $c = Db::one('SELECT * FROM cc_clash_challenges WHERE id = ?', [$id]);
        if (!$c || $c['to_id'] !== $p['id']) {
            throw new HttpError('That challenge is not for you.', 404);
        }
        if ($c['status'] !== 'pending') {
            throw new HttpError('That challenge already expired.', 410);
        }
        if (strtotime((string) $c['expires_at'] . ' UTC') < time()) {
            Db::run('UPDATE cc_clash_challenges SET status = ? WHERE id = ?', ['expired', $id]);
            throw new HttpError('Too late — the invite expired.', 410);
        }
        if (!$accept) {
            Db::run('UPDATE cc_clash_challenges SET status = ? WHERE id = ?', ['declined', $id]);
            return ['accepted' => false];
        }
        if ($live = self::activeMatch($p)) {
            throw new HttpError('You are already in a match.', 409);
        }
        $fromLive = Db::one(
            "SELECT * FROM cc_clash_matches WHERE status IN ('loadout','playing','inter') AND (a_id = ? OR b_id = ?)",
            [$c['from_id'], $c['from_id']]
        );
        if ($fromLive) {
            Db::run('UPDATE cc_clash_challenges SET status = ? WHERE id = ?', ['declined', $id]);
            throw new HttpError('They already started another match.', 409);
        }
        $m = self::createMatch((string) $c['from_id'], $p['id']);
        Db::run('UPDATE cc_clash_challenges SET status = ?, match_id = ? WHERE id = ?', ['accepted', $m['id'], $id]);
        Db::run('DELETE FROM cc_clash_queue WHERE player_id IN (?,?)', [$c['from_id'], $p['id']]);
        return ['accepted' => true, 'match' => self::matchView($m, $p)];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function match(array $p, string $id): array
    {
        self::touch($p, $id);
        self::sweep($p);
        $m = self::getMatch($id);
        if ($m['a_id'] !== $p['id'] && $m['b_id'] !== $p['id']) {
            // Finished matches are public enough to share results.
            if ($m['status'] !== 'finished' && $m['status'] !== 'forfeit') {
                throw new HttpError('This is not your match.', 403);
            }
        }
        $m = self::tick($m);
        return ['match' => self::matchView($m, $p), 'now' => Db::nowMs()];
    }

    /** @param array<string,mixed> $p @param list<int> $ids */
    public static function setLoadout(array $p, string $matchId, array $ids): array
    {
        $m = self::mine($p, $matchId);
        if ($m['status'] !== 'loadout') {
            throw new HttpError('The loadout window is closed.', 422);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $n = count($ids);
        if ($n < self::MIN_LOADOUT || $n > self::MAX_LOADOUT) {
            throw new HttpError('Pick 8 to 12 elements you have already discovered.', 422);
        }
        $own = self::ownedIds($p);
        foreach ($ids as $id) {
            if (!isset($own[$id])) {
                throw new HttpError('Every loadout card has to be one you have discovered.', 422);
            }
        }
        Db::upsert(
            'cc_clash_loadouts',
            ['match_id' => $matchId, 'player_id' => $p['id'], 'ids' => json_encode($ids), 'ready' => 0, 'last_play_ms' => 0],
            ['match_id', 'player_id'],
            ['ids', 'ready']
        );
        $m = self::tick(self::getMatch($matchId));
        return ['match' => self::matchView($m, $p)];
    }

    /** @param array<string,mixed> $p */
    public static function ready(array $p, string $matchId): array
    {
        $m = self::mine($p, $matchId);
        if ($m['status'] !== 'loadout') {
            throw new HttpError('You cannot ready up now.', 422);
        }
        $row = Db::one('SELECT * FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$matchId, $p['id']]);
        if (!$row) {
            throw new HttpError('Save a loadout of 8–12 elements first.', 422);
        }
        $ids = json_decode((string) $row['ids'], true);
        if (!is_array($ids) || count($ids) < self::MIN_LOADOUT) {
            throw new HttpError('Save a loadout of 8–12 elements first.', 422);
        }
        Db::run('UPDATE cc_clash_loadouts SET ready = 1 WHERE match_id = ? AND player_id = ?', [$matchId, $p['id']]);
        $m = self::tick(self::getMatch($matchId));
        return ['match' => self::matchView($m, $p)];
    }

    /** @param array<string,mixed> $p */
    public static function play(array $p, string $matchId, string $aTok, string $bTok, bool $attack, bool $internal = false, bool $burst = false): array
    {
        if ($internal) {
            $m = self::getMatch($matchId);
            if ($m['a_id'] !== $p['id'] && $m['b_id'] !== $p['id']) {
                throw new HttpError('This is not your match.', 403);
            }
            self::touch($p, $matchId);
        } else {
            $m = self::mine($p, $matchId);
            $m = self::tick($m);
        }
        if ($m['status'] !== 'playing') {
            throw new HttpError('This round is not in play.', 422);
        }
        $round = self::currentRound($m);
        if (!$round) {
            throw new HttpError('This round is not in play.', 422);
        }
        if (strtotime((string) $round['ends_at'] . ' UTC') <= time()) {
            $m = self::tick(self::getMatch($matchId));
            throw new HttpError('Time is up.', 422);
        }
        $kickoffUntil = strtotime((string) $round['started_at'] . ' UTC') + self::KICKOFF_SECS;
        if (time() < $kickoffUntil) {
            throw new HttpError('Kickoff — wait for GO!', 422);
        }
        $lo = Db::one('SELECT * FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$matchId, $p['id']]);
        $lastMs = (int) ($lo['last_play_ms'] ?? 0);
        $nowMs = Db::nowMs();
        if ($lastMs && ($nowMs - $lastMs) < self::PLAY_GAP_MS) {
            throw new HttpError('A little slower — combos need a beat.', 429);
        }
        $hand = self::hand($p, $m, $round);
        $ea = $hand[$aTok] ?? null;
        $eb = $hand[$bTok] ?? null;
        if (!$ea || !$eb) {
            throw new HttpError('You can only combine cards from your loadout or this round\'s results.', 422);
        }

        $made = self::craft($ea, $eb);
        $chain = !empty($ea['temp']) || !empty($eb['temp']);
        $double = !empty($ea['temp']) && !empty($eb['temp']);
        $boost = self::boostOf($matchId, $p['id'], (int) $round['n']);
        if ($attack && $boost < self::BOOST_ATTACK) {
            $attack = false;
        }
        if ($burst && $boost < self::BOOST_BURST) {
            $burst = false;
        }
        if ($attack) {
            $boost -= self::BOOST_ATTACK;
        }
        if ($burst) {
            $boost -= self::BOOST_BURST;
        }
        $oppLatest = self::latestPlay($round, self::oppId($m, $p['id']));
        $judge = self::judge((string) $round['theme'], $made, $ea, $eb, $chain, $double, $attack, $oppLatest, !$internal);
        if ($burst) {
            $judge['score'] = (int) $judge['score'] * 2;
            $judge['burst'] = true;
        }
        $gain = $chain ? self::BOOST_CHAIN : self::BOOST_COMBO;
        if ($double) {
            $gain += 15;
        }
        $boost = min(self::BOOST_MAX, $boost + $gain);
        self::setBoost($matchId, $p['id'], (int) $round['n'], $boost);
        $judge['boost'] = $boost;
        $judge['boostGain'] = $gain;
        $side = $m['a_id'] === $p['id'] ? 'a' : 'b';
        $newScore = (int) $round[$side . '_score'] + (int) $judge['score'];
        $oppSide = $side === 'a' ? 'b' : 'a';
        $oppScore = (int) $round[$oppSide . '_score'];
        if (!empty($judge['attackWorks']) && $oppLatest) {
            $cut = (int) $judge['cut'];
            $oppScore = max(0, $oppScore - $cut);
        }
        $playId = 0;
        Db::transaction(function () use ($p, $matchId, $round, $side, $oppSide, $newScore, $oppScore, $made, $ea, $eb, $judge, $attack, $nowMs, &$playId): void {
            Db::run(
                'INSERT INTO cc_clash_plays (round_id, player_id, a_name, b_name, a_emoji, b_emoji, result_name, result_emoji, result_tagline, rarity, score, breakdown, is_attack, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $round['id'], $p['id'], $ea['name'], $eb['name'], $ea['emoji'] ?? '', $eb['emoji'] ?? '',
                    $made['name'], $made['emoji'], $made['tagline'], $made['rarity'], $judge['score'],
                    json_encode($judge, JSON_UNESCAPED_UNICODE), $attack ? 1 : 0, Db::now(),
                ]
            );
            $playId = (int) Db::value('SELECT MAX(id) FROM cc_clash_plays WHERE round_id = ? AND player_id = ?', [$round['id'], $p['id']]);
            $latest = json_encode([
                'playId' => $playId,
                'name' => $made['name'],
                'emoji' => $made['emoji'],
                'tagline' => $made['tagline'],
                'rarity' => $made['rarity'],
                'score' => $judge['score'],
                'a' => $ea['name'],
                'b' => $eb['name'],
                'attack' => $attack,
                'attackWorks' => !empty($judge['attackWorks']),
            ], JSON_UNESCAPED_UNICODE);
            Db::run(
                "UPDATE cc_clash_rounds SET {$side}_score = ?, {$oppSide}_score = ?, {$side}_latest = ? WHERE id = ?",
                [$newScore, $oppScore, $latest, $round['id']]
            );
            Db::run('UPDATE cc_clash_loadouts SET last_play_ms = ? WHERE match_id = ? AND player_id = ?', [$nowMs, $matchId, $p['id']]);
            Db::run('UPDATE cc_clash_matches SET updated_at = ? WHERE id = ?', [Db::now(), $matchId]);
        });
        $m = self::getMatch($matchId);
        return [
            'play' => [
                'id' => $playId,
                'token' => 't:' . $playId,
                'name' => $made['name'],
                'emoji' => $made['emoji'],
                'tagline' => $made['tagline'],
                'rarity' => $made['rarity'],
                'score' => $judge['score'],
                'breakdown' => $judge,
                'inputs' => [$ea['name'], $eb['name']],
            ],
            'match' => self::matchView($m, $p),
            'now' => Db::nowMs(),
        ];
    }

    /** @param array<string,mixed> $p */
    public static function forfeit(array $p, string $matchId): array
    {
        $m = self::mine($p, $matchId);
        if (in_array($m['status'], ['finished', 'forfeit'], true)) {
            return ['match' => self::matchView($m, $p)];
        }
        self::endForfeit($m, $p['id'], 'gave up');
        return ['match' => self::matchView(self::getMatch($matchId), $p)];
    }

    /** @param array<string,mixed> $p */
    public static function revenge(array $p, string $matchId): array
    {
        self::requireName($p);
        $m = self::getMatch($matchId);
        if ($m['a_id'] !== $p['id'] && $m['b_id'] !== $p['id']) {
            throw new HttpError('This is not your match.', 403);
        }
        if (!in_array($m['status'], ['finished', 'forfeit'], true)) {
            throw new HttpError('Finish this match first.', 422);
        }
        $opp = self::oppId($m, $p['id']);
        if ($live = self::activeMatch($p)) {
            return ['match' => self::matchView($live, $p)];
        }
        $aiLevel = self::levelFromId($opp);
        if ($aiLevel) {
            return self::challengeAi($p, $aiLevel) + ['challenged' => false];
        }
        $oppLive = Db::one(
            "SELECT * FROM cc_clash_matches WHERE status IN ('loadout','playing','inter') AND (a_id = ? OR b_id = ?)",
            [$opp, $opp]
        );
        // If the opponent is still around (polled recently), start immediately; else send a 5s challenge.
        $seen = Db::one('SELECT * FROM cc_clash_seen WHERE player_id = ?', [$opp]);
        $fresh = $seen && (time() - strtotime((string) $seen['last_poll'] . ' UTC') <= 20);
        if ($fresh && !$oppLive) {
            $nm = self::createMatch($p['id'], $opp);
            return ['match' => self::matchView($nm, $p), 'challenged' => false];
        }
        $id = self::id('c');
        Db::run(
            'INSERT INTO cc_clash_challenges (id, from_id, to_id, created_at, expires_at, status, match_id) VALUES (?,?,?,?,?,?,?)',
            [$id, $p['id'], $opp, Db::now(), gmdate('Y-m-d H:i:s', time() + self::ACCEPT_SECS), 'pending', null]
        );
        return ['match' => null, 'challenged' => true, 'challenge' => self::challengeView(Db::one('SELECT * FROM cc_clash_challenges WHERE id = ?', [$id]), $p)];
    }

    /** @return array<string,mixed> */
    public static function board(string $kind): array
    {
        if ($kind === 'daily') {
            $day = Player::today();
            $rows = Db::all(
                'SELECT d.player_id, d.wins, d.points, p.nickname, p.avatar FROM cc_clash_daily d JOIN cc_players p ON p.id = d.player_id WHERE d.day = ? ORDER BY d.points DESC, d.wins DESC LIMIT 80',
                [$day]
            );
            $out = [];
            foreach ($rows as $r) {
                if (self::isHiddenFromBoards((string) $r['player_id'], (string) ($r['nickname'] ?? ''))) {
                    continue;
                }
                $wins = (int) $r['wins'];
                $points = (int) $r['points'];
                if ($wins === 0 && $points === 0) {
                    continue;
                }
                $out[] = [
                    'rank' => count($out) + 1,
                    'name' => $r['nickname'] ?: 'Player',
                    'avatar' => Text::safeEmoji((string) ($r['avatar'] ?: '🐱')),
                    'wins' => $wins,
                    'points' => $points,
                ];
                if (count($out) >= 25) {
                    break;
                }
            }
            return ['kind' => 'daily', 'day' => $day, 'rows' => $out];
        }
        if ($kind === 'rare') {
            $rows = Db::all(
                "SELECT p.result_name, p.result_emoji, p.rarity, p.a_name, p.b_name, m.id AS match_id, m.winner_id, w.nickname AS winner, l.nickname AS loser, l.id AS loser_id
                 FROM cc_clash_plays p
                 JOIN cc_clash_rounds r ON r.id = p.round_id
                 JOIN cc_clash_matches m ON m.id = r.match_id
                 JOIN cc_players w ON w.id = m.winner_id
                 JOIN cc_players l ON l.id = CASE WHEN m.winner_id = m.a_id THEN m.b_id ELSE m.a_id END
                 WHERE m.status IN ('finished','forfeit') AND p.player_id = m.winner_id
                 ORDER BY CASE p.rarity WHEN 'Legendary' THEN 4 WHEN 'Epic' THEN 3 WHEN 'Rare' THEN 2 ELSE 1 END DESC, p.score DESC, p.id DESC
                 LIMIT 80"
            );
            $out = [];
            $seen = [];
            foreach ($rows as $r) {
                if (self::isHiddenFromBoards((string) ($r['winner_id'] ?? ''), (string) ($r['winner'] ?? ''))) {
                    continue;
                }
                $loserName = (string) ($r['loser'] ?? 'Player');
                if (self::isHiddenFromBoards((string) ($r['loser_id'] ?? ''), $loserName)) {
                    $loserName = Clash::isAiId((string) ($r['loser_id'] ?? '')) ? 'Bot' : 'Player';
                }
                $k = mb_strtolower((string) $r['result_name']);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[] = [
                    'name' => $r['result_name'],
                    'emoji' => Text::safeEmoji((string) $r['result_emoji']),
                    'rarity' => $r['rarity'],
                    'recipe' => $r['a_name'] . ' + ' . $r['b_name'],
                    'winner' => $r['winner'] ?: 'Player',
                    'loser' => $loserName,
                    'matchId' => $r['match_id'],
                ];
                if (count($out) >= 15) {
                    break;
                }
            }
            return ['kind' => 'rare', 'rows' => $out];
        }
        $rows = Db::all(
            "SELECT winner_id AS player_id, COUNT(*) AS wins FROM cc_clash_matches WHERE status IN ('finished','forfeit') AND winner_id IS NOT NULL GROUP BY winner_id ORDER BY wins DESC LIMIT 80"
        );
        $out = [];
        $i = 0;
        foreach ($rows as $r) {
            $pl = Db::one('SELECT nickname, avatar FROM cc_players WHERE id = ?', [$r['player_id']]);
            $name = $pl['nickname'] ?? 'Player';
            if (self::isHiddenFromBoards((string) $r['player_id'], (string) $name)) {
                continue;
            }
            $out[] = [
                'rank' => ++$i,
                'name' => $name,
                'avatar' => Text::safeEmoji((string) (($pl['avatar'] ?? '') ?: '🐱')),
                'wins' => (int) $r['wins'],
            ];
            if (count($out) >= 25) {
                break;
            }
        }
        return ['kind' => 'wins', 'rows' => $out];
    }

    /** Public share payload for OG / remix. */
    public static function share(string $matchId): ?array
    {
        $m = Db::one('SELECT * FROM cc_clash_matches WHERE id = ?', [$matchId]);
        if (!$m || !in_array($m['status'], ['finished', 'forfeit'], true) || !$m['winner_id']) {
            return null;
        }
        $winner = self::playerMini((string) $m['winner_id']);
        $loser = self::playerMini(self::oppId($m, (string) $m['winner_id']));
        $best = self::bestPlay((string) $m['id'], (string) $m['winner_id']);
        return [
            'matchId' => $m['id'],
            'winner' => $winner,
            'loser' => $loser,
            'best' => $best,
            'headline' => self::headline($winner['name'], $loser['name'], $best),
        ];
    }

    /** @param array<string,mixed> $m */
    private static function tick(array $m): array
    {
        if (self::aiLevelOf($m)) {
            self::ensureAiReady($m);
            $m = self::getMatch($m['id']);
        }
        if ($m['status'] === 'loadout') {
            $ready = Db::all('SELECT player_id, ready FROM cc_clash_loadouts WHERE match_id = ?', [$m['id']]);
            $ok = 0;
            foreach ($ready as $r) {
                if ((int) $r['ready'] === 1) {
                    $ok++;
                }
            }
            if ($ok >= 2) {
                self::startRound($m, 1);
                return self::getMatch($m['id']);
            }
            return $m;
        }
        if ($m['status'] === 'playing') {
            $round = self::currentRound($m);
            if ($round && strtotime((string) $round['ends_at'] . ' UTC') <= time() && !(int) $round['settled']) {
                self::settleRound($m, $round);
                return self::getMatch($m['id']);
            }
            if (self::aiLevelOf($m)) {
                self::maybeAiPlay($m);
                return self::getMatch($m['id']);
            }
            return $m;
        }
        if ($m['status'] === 'inter') {
            $until = $m['inter_until'] ?? null;
            if ($until && strtotime((string) $until . ' UTC') <= time()) {
                $next = (int) $m['round'] + 1;
                $a = (int) $m['a_wins'];
                $b = (int) $m['b_wins'];
                if ($a >= 2 || $b >= 2 || ($next > 3 && $a !== $b)) {
                    self::finishMatch($m, $a >= $b ? (string) $m['a_id'] : (string) $m['b_id']);
                } else {
                    self::startRound($m, $next);
                }
                return self::getMatch($m['id']);
            }
        }
        return $m;
    }

    /** @param array<string,mixed> $m */
    private static function startRound(array $m, int $n): void
    {
        $id = self::id('r');
        $theme = self::THEMES[abs(crc32($m['id'] . '|' . $n)) % count(self::THEMES)];
        $start = time();
        $len = ($n >= 4 ? self::OT_SECS : self::ROUND_SECS) + self::KICKOFF_SECS;
        Db::run(
            'INSERT INTO cc_clash_rounds (id, match_id, n, theme, started_at, ends_at, a_score, b_score, a_latest, b_latest, winner_id, settled) VALUES (?,?,?,?,?,?,0,0,NULL,NULL,NULL,0)',
            [$id, $m['id'], $n, $theme, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $start + $len)]
        );
        Db::run('UPDATE cc_clash_matches SET status = ?, round = ?, inter_until = NULL, updated_at = ? WHERE id = ?', ['playing', $n, Db::now(), $m['id']]);
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $round */
    private static function settleRound(array $m, array $round): void
    {
        if ((int) $round['settled']) {
            return;
        }
        $as = (int) $round['a_score'];
        $bs = (int) $round['b_score'];
        $winner = null;
        $aWins = (int) $m['a_wins'];
        $bWins = (int) $m['b_wins'];
        if ($as > $bs) {
            $winner = (string) $m['a_id'];
            $aWins++;
        } elseif ($bs > $as) {
            $winner = (string) $m['b_id'];
            $bWins++;
        }
        // Tie in regulation: no win awarded. Overtime rounds must produce a winner or go again.
        Db::run('UPDATE cc_clash_rounds SET settled = 1, winner_id = ? WHERE id = ?', [$winner, $round['id']]);
        $n = (int) $m['round'];
        if ($n >= 4 && $winner) {
            Db::run('UPDATE cc_clash_matches SET a_wins = ?, b_wins = ?, updated_at = ? WHERE id = ?', [$aWins, $bWins, Db::now(), $m['id']]);
            self::finishMatch(array_merge($m, ['a_wins' => $aWins, 'b_wins' => $bWins]), $winner);
            return;
        }
        $done = $aWins >= 2 || $bWins >= 2 || ($n >= 3 && $aWins !== $bWins);
        if ($done) {
            $mw = $aWins > $bWins ? (string) $m['a_id'] : (string) $m['b_id'];
            Db::run('UPDATE cc_clash_matches SET a_wins = ?, b_wins = ?, updated_at = ? WHERE id = ?', [$aWins, $bWins, Db::now(), $m['id']]);
            self::finishMatch(array_merge($m, ['a_wins' => $aWins, 'b_wins' => $bWins]), $mw);
            return;
        }
        $until = gmdate('Y-m-d H:i:s', time() + self::INTER_SECS);
        Db::run('UPDATE cc_clash_matches SET status = ?, a_wins = ?, b_wins = ?, inter_until = ?, updated_at = ? WHERE id = ?', ['inter', $aWins, $bWins, $until, Db::now(), $m['id']]);
    }

    /** @param array<string,mixed> $m */
    private static function finishMatch(array $m, string $winnerId): void
    {
        Db::run(
            'UPDATE cc_clash_matches SET status = ?, winner_id = ?, updated_at = ? WHERE id = ? AND status <> ?',
            ['finished', $winnerId, Db::now(), $m['id'], 'finished']
        );
        self::awardDaily($m, $winnerId);
        Party::recordDuel(array_merge($m, ['winner_id' => $winnerId]));
        Db::run('DELETE FROM cc_clash_queue WHERE player_id IN (?,?)', [$m['a_id'], $m['b_id']]);
        try {
            ClashRank::applyMatch($m, $winnerId);
        } catch (Throwable $e) {
            error_log('[combocraft rank] ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $m */
    private static function endForfeit(array $m, string $loserId, string $why): void
    {
        $winner = self::oppId($m, $loserId);
        Db::run(
            'UPDATE cc_clash_matches SET status = ?, winner_id = ?, forfeit_id = ?, updated_at = ? WHERE id = ? AND status NOT IN (?,?)',
            ['forfeit', $winner, $loserId, Db::now(), $m['id'], 'finished', 'forfeit']
        );
        // Close an open round so scores freeze.
        Db::run('UPDATE cc_clash_rounds SET settled = 1 WHERE match_id = ? AND settled = 0', [$m['id']]);
        self::awardDaily(array_merge($m, ['status' => 'forfeit']), $winner);
        Party::recordDuel(array_merge($m, ['status' => 'forfeit', 'winner_id' => $winner]));
        Db::run('DELETE FROM cc_clash_queue WHERE player_id IN (?,?)', [$m['a_id'], $m['b_id']]);
        try {
            ClashRank::applyMatch(array_merge($m, ['status' => 'forfeit', 'winner_id' => $winner]), $winner);
        } catch (Throwable $e) {
            error_log('[combocraft rank forfeit] ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $m */
    private static function awardDaily(array $m, string $winnerId): void
    {
        $day = Player::today();
        $loser = self::oppId($m, $winnerId);
        $aRoundWins = (int) $m['a_wins'];
        $bRoundWins = (int) $m['b_wins'];
        $wRounds = $m['a_id'] === $winnerId ? $aRoundWins : $bRoundWins;
        $lRounds = $m['a_id'] === $winnerId ? $bRoundWins : $aRoundWins;
        $winnerName = (string) (Db::value('SELECT nickname FROM cc_players WHERE id = ?', [$winnerId]) ?: '');
        if (!self::isHiddenFromBoards($winnerId, $winnerName)) {
            self::bumpDaily($winnerId, $day, 1, 3 + $wRounds);
        }
        if ($loser) {
            $loserName = (string) (Db::value('SELECT nickname FROM cc_players WHERE id = ?', [$loser]) ?: '');
            if (!self::isHiddenFromBoards($loser, $loserName)) {
                self::bumpDaily($loser, $day, 0, $lRounds);
            }
        }
    }

    private static function bumpDaily(string $pid, string $day, int $wins, int $points): void
    {
        $row = Db::one('SELECT * FROM cc_clash_daily WHERE day = ? AND player_id = ?', [$day, $pid]);
        if ($row) {
            Db::run('UPDATE cc_clash_daily SET wins = wins + ?, points = points + ? WHERE day = ? AND player_id = ?', [$wins, $points, $day, $pid]);
        } else {
            Db::run('INSERT INTO cc_clash_daily (day, player_id, wins, points) VALUES (?,?,?,?)', [$day, $pid, $wins, $points]);
        }
    }

    /** @param array<string,mixed> $p @param array<string,mixed> $m @param array<string,mixed> $round @return array<string,array<string,mixed>> */
    private static function hand(array $p, array $m, array $round): array
    {
        $out = [];
        $lo = Db::one('SELECT ids FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$m['id'], $p['id']]);
        $ids = $lo ? (json_decode((string) $lo['ids'], true) ?: []) : [];
        foreach ($ids as $id) {
            $e = Game::element((int) $id);
            if (!$e) {
                continue;
            }
            $v = Game::view($e);
            $tok = 'e:' . (int) $id;
            $out[$tok] = $v + ['token' => $tok, 'temp' => false];
        }
        $plays = Db::all('SELECT * FROM cc_clash_plays WHERE round_id = ? AND player_id = ? ORDER BY id', [$round['id'], $p['id']]);
        foreach ($plays as $pl) {
            $tok = 't:' . (int) $pl['id'];
            $out[$tok] = [
                'token' => $tok,
                'temp' => true,
                'id' => 't:' . (int) $pl['id'],
                'name' => $pl['result_name'],
                'emoji' => Text::safeEmoji((string) $pl['result_emoji']),
                'tagline' => $pl['result_tagline'],
                'rarity' => $pl['rarity'],
                'depth' => self::depthFromRarity((string) $pl['rarity']) + 1,
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $ea @param array<string,mixed> $eb @return array{name:string,emoji:string,tagline:string,rarity:string,depth:int} */
    private static function craft(array $ea, array $eb): array
    {
        $depth = max((int) ($ea['depth'] ?? 1), (int) ($eb['depth'] ?? 1)) + 1;
        if (empty($ea['temp']) && empty($eb['temp']) && !empty($ea['id']) && !empty($eb['id'])) {
            $x = min((int) $ea['id'], (int) $eb['id']);
            $y = max((int) $ea['id'], (int) $eb['id']);
            $recipe = Db::one('SELECT e.* FROM cc_recipes r JOIN cc_elements e ON e.id = r.result_id WHERE r.a_id = ? AND r.b_id = ?', [$x, $y]);
            if ($recipe) {
                $v = Game::view($recipe);
                return [
                    'name' => $v['name'],
                    'emoji' => $v['emoji'],
                    'tagline' => $v['tagline'] ?? '',
                    'rarity' => $v['rarity'] ?? Game::rarity((int) $recipe['depth']),
                    'depth' => (int) $recipe['depth'],
                ];
            }
            $pairKey = Seeds::key((string) $ea['name'], (string) $eb['name']);
            $seed = Seeds::RECIPES[$pairKey] ?? null;
            if ($seed) {
                return [
                    'name' => $seed[0],
                    'emoji' => Text::safeEmoji((string) $seed[1]),
                    'tagline' => $seed[2],
                    'rarity' => Game::rarity($depth),
                    'depth' => $depth,
                ];
            }
        }
        $made = Remix::make($ea, $eb);
        return [
            'name' => $made['name'],
            'emoji' => Text::safeEmoji((string) ($made['emoji'] ?? '✨')),
            'tagline' => $made['tagline'],
            'rarity' => Game::rarity($depth),
            'depth' => $depth,
        ];
    }

    /**
     * @param array<string,mixed> $made
     * @param array<string,mixed> $ea
     * @param array<string,mixed> $eb
     * @param array<string,mixed>|null $opp
     * @return array<string,mixed>
     */
    private static function judge(string $theme, array $made, array $ea, array $eb, bool $chain, bool $double, bool $attack, ?array $opp, bool $remote = true): array
    {
        $cacheKey = 'clashj:' . sha1(strtolower($theme . '|' . $made['name'] . '|' . $ea['name'] . '|' . $eb['name'] . '|' . ($opp['name'] ?? '') . '|' . ($attack ? '1' : '0')));
        $cached = Store::get($cacheKey);
        $ai = null;
        if ($cached) {
            $ai = json_decode($cached, true);
        } elseif ($remote) {
            $ai = self::askJudge($theme, $made, $ea, $eb, $attack, $opp);
            if ($ai) {
                try {
                    Store::set($cacheKey, json_encode($ai, JSON_UNESCAPED_UNICODE));
                } catch (Throwable $e) {
                }
            }
        }
        $fallback = self::fallbackJudge($theme, $made, $chain, $double, $attack, $opp);
        $themePts = isset($ai['theme']) ? max(0, min(50, (int) $ai['theme'])) : $fallback['theme'];
        $creative = isset($ai['creative']) ? max(0, min(15, (int) $ai['creative'])) : $fallback['creative'];
        $rarityPts = (int) round(10 * (self::MULT[$made['rarity']] ?? 1));
        $chainPts = $double ? 18 : ($chain ? 12 : 0);
        $attackWorks = $attack && $opp && (isset($ai['attack']) ? (bool) $ai['attack'] : $fallback['attackWorks']);
        $cut = 0;
        $atkBonus = 0;
        if ($attack && $opp) {
            if ($attackWorks) {
                $cut = max(8, (int) floor(((int) ($opp['score'] ?? 20)) * 0.35));
                $atkBonus = 15;
            }
        }
        $score = $themePts + $rarityPts + $creative + $chainPts + $atkBonus;
        return [
            'score' => $score,
            'theme' => $themePts,
            'rarity' => $rarityPts,
            'creative' => $creative,
            'chain' => $chainPts,
            'attackWorks' => $attackWorks,
            'cut' => $cut,
            'attackBonus' => $atkBonus,
            'ai' => $ai !== null,
        ];
    }

    /**
     * @param array<string,mixed> $made
     * @param array<string,mixed> $ea
     * @param array<string,mixed> $eb
     * @param array<string,mixed>|null $opp
     * @return array<string,mixed>|null
     */
    private static function askJudge(string $theme, array $made, array $ea, array $eb, bool $attack, ?array $opp): ?array
    {
        $prompt = 'You judge a 75-second combo battle. Theme: "' . $theme . '". '
            . 'Combo: ' . $ea['name'] . ' + ' . $eb['name'] . ' = ' . $made['name']
            . ($made['tagline'] ? ' ("' . $made['tagline'] . '")' : '')
            . '. Rarity: ' . $made['rarity'] . '.';
        if ($attack && $opp) {
            $prompt .= ' This is an ATTACK on the opponent\'s latest creation: ' . ($opp['name'] ?? 'unknown') . '. Does this combo counter or destroy it?';
        }
        $prompt .= ' Reply ONLY JSON: {"theme":0-50 how well it fits the theme,"creative":0-15 surprise/fun,"attack":true/false}.';
        try {
            $res = Ai::post(Ai::POLLINATIONS, [
                'model' => 'openai',
                'messages' => [
                    ['role' => 'system', 'content' => 'You score playful combinatory creations. Be fair, slightly generous, never rude. JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.4,
            ], [], 3);
            if (($res['code'] ?? 0) < 200 || ($res['code'] ?? 0) >= 300 || empty($res['body'])) {
                return null;
            }
            $body = $res['body'];
            $data = json_decode($body, true);
            $text = is_array($data) ? (string) ($data['choices'][0]['message']['content'] ?? $data['content'] ?? '') : $body;
            if (preg_match('/\{.*\}/s', $text, $mm)) {
                $j = json_decode($mm[0], true);
                if (is_array($j)) {
                    return [
                        'theme' => (int) ($j['theme'] ?? $j['fit'] ?? 20),
                        'creative' => (int) ($j['creative'] ?? $j['creativity'] ?? 6),
                        'attack' => !empty($j['attack']) || !empty($j['works']),
                    ];
                }
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    /**
     * @param array<string,mixed> $made
     * @param array<string,mixed>|null $opp
     * @return array{theme:int,creative:int,attackWorks:bool}
     */
    private static function fallbackJudge(string $theme, array $made, bool $chain, bool $double, bool $attack, ?array $opp): array
    {
        $hay = mb_strtolower($made['name'] . ' ' . ($made['tagline'] ?? '') . ' ' . $made['rarity']);
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($theme)) ?: [];
        $hit = 0;
        $syn = [
            'warrior' => 'war battle sword hero knight armor power strong fight champion',
            'disaster' => 'chaos fire storm crash boom explode wreck ruin funny oops mess',
            'meme' => 'funny lol cat dog vibe cursed chaotic relatable internet joke',
            'destroy' => 'destroy counter crush vanish explode anti cancel break ruin',
            'comfort' => 'cozy soft warm blanket tea home calm hug sleep pillow',
            'snack' => 'food pizza cake candy soup bread snack sweet spicy cook',
            'cute' => 'cute cat puppy baby soft blush sparkle love tiny',
            'flex' => 'gold rich money rare legendary flex crown jewel luxe',
            'twist' => 'plot twist secret surprise unexpected weird glitch',
            'underdog' => 'underdog small weak courage come back hope rebel',
            'party' => 'party loud music dance confetti night disco boom',
            'hero' => 'hero legendary savior epic power myth',
        ];
        foreach ($words as $w) {
            if (strlen($w) < 4) {
                continue;
            }
            if (str_contains($hay, $w)) {
                $hit += 2;
            }
            foreach ($syn as $k => $extra) {
                if ($w === $k || str_contains($w, $k)) {
                    foreach (explode(' ', $extra) as $s) {
                        if ($s !== '' && str_contains($hay, $s)) {
                            $hit++;
                        }
                    }
                }
            }
        }
        $themePts = min(50, 14 + $hit * 6);
        $creative = min(15, 5 + (mb_strlen($made['name']) % 6) + ($chain ? 3 : 0) + (($made['rarity'] === 'Legendary' || $made['rarity'] === 'Epic') ? 3 : 0));
        $works = false;
        if ($attack && $opp) {
            $oh = mb_strtolower((string) ($opp['name'] ?? ''));
            $counters = ['water' => 'fire', 'fire' => 'ice', 'ice' => 'fire', 'light' => 'dark', 'dark' => 'light', 'cat' => 'dog', 'order' => 'chaos', 'chaos' => 'order', 'life' => 'death', 'death' => 'life'];
            foreach ($counters as $a => $b) {
                if ((str_contains($hay, $a) && str_contains($oh, $b)) || (str_contains($hay, 'anti') || str_contains($hay, 'destroy') || str_contains($hay, 'counter') || str_contains($hay, 'crash') || str_contains($hay, 'break'))) {
                    $works = true;
                    break;
                }
            }
            // Shared root words often "answer" each other in a playful way.
            $ow = preg_split('/[^a-z0-9]+/i', $oh) ?: [];
            foreach ($ow as $w) {
                if (strlen($w) >= 4 && str_contains($hay, $w)) {
                    $works = true;
                }
            }
            // Deterministic slight chance so attacks are not hopeless.
            if (!$works && (crc32($made['name'] . '|' . $oh) % 4 === 0)) {
                $works = true;
            }
        }
        return ['theme' => $themePts, 'creative' => $creative, 'attackWorks' => $works];
    }

    /** @param array<string,mixed> $round @return array<string,mixed>|null */
    private static function latestPlay(array $round, string $pid): ?array
    {
        $side = null;
        // We do not have a_id on the round; encoded in a_latest/b_latest plus plays table.
        $row = Db::one('SELECT * FROM cc_clash_plays WHERE round_id = ? AND player_id = ? ORDER BY id DESC', [$round['id'], $pid]);
        if (!$row) {
            return null;
        }
        return [
            'playId' => (int) $row['id'],
            'name' => $row['result_name'],
            'emoji' => $row['result_emoji'],
            'score' => (int) $row['score'],
            'rarity' => $row['rarity'],
        ];
    }

    private static function createMatch(string $a, string $b): array
    {
        $id = self::id('m');
        $now = Db::now();
        Db::run(
            'INSERT INTO cc_clash_matches (id, a_id, b_id, status, round, a_wins, b_wins, winner_id, forfeit_id, inter_until, created_at, updated_at) VALUES (?,?,?,?,0,0,0,NULL,NULL,NULL,?,?)',
            [$id, $a, $b, 'loadout', $now, $now]
        );
        self::touchId($a, $id);
        self::touchId($b, $id);
        return self::getMatch($id);
    }

    /** @return array<string,mixed> */
    private static function getMatch(string $id): array
    {
        $m = Db::one('SELECT * FROM cc_clash_matches WHERE id = ?', [$id]);
        if (!$m) {
            throw new HttpError('Match not found.', 404);
        }
        return $m;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function mine(array $p, string $id): array
    {
        $m = self::getMatch($id);
        if ($m['a_id'] !== $p['id'] && $m['b_id'] !== $p['id']) {
            throw new HttpError('This is not your match.', 403);
        }
        self::touch($p, $id);
        return $m;
    }

    /** @param array<string,mixed> $p */
    private static function activeMatch(array $p): ?array
    {
        return Db::one(
            "SELECT * FROM cc_clash_matches WHERE status IN ('loadout','playing','inter') AND (a_id = ? OR b_id = ?) ORDER BY created_at DESC",
            [$p['id'], $p['id']]
        );
    }

    /** @param array<string,mixed> $m @return array<string,mixed>|null */
    private static function currentRound(array $m): ?array
    {
        if ((int) $m['round'] < 1) {
            return null;
        }
        return Db::one('SELECT * FROM cc_clash_rounds WHERE match_id = ? AND n = ?', [$m['id'], (int) $m['round']]);
    }

    /** @param array<string,mixed> $p */
    private static function touch(array $p, ?string $matchId): void
    {
        self::touchId($p['id'], $matchId);
    }

    private static function touchId(string $pid, ?string $matchId): void
    {
        $now = Db::now();
        $row = Db::one('SELECT * FROM cc_clash_seen WHERE player_id = ?', [$pid]);
        if ($row) {
            Db::run('UPDATE cc_clash_seen SET last_poll = ?, match_id = COALESCE(?, match_id) WHERE player_id = ?', [$now, $matchId, $pid]);
        } else {
            Db::run('INSERT INTO cc_clash_seen (player_id, last_poll, match_id) VALUES (?,?,?)', [$pid, $now, $matchId]);
        }
    }

    /** @param array<string,mixed> $p */
    private static function sweep(array $p): void
    {
        self::expireChallenges();
        $live = self::activeMatch($p);
        if (!$live) {
            return;
        }
        $opp = self::oppId($live, $p['id']);
        if (self::isAiId($opp)) {
            return;
        }
        $seen = Db::one('SELECT * FROM cc_clash_seen WHERE player_id = ?', [$opp]);
        $limit = $live['status'] === 'loadout' ? self::DISCONNECT_LOADOUT_SECS : self::DISCONNECT_PLAY_SECS;
        $last = $seen ? strtotime((string) $seen['last_poll'] . ' UTC') : strtotime((string) $live['updated_at'] . ' UTC');
        if ($last && (time() - $last) > $limit) {
            self::endForfeit($live, $opp, 'disconnect');
        }
    }

    private static function expireChallenges(): void
    {
        Db::run("UPDATE cc_clash_challenges SET status = 'expired' WHERE status IN ('pending','waiting') AND expires_at < ?", [Db::now()]);
    }

    /** @param array<string,mixed> $p */
    private static function needName(array $p): bool
    {
        return trim((string) ($p['nickname'] ?? '')) === '';
    }

    /** @param array<string,mixed> $p */
    private static function requireName(array $p): void
    {
        if (self::needName($p)) {
            throw new HttpError('Pick a display name first so people know who beat them.', 422);
        }
    }

    /** @param array<string,mixed> $p @return array<int,true> */
    private static function ownedIds(array $p): array
    {
        $out = [];
        foreach (Game::collection($p) as $e) {
            $out[(int) $e['id']] = true;
        }
        return $out;
    }

    /** @param array<string,mixed> $m */
    private static function oppId(array $m, string $pid): string
    {
        return $m['a_id'] === $pid ? (string) $m['b_id'] : (string) $m['a_id'];
    }

    /** @return array{id:string,name:string,avatar:string} */
    private static function playerMini(string $id): array
    {
        $p = Db::one('SELECT id, nickname, avatar FROM cc_players WHERE id = ?', [$id]);
        $mini = [
            'id' => $id,
            'name' => $p['nickname'] ?? 'Player',
            'avatar' => Text::safeEmoji((string) (($p['avatar'] ?? '') ?: '🐱')),
        ];
        try {
            $mini['rank'] = ClashRank::of($id);
        } catch (Throwable $e) {
            // ranks table may not exist until schema catches up
        }
        return $mini;
    }

    /** @param array<string,mixed>|null $best */
    private static function headline(string $winner, string $loser, ?array $best): string
    {
        $w = $winner !== '' ? '@' . $winner : 'a rival';
        $l = $loser !== '' ? '@' . $loser : 'a rival';
        if ($best && !empty($best['a']) && !empty($best['name'])) {
            return 'I beat ' . $l . ' with ' . $best['a'] . ' + ' . $best['b'] . ' = ' . $best['name'];
        }
        return 'I beat ' . $l . ' in Combo Clash';
    }

    /** @return array<string,mixed>|null */
    private static function bestPlay(string $matchId, string $pid): ?array
    {
        $row = Db::one(
            'SELECT p.* FROM cc_clash_plays p JOIN cc_clash_rounds r ON r.id = p.round_id WHERE r.match_id = ? AND p.player_id = ? ORDER BY CASE p.rarity WHEN \'Legendary\' THEN 4 WHEN \'Epic\' THEN 3 WHEN \'Rare\' THEN 2 ELSE 1 END DESC, p.score DESC, p.id DESC',
            [$matchId, $pid]
        );
        if (!$row) {
            return null;
        }
        return [
            'a' => $row['a_name'],
            'b' => $row['b_name'],
            'name' => $row['result_name'],
            'emoji' => Text::safeEmoji((string) $row['result_emoji']),
            'tagline' => $row['result_tagline'],
            'rarity' => $row['rarity'],
            'score' => (int) $row['score'],
        ];
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $p @return array<string,mixed> */
    private static function challengeView(array $c, array $p): array
    {
        $from = self::playerMini((string) $c['from_id']);
        $to = $c['to_id'] ? self::playerMini((string) $c['to_id']) : null;
        $exp = strtotime((string) $c['expires_at'] . ' UTC') * 1000;
        return [
            'id' => $c['id'],
            'status' => $c['status'],
            'from' => $from,
            'to' => $to,
            'expiresAt' => $exp,
            'mine' => $c['from_id'] === $p['id'],
            'incoming' => $c['to_id'] === $p['id'],
            'matchId' => $c['match_id'],
        ];
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $p @return array<string,mixed> */
    private static function matchView(array $m, array $p): array
    {
        $youAreA = $m['a_id'] === $p['id'];
        $you = self::playerMini($p['id']);
        $opp = self::playerMini(self::oppId($m, $p['id']));
        $round = self::currentRound($m);
        $youLo = Db::one('SELECT * FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$m['id'], $p['id']]);
        $oppLo = Db::one('SELECT * FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$m['id'], self::oppId($m, $p['id'])]);
        $endsAt = $round ? strtotime((string) $round['ends_at'] . ' UTC') * 1000 : null;
        $startsAt = $round ? strtotime((string) $round['started_at'] . ' UTC') * 1000 : null;
        $youPlays = [];
        $oppPlays = [];
        if ($round) {
            foreach (Db::all('SELECT * FROM cc_clash_plays WHERE round_id = ? ORDER BY id', [$round['id']]) as $pl) {
                $item = [
                    'id' => (int) $pl['id'],
                    'token' => 't:' . (int) $pl['id'],
                    'name' => $pl['result_name'],
                    'emoji' => Text::safeEmoji((string) $pl['result_emoji']),
                    'tagline' => $pl['result_tagline'],
                    'rarity' => $pl['rarity'],
                    'score' => (int) $pl['score'],
                    'a' => $pl['a_name'],
                    'b' => $pl['b_name'],
                    'attack' => (int) $pl['is_attack'] === 1,
                    'breakdown' => json_decode((string) $pl['breakdown'], true) ?: new \stdClass(),
                ];
                if ($pl['player_id'] === $p['id']) {
                    $youPlays[] = $item;
                } else {
                    $oppPlays[] = $item;
                }
            }
        }
        $collection = [];
        if ($m['status'] === 'loadout') {
            foreach (Game::collection($p) as $e) {
                $collection[] = [
                    'id' => (int) $e['id'],
                    'name' => $e['name'],
                    'emoji' => $e['emoji'],
                    'rarity' => $e['rarity'] ?? 'Common',
                    'tagline' => $e['tagline'] ?? '',
                    'depth' => $e['depth'] ?? 1,
                ];
            }
        }
        $loadoutEls = [];
        if ($youLo && $m['status'] !== 'loadout') {
            foreach (json_decode((string) $youLo['ids'], true) ?: [] as $id) {
                $e = Game::element((int) $id);
                if ($e) {
                    $v = Game::view($e);
                    $loadoutEls[] = $v + ['token' => 'e:' . (int) $id];
                }
            }
        } elseif ($youLo) {
            foreach (json_decode((string) $youLo['ids'], true) ?: [] as $id) {
                $loadoutEls[] = ['id' => (int) $id, 'token' => 'e:' . (int) $id];
            }
        }
        $youScore = 0;
        $oppScore = 0;
        if ($round) {
            $youScore = (int) $round[$youAreA ? 'a_score' : 'b_score'];
            $oppScore = (int) $round[$youAreA ? 'b_score' : 'a_score'];
        }
        $share = null;
        if (in_array($m['status'], ['finished', 'forfeit'], true)) {
            $share = self::share($m['id']);
        }
        $lastRound = null;
        if ($m['status'] === 'inter' || in_array($m['status'], ['finished', 'forfeit'], true)) {
            $lr = Db::one('SELECT * FROM cc_clash_rounds WHERE match_id = ? ORDER BY n DESC', [$m['id']]);
            if ($lr) {
                $lastRound = [
                    'n' => (int) $lr['n'],
                    'theme' => $lr['theme'],
                    'you' => (int) $lr[$youAreA ? 'a_score' : 'b_score'],
                    'opp' => (int) $lr[$youAreA ? 'b_score' : 'a_score'],
                    'winner' => $lr['winner_id'] ? self::playerMini((string) $lr['winner_id']) : null,
                ];
            }
        }
        return [
            'id' => $m['id'],
            'status' => $m['status'],
            'round' => (int) $m['round'],
            'youWins' => $youAreA ? (int) $m['a_wins'] : (int) $m['b_wins'],
            'oppWins' => $youAreA ? (int) $m['b_wins'] : (int) $m['a_wins'],
            'you' => $you,
            'opp' => $opp,
            'winnerId' => $m['winner_id'],
            'forfeitId' => $m['forfeit_id'],
            'youReady' => $youLo ? (int) $youLo['ready'] === 1 : false,
            'oppReady' => $oppLo ? (int) $oppLo['ready'] === 1 : false,
            'youLoadoutCount' => $youLo ? count(json_decode((string) $youLo['ids'], true) ?: []) : 0,
            'oppLoadoutCount' => $oppLo ? count(json_decode((string) $oppLo['ids'], true) ?: []) : 0,
            'loadoutIds' => $youLo ? array_map('intval', json_decode((string) $youLo['ids'], true) ?: []) : [],
            'loadout' => $loadoutEls,
            'collection' => $collection,
            'theme' => $round['theme'] ?? null,
            'endsAt' => $endsAt,
            'startsAt' => $startsAt,
            'interUntil' => $m['inter_until'] ? strtotime((string) $m['inter_until'] . ' UTC') * 1000 : null,
            'roundSecs' => self::ROUND_SECS,
            'youScore' => $youScore,
            'oppScore' => $oppScore,
            'youPlays' => $youPlays,
            'oppPlays' => $oppPlays,
            'youLatest' => $youPlays ? $youPlays[count($youPlays) - 1] : null,
            'oppLatest' => $oppPlays ? $oppPlays[count($oppPlays) - 1] : null,
            'lastRound' => $lastRound,
            'share' => $share,
            'now' => Db::nowMs(),
            'isAi' => self::isAiId(self::oppId($m, $p['id'])),
            'aiLevel' => self::aiLevelOf($m),
            'youTeam' => $youAreA ? 'blue' : 'orange',
            'oppTeam' => $youAreA ? 'orange' : 'blue',
            'overtime' => (int) $m['round'] >= 4,
            'kickoffUntil' => $startsAt ? $startsAt + (self::KICKOFF_SECS * 1000) : null,
            'kickoffSecs' => self::KICKOFF_SECS,
            'boost' => $round ? self::boostOf($m['id'], $p['id'], (int) $round['n']) : 0,
            'boostMax' => self::BOOST_MAX,
            'boostAttack' => self::BOOST_ATTACK,
            'boostBurst' => self::BOOST_BURST,
            'quick' => self::recentQuick($m['id']),
            'stats' => in_array($m['status'], ['finished', 'forfeit'], true) ? ClashRank::matchStats($m['id']) : [],
            'rankChange' => in_array($m['status'], ['finished', 'forfeit'], true) ? ClashRank::summaryFor($m['id'], $p['id']) : null,
            'rematch' => in_array($m['status'], ['finished', 'forfeit'], true)
                ? ClashRematch::publicView(ClashRematch::tick($m['id']), $p['id'])
                : ClashRematch::idle(),
        ];
    }

    private static function depthFromRarity(string $r): int
    {
        return match ($r) {
            'Legendary' => 4,
            'Epic' => 3,
            'Rare' => 2,
            default => 1,
        };
    }


    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function rematchVote(array $p, string $matchId): array
    {
        self::requireName($p);
        $m = self::getMatch($matchId);
        if ($m['a_id'] !== $p['id'] && $m['b_id'] !== $p['id']) {
            throw new HttpError('This is not your match.', 403);
        }
        if (!in_array($m['status'], ['finished', 'forfeit'], true)) {
            throw new HttpError('Finish this match first.', 422);
        }
        if ($live = self::activeMatch($p)) {
            return ['match' => self::matchView($live, $p), 'rematch' => ClashRematch::publicView(ClashRematch::tick($matchId), $p['id'])];
        }
        $a = (string) $m['a_id'];
        $b = (string) $m['b_id'];
        $seed = [];
        if (self::isAiId($a)) {
            $seed[$a] = true;
        }
        if (self::isAiId($b)) {
            $seed[$b] = true;
        }
        $st = ClashRematch::open($matchId, 'duel', [$a, $b], $seed, $p['id']);
        $st = ClashRematch::tick($matchId);
        if (($st['status'] ?? '') === 'cancelled') {
            return ['match' => self::matchView($m, $p), 'rematch' => ClashRematch::publicView($st, $p['id'])];
        }
        if (ClashRematch::allVoted($st)) {
            $opp = self::oppId($m, $p['id']);
            if (self::isAiId($opp)) {
                $res = self::challengeAi($p, (string) self::levelFromId($opp));
                $nid = $res['match']['id'] ?? null;
                if (is_string($nid) && $nid !== '') {
                    ClashRematch::markAccepted($matchId, $st, $nid);
                }
                $st = ClashRematch::load($matchId) ?: $st;
                return $res + ['rematch' => ClashRematch::publicView($st, $p['id'])];
            }
            $nm = self::createMatch($a, $b);
            ClashRematch::markAccepted($matchId, $st, (string) $nm['id']);
            return ['match' => self::matchView($nm, $p), 'rematch' => ClashRematch::publicView(ClashRematch::load($matchId) ?: $st, $p['id']), 'challenged' => false];
        }
        return ['match' => self::matchView($m, $p), 'rematch' => ClashRematch::publicView($st, $p['id'])];
    }

    /** @return array{id:string,name:string,avatar:string} */
    public static function playerPublic(string $id): array
    {
        if (self::isAiId($id)) {
            foreach (self::AI as $ai) {
                if ($ai['id'] === $id) {
                    return ['id' => $id, 'name' => $ai['name'], 'avatar' => $ai['avatar']];
                }
            }
            $level = self::levelFromId($id);
            if ($level && preg_match('/_p(\d+)$/', $id, $m)) {
                return ['id' => $id, 'name' => self::partyBotName($level, (int) $m[1]), 'avatar' => self::AI[$level]['avatar']];
            }
        }
        return self::playerMini($id);
    }

    /** @return array<string,mixed>|null */
    public static function aiCfg(string $level): ?array
    {
        $level = self::normLevel($level) ?? $level;
        return self::AI[$level] ?? null;
    }

    public static function isAiId(string $id): bool
    {
        return str_starts_with($id, 'pai_');
    }

    public static function isHiddenName(?string $name): bool
    {
        $n = trim((string) $name);
        return $n !== '' && (bool) preg_match('/^(CcBot|CcFin|CcQA|CcTest|CcAi|CcAud)/i', $n);
    }

    public static function isHiddenFromBoards(?string $id, ?string $name): bool
    {
        if ($id && self::isAiId($id)) {
            return true;
        }
        return self::isHiddenName($name);
    }

    public static function normLevel(string $level): ?string
    {
        $level = strtolower(trim($level));
        return isset(self::AI[$level]) ? $level : null;
    }

    public static function levelFromId(string $id): ?string
    {
        foreach (self::AI as $level => $cfg) {
            if ($cfg['id'] === $id) {
                return $level;
            }
        }
        if (preg_match('/^pai_(easy|medium|hard)_p\d+$/', $id, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function partyBotId(string $level, int $n): string
    {
        return 'pai_' . $level . '_p' . $n;
    }

    public static function partyBotName(string $level, int $n): string
    {
        return ucfirst($level) . ' Bot ' . $n;
    }

    public static function ensurePartyBot(string $level, int $n): void
    {
        if (!isset(self::AI[$level])) {
            return;
        }
        $id = self::partyBotId($level, $n);
        $nick = self::partyBotName($level, $n);
        $avatar = self::AI[$level]['avatar'];
        $row = Db::one('SELECT * FROM cc_players WHERE id = ?', [$id]);
        $now = Db::now();
        $key = mb_strtolower($nick);
        if ($row) {
            if (($row['nickname'] ?? '') !== $nick) {
                Db::run('UPDATE cc_players SET nickname = ?, nick_key = ?, avatar = ?, last_seen = ? WHERE id = ?', [$nick, $key, $avatar, $now, $id]);
            }
            return;
        }
        $hash = hash('sha256', 'cc-reserved-ai|' . $id);
        Db::run(
            'INSERT INTO cc_players (id, token_hash, nickname, nick_key, avatar, day, day_count, streak, last_day, firsts, combos, ip_hash, created_at, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $hash, $nick, $key, $avatar, '', 0, 0, '', 0, 0, 'ai-reserved', $now, $now]
        );
    }

    /** @param array<string,mixed> $m */
    public static function aiLevelOf(array $m): ?string
    {
        return self::levelFromId((string) ($m['a_id'] ?? '')) ?? self::levelFromId((string) ($m['b_id'] ?? ''));
    }

    private static function ensureAiPlayer(string $level): void
    {
        $cfg = self::AI[$level];
        $row = Db::one('SELECT * FROM cc_players WHERE id = ?', [$cfg['id']]);
        $now = Db::now();
        $nick = $cfg['name'];
        $key = mb_strtolower($nick);
        if ($row) {
            if (($row['nickname'] ?? '') !== $nick) {
                Db::run('UPDATE cc_players SET nickname = ?, nick_key = ?, avatar = ?, last_seen = ? WHERE id = ?', [$nick, $key, $cfg['avatar'], $now, $cfg['id']]);
            }
            return;
        }
        $hash = hash('sha256', 'cc-reserved-ai|' . $cfg['id']);
        Db::run(
            'INSERT INTO cc_players (id, token_hash, nickname, nick_key, avatar, day, day_count, streak, last_day, firsts, combos, ip_hash, created_at, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$cfg['id'], $hash, $nick, $key, $cfg['avatar'], '', 0, 0, '', 0, 0, 'ai-reserved', $now, $now]
        );
    }

    /** @param array<string,mixed> $m */
    private static function prepareAiLoadout(array $m, string $level): void
    {
        $cfg = self::AI[$level];
        $aiId = $cfg['id'];
        $bases = Db::all('SELECT id FROM cc_elements WHERE is_base = 1 ORDER BY sort_order, id');
        $ids = [];
        foreach ($bases as $row) {
            $ids[] = (int) $row['id'];
            if (count($ids) >= (int) $cfg['cards']) {
                break;
            }
        }
        if (count($ids) < self::MIN_LOADOUT) {
            return;
        }
        Db::upsert(
            'cc_clash_loadouts',
            ['match_id' => $m['id'], 'player_id' => $aiId, 'ids' => json_encode($ids), 'ready' => 1, 'last_play_ms' => 0],
            ['match_id', 'player_id'],
            ['ids', 'ready']
        );
        self::touchId($aiId, $m['id']);
    }

    /** @param array<string,mixed> $m */
    private static function ensureAiReady(array $m): void
    {
        $level = self::aiLevelOf($m);
        if (!$level) {
            return;
        }
        self::ensureAiPlayer($level);
        $aiId = self::AI[$level]['id'];
        $lo = Db::one('SELECT * FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$m['id'], $aiId]);
        if (!$lo || !(int) $lo['ready']) {
            self::prepareAiLoadout($m, $level);
        } else {
            self::touchId($aiId, $m['id']);
        }
    }

    /** @param array<string,mixed> $m */
    private static function maybeAiPlay(array $m): void
    {
        $level = self::aiLevelOf($m);
        if (!$level || $m['status'] !== 'playing') {
            return;
        }
        $cfg = self::AI[$level];
        $aiId = $cfg['id'];
        $round = self::currentRound($m);
        if (!$round || (int) $round['settled'] || strtotime((string) $round['ends_at'] . ' UTC') <= time()) {
            return;
        }
        if (time() < strtotime((string) $round['started_at'] . ' UTC') + self::KICKOFF_SECS) {
            return;
        }
        $lo = Db::one('SELECT * FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$m['id'], $aiId]);
        if (!$lo) {
            return;
        }
        $nowMs = Db::nowMs();
        $last = (int) ($lo['last_play_ms'] ?? 0);
        $startMs = strtotime((string) $round['started_at'] . ' UTC') * 1000;
        $wait = $last > 0 ? (int) $cfg['gapMs'] : (int) $cfg['firstMs'];
        $from = $last > 0 ? $last : $startMs;
        if ($nowMs - $from < $wait) {
            return;
        }
        $ai = Db::one('SELECT * FROM cc_players WHERE id = ?', [$aiId]);
        if (!$ai) {
            return;
        }
        $hand = self::hand($ai, $m, $round);
        $toks = array_keys($hand);
        if (count($toks) < 1) {
            return;
        }
        $temps = [];
        $bases = [];
        foreach ($hand as $tok => $card) {
            if (!empty($card['temp'])) {
                $temps[] = $tok;
            } else {
                $bases[] = $tok;
            }
        }
        $roll = (crc32($m['id'] . '|' . $round['id'] . '|' . $nowMs) % 1000) / 1000;
        $a = $bases[0] ?? $toks[0];
        $b = $bases[1] ?? $bases[0] ?? $toks[0];
        if ($roll < (float) $cfg['chain'] && $temps) {
            $a = $temps[array_key_last($temps)];
            $b = $bases ? $bases[crc32((string) $nowMs) % count($bases)] : ($temps[0] ?? $a);
            if ($b === $a && count($temps) > 1) {
                $b = $temps[0];
            }
        } else {
            $i = crc32($m['id'] . '|' . (string) $nowMs) % count($toks);
            $a = $toks[$i];
            $b = $toks[($i + 1) % count($toks)];
        }
        $humanId = $m['a_id'] === $aiId ? (string) $m['b_id'] : (string) $m['a_id'];
        $oppLatest = self::latestPlay($round, $humanId);
        $attack = $oppLatest && $roll < (float) $cfg['attack'] + 0.15;
        try {
            self::play($ai, (string) $m['id'], (string) $a, (string) $b, $attack, true);
        } catch (Throwable $e) {
            error_log('[combocraft ai play] ' . $e->getMessage());
        }
    }

    public const QUICK_PHRASES = [
        'What a combo!',
        'Nice one!',
        'Close one!',
        'Calculated.',
        'Wow!',
    ];

    /** @param array<string,mixed> $p */
    public static function quick(array $p, string $matchId, string $phrase): array
    {
        $m = self::mine($p, $matchId);
        if (!in_array($m['status'], ['playing', 'inter', 'loadout'], true)) {
            throw new HttpError('The match is over.', 422);
        }
        $phrase = trim($phrase);
        if (!in_array($phrase, self::QUICK_PHRASES, true)) {
            throw new HttpError('Unknown quick chat.', 422);
        }
        Db::run(
            'INSERT INTO cc_clash_quick (match_id, player_id, phrase, created_at) VALUES (?,?,?,?)',
            [$matchId, $p['id'], $phrase, Db::now()]
        );
        return ['ok' => true, 'quick' => self::recentQuick($matchId), 'match' => self::matchView(self::getMatch($matchId), $p)];
    }

    /** @return list<array<string,mixed>> */
    private static function recentQuick(string $matchId): array
    {
        $rows = Db::all(
            'SELECT q.*, p.nickname, p.avatar FROM cc_clash_quick q LEFT JOIN cc_players p ON p.id = q.player_id WHERE q.match_id = ? ORDER BY q.id DESC LIMIT 8',
            [$matchId]
        );
        $out = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'playerId' => (string) $r['player_id'],
                'name' => $r['nickname'] ?? 'Player',
                'avatar' => Text::safeEmoji((string) (($r['avatar'] ?? '') ?: '⚡')),
                'phrase' => (string) $r['phrase'],
                'at' => strtotime((string) $r['created_at'] . ' UTC') * 1000,
            ];
        }
        return $out;
    }

    private static function boostKey(string $matchId, string $playerId, int $round): string
    {
        return 'cb:' . $matchId . ':' . $playerId . ':' . $round;
    }

    private static function boostOf(string $matchId, string $playerId, int $round): int
    {
        $v = Store::get(self::boostKey($matchId, $playerId, $round));
        return max(0, min(self::BOOST_MAX, (int) $v));
    }

    private static function setBoost(string $matchId, string $playerId, int $round, int $n): void
    {
        Store::set(self::boostKey($matchId, $playerId, $round), (string) max(0, min(self::BOOST_MAX, $n)));
    }

    private static function id(string $p): string
    {
        return $p . bin2hex(random_bytes(8));
    }
}
