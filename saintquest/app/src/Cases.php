<?php

declare(strict_types=1);

namespace SaintQuest;

/**
 * Street cases met while walking (the beggar, the kids fighting, ...), the
 * town gossip clues and the Holy Mystery. Kindness cases can be done once a
 * day each; clues and the mystery once ever. The beggar costs 50 virtue now
 * and leaves a blessing of 100 that is paid the next time the Store opens
 * ("Give, and it shall be given to you").
 */
final class Cases
{
    public const EVER = 'ever';
    public const GIVE = 50;
    public const BLESSING = 100;
    public const MYSTERY_REWARD = 100;
    public const MYSTERY_KIND_NEEDED = 3;

    /** key => [type, reward, who] */
    public const LIST = [
        'lost_child' => ['kind', 20, 'Rosie'],
        'widow' => ['kind', 20, 'Old Martha'],
        'beggar' => ['give', 0, 'Simon'],
        'thief' => ['kind', 20, 'Jakob'],
        'sick_man' => ['kind', 25, 'Ezra'],
        'kids_fight' => ['kind', 30, 'Tom and Leo'],
        'lonely_kid' => ['kind', 15, 'Mia'],
        'clue_bell' => ['clue', 5, 'Bram and Nell'],
        'clue_dove' => ['clue', 5, 'Greta the baker'],
        'clue_lantern' => ['clue', 5, 'Hugo the gardener'],
    ];

    /** @return array<string,mixed> */
    public static function status(string $userId): array
    {
        $rows = Db::all('SELECT case_key, day FROM sq_cases WHERE user_id = ? AND (day = ? OR day = ?)', [$userId, Deeds::day(), self::EVER]);
        $today = [];
        $clues = [];
        $solved = false;
        foreach ($rows as $r) {
            $k = (string) $r['case_key'];
            if ($k === 'mystery') {
                $solved = true;
            } elseif ((string) $r['day'] === self::EVER) {
                $clues[] = $k;
            } else {
                $today[] = $k;
            }
        }
        $kindEver = (int) Db::value("SELECT COUNT(*) FROM sq_cases WHERE user_id = ? AND day <> ? AND case_key <> 'mystery'", [$userId, self::EVER]);
        $pending = (int) (Db::value('SELECT SUM(amount) FROM sq_blessings WHERE user_id = ? AND paid_at IS NULL', [$userId]) ?? 0);
        return [
            'today' => $today,
            'clues' => $clues,
            'kindDone' => $kindEver,
            'mysteryReady' => !$solved && count($clues) >= 3 && $kindEver >= self::MYSTERY_KIND_NEEDED,
            'mysterySolved' => $solved,
            'blessing' => $pending,
        ];
    }

    /** @return array<string,mixed> */
    public static function complete(string $userId, string $key): array
    {
        $def = self::LIST[$key] ?? null;
        if ($def === null) {
            throw new HttpError('Unknown case.', 404);
        }
        Auth::throttle('case:' . $userId, 60, 600, true);
        [$type, $reward, $who] = $def;
        $day = $type === 'clue' ? self::EVER : Deeds::day();
        return Db::transaction(function () use ($userId, $key, $type, $reward, $who, $day): array {
            if (Db::value('SELECT 1 FROM sq_cases WHERE user_id = ? AND day = ? AND case_key = ?', [$userId, $day, $key]) !== null) {
                throw new HttpError($type === 'clue' ? 'You already know this clue.' : "You already helped {$who} today. Come back tomorrow!", 409);
            }
            if ($type === 'give') {
                $n = Db::run('UPDATE sq_users SET virtue = virtue - ?, updated_at = ? WHERE id = ? AND virtue >= ?', [self::GIVE, Db::now(), $userId, self::GIVE]);
                if ($n !== 1) {
                    throw new HttpError('You need ' . self::GIVE . ' virtue points to give. Help someone or win a Holy Battle first!', 402);
                }
            }
            try {
                Db::run('INSERT INTO sq_cases (id, user_id, day, case_key, reward, created_at) VALUES (?, ?, ?, ?, ?, ?)', [Auth::randomId(), $userId, $day, $key, $reward, Db::now()]);
                if ($type === 'give') {
                    Db::run('INSERT INTO sq_blessings (id, user_id, day, amount, created_at, paid_at) VALUES (?, ?, ?, ?, ?, NULL)', [Auth::randomId(), $userId, $day, self::BLESSING, Db::now()]);
                }
            } catch (\PDOException) {
                throw new HttpError('Already done.', 409);
            }
            Saints::earn($userId, $reward);
            if ($type !== 'clue') {
                Db::run('UPDATE sq_users SET good_deeds = good_deeds + 1 WHERE id = ?', [$userId]);
            }
            return ['case' => $key, 'reward' => $reward, 'gave' => $type === 'give' ? self::GIVE : 0, 'blessing' => $type === 'give' ? self::BLESSING : 0];
        });
    }

    /** Pay every waiting blessing once (called when the Store opens). */
    public static function claimBlessing(string $userId): array
    {
        return Db::transaction(function () use ($userId): array {
            $rows = Db::all('SELECT id, amount FROM sq_blessings WHERE user_id = ? AND paid_at IS NULL', [$userId]);
            $total = 0;
            foreach ($rows as $r) {
                if (Db::run('UPDATE sq_blessings SET paid_at = ? WHERE id = ? AND paid_at IS NULL', [Db::now(), $r['id']]) === 1) {
                    $total += (int) $r['amount'];
                }
            }
            Saints::earn($userId, $total);
            return ['paid' => $total];
        });
    }

    /** Ring the great bell: needs all three clues and three kind deeds. Once ever. */
    public static function solveMystery(string $userId): array
    {
        $s = self::status($userId);
        if ($s['mysterySolved']) {
            throw new HttpError('You already solved the Holy Mystery!', 409);
        }
        if (!$s['mysteryReady']) {
            throw new HttpError('The lantern is still dark. Find all three clues and help three people first.', 409);
        }
        return Db::transaction(function () use ($userId): array {
            try {
                Db::run('INSERT INTO sq_cases (id, user_id, day, case_key, reward, created_at) VALUES (?, ?, ?, ?, ?, ?)', [Auth::randomId(), $userId, self::EVER, 'mystery', self::MYSTERY_REWARD, Db::now()]);
            } catch (\PDOException) {
                throw new HttpError('You already solved the Holy Mystery!', 409);
            }
            Saints::earn($userId, self::MYSTERY_REWARD);
            return ['reward' => self::MYSTERY_REWARD];
        });
    }
}
