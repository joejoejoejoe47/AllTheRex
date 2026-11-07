<?php

declare(strict_types=1);

namespace WorldDomination;

/** Honest persisted scores from finished matches. Never seeds fake players. */
final class Leaderboard
{
    public const WIN_PCT = 50.0;
    public const MATCH_MS = 180000;

    /** @return list<array<string,mixed>> */
    public static function podium(int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        // Limit interpolated as int after clamping — not user SQL.
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
     * @return array{matchId:string,token:string,country:string,startedAt:string,winPct:float,durationMs:int}
     */
    public static function startMatch(string $userId, string $country): array
    {
        $country = self::normalizeCountry($country);
        if (!self::countryExists($country)) {
            throw new HttpError('Unknown country.', 400);
        }
        Auth::throttle('match-start:' . $userId, 30, 60, true);
        $id = Auth::randomId();
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $now = Db::now();
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

        $country = self::normalizeCountry((string) ($payload['country'] ?? $row['country_code']));
        if ($country !== (string) $row['country_code']) {
            throw new HttpError('Country does not match this match.', 400);
        }

        $playerPct = (float) ($payload['playerPct'] ?? 0);
        if (!is_finite($playerPct) || $playerPct < 0 || $playerPct > 100) {
            throw new HttpError('Territory percent must be between 0 and 100.', 400);
        }
        $outcome = (string) ($payload['outcome'] ?? '');
        if (!in_array($outcome, ['win', 'lose', 'timeout', 'quit'], true)) {
            throw new HttpError('Unknown match outcome.', 400);
        }

        $serverElapsed = max(0, Db::nowMs() - Db::toMs($row['started_at']));
        $clientElapsed = (int) ($payload['durationMs'] ?? $serverElapsed);
        $elapsed = max($serverElapsed, 0);
        if ($elapsed < 5000 && $playerPct > 25) {
            throw new HttpError('That result does not look like a real match.', 422);
        }
        if ($elapsed < 12000 && $playerPct >= self::WIN_PCT) {
            throw new HttpError('That conquest was too fast to record.', 422);
        }
        if ($outcome === 'win' && $playerPct + 0.05 < self::WIN_PCT) {
            throw new HttpError('A win requires at least 50% of the country.', 422);
        }

        $score = (int) round($playerPct * 10) + ($outcome === 'win' ? 250 : 0);
        $bots = self::sanitizeBots($payload['bots'] ?? []);
        $now = Db::now();

        Db::transaction(function () use ($row, $now, $elapsed, $playerPct, $score, $outcome, $bots, $userId): void {
            Db::run(
                'UPDATE wd_matches
                 SET finished_at = ?, duration_ms = ?, player_pct = ?, player_score = ?, outcome = ?, bots_json = ?
                 WHERE id = ?',
                [$now, $elapsed, $playerPct, $score, $outcome, json_encode($bots, JSON_UNESCAPED_UNICODE), $row['id']]
            );

            $user = Db::one('SELECT name FROM wd_users WHERE id = ?', [$userId]);
            $name = (string) ($user['name'] ?? 'Player');
            self::addScore('user:' . $userId, $name, false, $score, $outcome === 'win', $playerPct);

            foreach ($bots as $bot) {
                self::addScore(
                    'bot:' . $bot['slug'],
                    $bot['name'],
                    true,
                    $bot['score'],
                    $bot['outcome'] === 'win',
                    $bot['pct']
                );
            }
        });

        return [
            'player' => [
                'pct' => $playerPct,
                'score' => $score,
                'outcome' => $outcome,
                'durationMs' => $elapsed,
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

    /**
     * @param mixed $raw
     * @return list<array{slug:string,name:string,pct:float,score:int,outcome:string}>
     */
    public static function sanitizeBots(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_array($item) || count($out) >= 4) {
                break;
            }
            $slug = strtolower((string) ($item['slug'] ?? ''));
            $slug = preg_replace('/[^a-z0-9-]/', '', $slug) ?? '';
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $name = trim((string) ($item['name'] ?? ''));
            $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
            if ($name === '') {
                $name = 'Bot ' . ucfirst($slug);
            }
            if (!str_starts_with(strtolower($name), 'bot ')) {
                $name = 'Bot ' . $name;
            }
            if (mb_strlen($name) > 28) {
                $name = mb_substr($name, 0, 28);
            }
            $pct = (float) ($item['pct'] ?? 0);
            if (!is_finite($pct) || $pct < 0) {
                $pct = 0;
            }
            if ($pct > 100) {
                $pct = 100.0;
            }
            $outcome = (string) ($item['outcome'] ?? 'lose');
            if (!in_array($outcome, ['win', 'lose', 'timeout', 'quit'], true)) {
                $outcome = 'lose';
            }
            $score = (int) round($pct * 10) + ($outcome === 'win' ? 250 : 0);
            $out[] = [
                'slug' => $slug,
                'name' => $name,
                'pct' => $pct,
                'score' => $score,
                'outcome' => $outcome,
            ];
        }
        return $out;
    }

    public static function normalizeCountry(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function countryExists(string $code): bool
    {
        $ids = self::countryIds();
        return isset($ids[$code]);
    }

    /** @return array<string,true> */
    public static function countryIds(): array
    {
        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }
        $path = dirname(__DIR__, 2) . '/assets/data/countries.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $ids = [];
        if (is_array($data) && isset($data['countries']) && is_array($data['countries'])) {
            foreach ($data['countries'] as $c) {
                if (is_array($c) && isset($c['id'])) {
                    $ids[self::normalizeCountry((string) $c['id'])] = true;
                }
            }
        }
        return $ids;
    }
}
