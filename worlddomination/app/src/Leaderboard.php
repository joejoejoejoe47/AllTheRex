<?php

declare(strict_types=1);

namespace WorldDomination;

/** Honest persisted scores from server-evaluated matches. */
final class Leaderboard
{
    public const WIN_PCT = Rules::WIN_PCT;
    public const MATCH_MS = Rules::MATCH_MS;

    /** @return list<array<string,mixed>> */
    public static function podium(int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        $rows = Db::all(
            'SELECT subject_key, display_name, is_bot, points, wins, matches, best_pct
             FROM wd_scores
             ORDER BY points DESC, best_pct DESC, wins DESC, display_name ASC
             LIMIT ' . $limit
        );
        $out = [];
        $rank = 1;
        foreach ($rows as $row) {
            $out[] = [
                'rank' => $rank++,
                'displayName' => (string) $row['display_name'],
                'isBot' => ((int) $row['is_bot']) === 1,
                'points' => (int) $row['points'],
                'wins' => (int) $row['wins'],
                'matches' => (int) $row['matches'],
                'bestPct' => (float) $row['best_pct'],
            ];
        }
        return $out;
    }

    /**
     * @return array{matchId:string,token:string,country:string,startedAt:string,winPct:float,durationMs:int,spawn:array{x:int,y:int}}
     */
    public static function startMatch(string $userId, string $country): array
    {
        $country = self::normalizeCountry($country);
        $info = Geo::countryById($country);
        if ($info === null || !isset($info['arena']) || !is_array($info['arena'])) {
            throw new HttpError('Unknown country.', 400);
        }
        Auth::throttle('match-start:' . $userId, 30, 60, true);

        $now = Db::now();
        Db::run(
            "UPDATE wd_matches SET finished_at = ?, outcome = 'abandoned' WHERE user_id = ? AND (finished_at IS NULL OR finished_at = '')",
            [$now, $userId]
        );

        $id = Auth::randomId();
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $grid = Geo::rasterizeArena($info['arena'], Rules::GRID);
        $spawn = Geo::pickSpawn($grid, $id);
        Db::run(
            'INSERT INTO wd_matches (id, user_id, country_code, token_hash, started_at)
             VALUES (?, ?, ?, ?, ?)',
            [$id, $userId, $country, hash('sha256', $token), $now]
        );
        return [
            'matchId' => $id,
            'token' => $token,
            'country' => $country,
            'startedAt' => $now,
            'winPct' => self::WIN_PCT,
            'durationMs' => self::MATCH_MS,
            'spawn' => $spawn,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{player:array<string,mixed>,podium:list<array<string,mixed>>}
     */
    public static function finishMatch(string $userId, array $payload): array
    {
        $token = (string) ($payload['token'] ?? '');
        if ($token === '') {
            throw new HttpError('Missing match token.', 400);
        }
        Auth::throttle('match-finish:' . $userId, 20, 60, true);

        $row = Db::one(
            'SELECT * FROM wd_matches WHERE token_hash = ? AND user_id = ? LIMIT 1',
            [hash('sha256', $token), $userId]
        );
        if ($row === null) {
            throw new HttpError('That match was not found.', 404);
        }
        if ($row['finished_at'] !== null && $row['finished_at'] !== '') {
            throw new HttpError('That match was already recorded.', 409);
        }

        $country = (string) $row['country_code'];
        $info = Geo::countryById($country);
        if ($info === null || !isset($info['arena']) || !is_array($info['arena'])) {
            throw new HttpError('Unknown country.', 400);
        }

        $heartbeats = is_array($payload['heartbeats'] ?? null) ? $payload['heartbeats'] : [];
        $captures = is_array($payload['captures'] ?? null) ? $payload['captures'] : [];
        $intent = (string) ($payload['intent'] ?? 'finish');
        if (!in_array($intent, ['finish', 'quit'], true)) {
            $intent = 'finish';
        }

        $serverElapsed = max(0, Db::nowMs() - Db::toMs($row['started_at']));
        $grid = Geo::rasterizeArena($info['arena'], Rules::GRID);
        $spawn = Geo::pickSpawn($grid, (string) $row['id']);
        $evaluated = MatchEngine::evaluate($grid, $spawn, $heartbeats, $captures, $serverElapsed);
        if (!$evaluated['ok']) {
            throw new HttpError($evaluated['reason'] ?? 'That match log could not be verified.', 422);
        }

        $playerPct = (float) $evaluated['pct'];
        if ($intent === 'quit') {
            $outcome = 'quit';
        } elseif ($playerPct + 0.05 >= self::WIN_PCT) {
            $outcome = 'win';
        } elseif ($serverElapsed >= self::MATCH_MS) {
            $outcome = 'timeout';
        } else {
            $outcome = 'lose';
        }

        if ($outcome === 'win' && $serverElapsed < 8000) {
            throw new HttpError('That conquest was too fast to record.', 422);
        }

        $score = (int) round($playerPct * 10) + ($outcome === 'win' ? 250 : 0);
        $now = Db::now();

        Db::transaction(function () use ($row, $now, $serverElapsed, $playerPct, $score, $outcome, $userId): void {
            Db::run(
                'UPDATE wd_matches
                 SET finished_at = ?, duration_ms = ?, player_pct = ?, player_score = ?, outcome = ?, bots_json = ?
                 WHERE id = ?',
                [$now, $serverElapsed, $playerPct, $score, $outcome, '[]', $row['id']]
            );
            $user = Db::one('SELECT name FROM wd_users WHERE id = ?', [$userId]);
            $name = (string) ($user['name'] ?? 'Player');
            self::addScore('user:' . $userId, $name, false, $score, $outcome === 'win', $playerPct);
        });

        return [
            'player' => [
                'pct' => $playerPct,
                'score' => $score,
                'outcome' => $outcome,
                'durationMs' => $serverElapsed,
            ],
            'podium' => self::podium(),
        ];
    }

    private static function addScore(string $key, string $name, bool $isBot, int $points, bool $win, float $pct): void
    {
        $existing = Db::one('SELECT * FROM wd_scores WHERE subject_key = ?', [$key]);
        if ($existing === null) {
            Db::run(
                'INSERT INTO wd_scores (id, subject_key, display_name, is_bot, points, wins, matches, best_pct, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [Auth::randomId(), $key, $name, $isBot ? 1 : 0, $points, $win ? 1 : 0, $pct, Db::now()]
            );
            return;
        }
        $best = max((float) $existing['best_pct'], $pct);
        Db::run(
            'UPDATE wd_scores
             SET display_name = ?, points = points + ?, wins = wins + ?, matches = matches + 1, best_pct = ?, updated_at = ?
             WHERE subject_key = ?',
            [$name, $points, $win ? 1 : 0, $best, Db::now(), $key]
        );
    }

    public static function normalizeCountry(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function countryExists(string $code): bool
    {
        return Geo::countryById($code) !== null;
    }
}
