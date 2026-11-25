<?php

declare(strict_types=1);

namespace ComboCraft;

/** Reveals one new combo from the player's current elements. 3 per day free. */
final class Hint
{
    public const DAILY = 3;

    /** @param array<string,mixed> $player @return array{left:int,used:int,limit:int} */
    public static function remaining(array $player): array
    {
        $day = Player::today();
        $used = (int) (Db::value('SELECT used FROM cc_hints WHERE player_id = ? AND day = ?', [$player['id'], $day]) ?? 0);
        return ['used' => $used, 'limit' => self::DAILY, 'left' => max(0, self::DAILY - $used)];
    }

    /**
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function give(array $player): array
    {
        $left = self::remaining($player);
        if ($left['left'] <= 0) {
            throw new HttpError('You used all ' . self::DAILY . ' free hints for today. Come back tomorrow!', 429);
        }
        $hint = self::find($player);
        if ($hint === null) {
            throw new HttpError('No new hints right now. Discover a few more elements and try again.', 404);
        }
        $day = Player::today();
        $row = Db::one('SELECT used FROM cc_hints WHERE player_id = ? AND day = ?', [$player['id'], $day]);
        if ($row) {
            Db::run('UPDATE cc_hints SET used = used + 1 WHERE player_id = ? AND day = ?', [$player['id'], $day]);
        } else {
            Game::insertPublic('cc_hints', ['player_id' => $player['id'], 'day' => $day, 'used' => 1]);
        }
        Goals::unlock($player, 'hint_taker');
        $left = self::remaining($player);
        return $hint + ['hints' => $left];
    }

    /** @param array<string,mixed> $player @return array<string,mixed>|null */
    public static function find(array $player): ?array
    {
        $owned = [];
        foreach (Game::collection($player) as $e) {
            $owned[(int) $e['id']] = $e;
        }
        if (count($owned) < 2) {
            return null;
        }
        $ids = array_keys($owned);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all(
            "SELECT r.a_id, r.b_id, r.result_id FROM cc_recipes r
             WHERE r.a_id IN ($ph) AND r.b_id IN ($ph)
             AND r.result_id NOT IN ($ph)
             ORDER BY r.created_at ASC LIMIT 40",
            array_merge($ids, $ids, $ids)
        );
        $pick = $rows[0] ?? null;
        // Also scan seed recipes whose names the player already has, in case the result isn't in cc_recipes yet.
        if ($pick === null) {
            $byKey = [];
            foreach ($owned as $e) {
                $byKey[Text::key((string) $e['name'])] = $e;
            }
            foreach (Seeds::RECIPES as $pair => $r) {
                [$ka, $kb] = explode('|', $pair);
                if (!isset($byKey[$ka], $byKey[$kb])) {
                    continue;
                }
                $resKey = Text::key($r[0]);
                $already = false;
                foreach ($owned as $e) {
                    if (Text::key((string) $e['name']) === $resKey) {
                        $already = true;
                        break;
                    }
                }
                if ($already) {
                    continue;
                }
                return [
                    'a' => Game::mini($byKey[$ka] + ['id' => $byKey[$ka]['id'], 'name' => $byKey[$ka]['name'], 'emoji' => $byKey[$ka]['emoji']]),
                    'b' => Game::mini($byKey[$kb] + ['id' => $byKey[$kb]['id'], 'name' => $byKey[$kb]['name'], 'emoji' => $byKey[$kb]['emoji']]),
                    'tease' => 'makes something new',
                ];
            }
            return null;
        }
        $a = Game::element((int) $pick['a_id']);
        $b = Game::element((int) $pick['b_id']);
        if (!$a || !$b) {
            return null;
        }
        return [
            'a' => Game::mini($a),
            'b' => Game::mini($b),
            'tease' => 'makes something new',
        ];
    }
}
