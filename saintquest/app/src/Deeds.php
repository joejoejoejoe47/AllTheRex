<?php

declare(strict_types=1);

namespace SaintQuest;

/** Daily good deeds: help each villager on the home page once a day. */
final class Deeds
{
    public const REWARD = 25;
    public const LIST = [
        'apples' => ['who' => 'Grandma Agnes', 'title' => 'Carry the apple basket'],
        'lamb' => ['who' => 'Little Tobias', 'title' => 'Find the lost lamb'],
        'chapel' => ['who' => 'Brother Peter', 'title' => 'Ring the chapel bell'],
    ];

    public static function day(): string
    {
        return gmdate('Y-m-d');
    }

    /** @return list<string> */
    public static function today(string $userId): array
    {
        return array_map(fn ($r) => (string) $r['deed_key'], Db::all('SELECT deed_key FROM sq_deeds WHERE user_id = ? AND day = ?', [$userId, self::day()]));
    }

    public static function complete(string $userId, string $key): array
    {
        if (!isset(self::LIST[$key])) {
            throw new HttpError('Unknown good deed.', 404);
        }
        return Db::transaction(function () use ($userId, $key): array {
            if (Db::value('SELECT 1 FROM sq_deeds WHERE user_id = ? AND day = ? AND deed_key = ?', [$userId, self::day(), $key]) !== null) {
                throw new HttpError('You already helped ' . self::LIST[$key]['who'] . ' today. Come back tomorrow!', 409);
            }
            try {
                Db::run('INSERT INTO sq_deeds (id, user_id, day, deed_key, created_at) VALUES (?, ?, ?, ?, ?)', [Auth::randomId(), $userId, self::day(), $key, Db::now()]);
            } catch (\PDOException) {
                throw new HttpError('You already helped ' . self::LIST[$key]['who'] . ' today.', 409);
            }
            Saints::earn($userId, self::REWARD);
            Db::run('UPDATE sq_users SET good_deeds = good_deeds + 1 WHERE id = ?', [$userId]);
            return ['reward' => self::REWARD, 'deed' => $key];
        });
    }
}
