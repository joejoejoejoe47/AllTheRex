<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Rematch voting after a finished Combo Clash match.
 * Every player in the match (both teams) must vote yes. A 15s timer
 * starts with the first vote. Timeout or anyone leaving cancels it.
 * vs AI: the bot auto-votes yes.
 */
final class ClashRematch
{
    public const SECS = 15;

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function vote(array $p, string $matchId): array
    {
        if (str_starts_with($matchId, 'g')) {
            return Party::rematchVote($p, $matchId);
        }
        return Clash::rematchVote($p, $matchId);
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function decline(array $p, string $matchId): array
    {
        $st = self::tick($matchId);
        if (!$st || ($st['status'] ?? '') !== 'open') {
            return ['rematch' => $st ?: self::idle()];
        }
        if (!in_array($p['id'], $st['needed'] ?? [], true)) {
            throw new HttpError('This is not your rematch.', 403);
        }
        $st['status'] = 'cancelled';
        $st['reason'] = 'left';
        self::save($matchId, $st);
        return ['rematch' => self::publicView($st, $p['id'])];
    }

    public static function cancelForPlayer(string $playerId): void
    {
        $id = Store::get('rmu:' . $playerId);
        if (!$id) {
            return;
        }
        $st = self::load($id);
        if ($st && ($st['status'] ?? '') === 'open') {
            $st['status'] = 'cancelled';
            $st['reason'] = 'left';
            self::save($id, $st);
        }
    }

    /** @return array<string,mixed> */
    public static function tick(string $matchId): array
    {
        $st = self::load($matchId);
        if (!$st) {
            return self::idle();
        }
        if (($st['status'] ?? '') === 'open' && (int) ($st['expiresAt'] ?? 0) > 0 && Db::nowMs() >= (int) $st['expiresAt']) {
            $st['status'] = 'cancelled';
            $st['reason'] = 'timeout';
            self::save($matchId, $st);
        }
        return $st;
    }

    /** @param array<string,mixed> $st @return array<string,mixed> */
    public static function publicView(array $st, string $viewerId): array
    {
        $needed = $st['needed'] ?? [];
        $votes = $st['votes'] ?? [];
        $players = [];
        foreach ($needed as $pid) {
            $mini = Clash::playerPublic($pid);
            $players[] = [
                'id' => $pid,
                'name' => $mini['name'] ?? 'Player',
                'avatar' => $mini['avatar'] ?? '🐱',
                'voted' => !empty($votes[$pid]),
                'ai' => Clash::isAiId($pid),
                'you' => $pid === $viewerId,
            ];
        }
        $yes = 0;
        foreach ($needed as $pid) {
            if (!empty($votes[$pid])) {
                $yes++;
            }
        }
        $msg = null;
        if (($st['status'] ?? '') === 'cancelled') {
            $msg = ($st['reason'] ?? '') === 'timeout'
                ? 'Rematch timed out — not everyone voted in time.'
                : 'Rematch cancelled — someone left.';
        }
        return [
            'status' => $st['status'] ?? 'idle',
            'reason' => $st['reason'] ?? null,
            'message' => $msg,
            'yes' => $yes,
            'need' => count($needed),
            'expiresAt' => $st['expiresAt'] ?? null,
            'youVoted' => !empty($votes[$viewerId]),
            'players' => $players,
            'newMatchId' => $st['newMatchId'] ?? null,
            'kind' => $st['kind'] ?? 'duel',
        ];
    }

    /** @param list<string> $needed @param array<string,bool> $seedVotes @return array<string,mixed> */
    public static function open(string $matchId, string $kind, array $needed, array $seedVotes, string $voterId): array
    {
        $now = Db::nowMs();
        $st = self::load($matchId);
        if ($st && ($st['status'] ?? '') === 'accepted' && !empty($st['newMatchId'])) {
            return $st;
        }
        if ($st && ($st['status'] ?? '') === 'cancelled') {
            // allow a fresh offer only if nobody started one; stay cancelled once it failed
            return $st;
        }
        if (!$st || ($st['status'] ?? '') === 'idle') {
            $votes = $seedVotes;
            $votes[$voterId] = true;
            $st = [
                'kind' => $kind,
                'status' => 'open',
                'reason' => null,
                'needed' => array_values($needed),
                'votes' => $votes,
                'startedAt' => $now,
                'expiresAt' => $now + (self::SECS * 1000),
                'newMatchId' => null,
            ];
        } else {
            $st['votes'][$voterId] = true;
        }
        foreach ($needed as $pid) {
            Store::set('rmu:' . $pid, $matchId);
        }
        self::save($matchId, $st);
        return $st;
    }

    /** @param array<string,mixed> $st */
    public static function markAccepted(string $matchId, array $st, string $newMatchId): array
    {
        $st['status'] = 'accepted';
        $st['newMatchId'] = $newMatchId;
        self::save($matchId, $st);
        return $st;
    }

    public static function allVoted(array $st): bool
    {
        foreach ($st['needed'] ?? [] as $pid) {
            if (empty($st['votes'][$pid])) {
                return false;
            }
        }
        return !empty($st['needed']);
    }

    /** @return array<string,mixed> */
    public static function idle(): array
    {
        return [
            'status' => 'idle',
            'reason' => null,
            'message' => null,
            'yes' => 0,
            'need' => 0,
            'expiresAt' => null,
            'youVoted' => false,
            'players' => [],
            'newMatchId' => null,
            'kind' => 'duel',
        ];
    }

    /** @return array<string,mixed>|null */
    public static function load(string $matchId): ?array
    {
        $raw = Store::get('rm:' . $matchId);
        if ($raw === null || $raw === '') {
            return null;
        }
        $st = json_decode($raw, true);
        return is_array($st) ? $st : null;
    }

    /** @param array<string,mixed> $st */
    public static function save(string $matchId, array $st): void
    {
        Store::set('rm:' . $matchId, json_encode($st, JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
