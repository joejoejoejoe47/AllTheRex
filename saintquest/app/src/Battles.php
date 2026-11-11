<?php

declare(strict_types=1);

namespace SaintQuest;

/**
 * Holy Battle mini-games. The server picks the seed, keeps the token, times
 * the match and decides the reward, so a doctored client cannot claim more
 * than the game allows:
 *  - duel:    win when the shadow's darkness is cleared; score = your light left (0..100)
 *  - race:    orbs collected in 45 s against three rival saints whose totals come from the seed
 *  - defense: shadows purified in 60 s while the chapel light holds
 */
final class Battles
{
    public const GAMES = [
        'duel' => ['name' => 'Arena Duel', 'minMs' => 6000, 'maxScore' => 100],
        'race' => ['name' => 'Light-Orb Race', 'minMs' => 42000, 'maxScore' => 60],
        'defense' => ['name' => 'Chapel Defense', 'minMs' => 55000, 'maxScore' => 90],
    ];
    public const DAILY_REWARDED = 30;

    public static function rivals(int $seed): array
    {
        $out = [];
        $x = $seed & 0x7fffffff;
        for ($i = 0; $i < 3; $i++) {
            $x = ($x * 1103515245 + 12345) & 0x7fffffff;
            $out[] = 12 + ($x % 15); // 12..26 orbs
        }
        return $out;
    }

    public static function start(string $userId, string $game, string $saintId): array
    {
        if (!isset(self::GAMES[$game])) {
            throw new HttpError('Unknown battle.', 404);
        }
        $s = Saints::owned($saintId, $userId);
        Auth::throttle('battle:' . $userId, 60, 3600, true);
        $token = bin2hex(random_bytes(16));
        $id = Auth::randomId();
        $seed = random_int(1, 2_000_000_000);
        Db::run(
            'INSERT INTO sq_battles (id, user_id, saint_id, game, seed, token_hash, started_at, started_ms, finished_at, score, won, reward) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)',
            [$id, $userId, $saintId, $game, $seed, hash('sha256', $token), Db::now(), Db::nowMs()]
        );
        $def = Saints::catalog()[$s['saint_key']];
        $opp = array_values(array_filter(array_keys(Saints::catalog()), fn ($k) => $k !== $s['saint_key']));
        return [
            'battleId' => $id, 'token' => $token, 'seed' => $seed, 'game' => $game,
            'stats' => $def['stats'], 'rivals' => $game === 'race' ? array_map(fn ($n, $i) => ['key' => $opp[($seed + $i * 5) % count($opp)], 'orbs' => $n], self::rivals($seed), [0, 1, 2]) : [],
        ];
    }

    public static function finish(string $userId, string $battleId, string $token, int $score, bool $clientWon): array
    {
        return Db::transaction(function () use ($userId, $battleId, $token, $score, $clientWon): array {
            $b = Db::one('SELECT * FROM sq_battles WHERE id = ? AND user_id = ?', [$battleId, $userId]);
            if ($b === null || !hash_equals((string) $b['token_hash'], hash('sha256', $token))) {
                throw new HttpError('That battle was not found.', 404);
            }
            if ($b['finished_at'] !== null) {
                throw new HttpError('That battle is already finished.', 409);
            }
            $game = (string) $b['game'];
            $rules = self::GAMES[$game];
            $elapsed = Db::nowMs() - (int) $b['started_ms'];
            $score = max(0, min($rules['maxScore'], $score));
            $tooFast = $elapsed < $rules['minMs'];
            $won = false;
            if (!$tooFast) {
                $won = match ($game) {
                    'duel' => $clientWon && $score > 0,
                    'race' => $score > max(self::rivals((int) $b['seed'])),
                    'defense' => $clientWon && $score >= 10,
                };
            }
            if ($tooFast) {
                $score = 0;
            }
            $start = gmdate('Y-m-d') . ' 00:00:00';
            $rewardedToday = (int) Db::value('SELECT COUNT(*) FROM sq_battles WHERE user_id = ? AND started_at >= ? AND reward > 0', [$userId, $start]);
            $reward = 0;
            if (!$tooFast && $rewardedToday < self::DAILY_REWARDED) {
                $reward = $won ? 40 + (int) floor($score * 40 / $rules['maxScore']) : 10;
            }
            Db::run('UPDATE sq_battles SET finished_at = ?, score = ?, won = ?, reward = ? WHERE id = ?', [Db::now(), $score, $won ? 1 : 0, $reward, $battleId]);
            $delta = $tooFast ? 0 : ($won ? 16 : -8);
            Db::run(
                'UPDATE sq_saints SET rating = CASE WHEN rating + ? < 800 THEN 800 ELSE rating + ? END, wins = wins + ?, losses = losses + ?, updated_at = ? WHERE id = ?',
                [$delta, $delta, $won ? 1 : 0, (!$won && !$tooFast) ? 1 : 0, Db::now(), $b['saint_id']]
            );
            Saints::earn($userId, $reward);
            $rating = (int) Db::value('SELECT rating FROM sq_saints WHERE id = ?', [$b['saint_id']]);
            return [
                'won' => $won, 'score' => $score, 'reward' => $reward, 'ratingDelta' => $delta, 'rating' => $rating,
                'note' => $tooFast ? 'That battle ended too quickly to count.' : ($reward === 0 ? 'Daily battle rewards are used up. Ratings still count!' : null),
                'rivals' => $game === 'race' ? self::rivals((int) $b['seed']) : null,
            ];
        });
    }
}
