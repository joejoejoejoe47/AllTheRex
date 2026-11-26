<?php

declare(strict_types=1);

namespace ComboCraft;

/** Reveals one new combo from the player's current elements. 3 per day free; more can be bought with Morse coins. */
final class Hint
{
    public const DAILY = 3;
    public const PACK = 3;
    public const PACK_COST = 25;

    /** @param array<string,mixed> $player @return array{left:int,used:int,limit:int} */
    public static function remaining(array $player): array
    {
        $day = Player::today();
        $used = (int) (Db::value('SELECT used FROM cc_hints WHERE player_id = ? AND day = ?', [$player['id'], $day]) ?? 0);
        $bonus = 0;
        try {
            $bonus = (int) (Db::value('SELECT COALESCE(SUM(hints),0) FROM cc_hint_buys WHERE player_id = ? AND day = ?', [$player['id'], $day]) ?? 0);
        } catch (\Throwable $e) {
        }
        $limit = self::DAILY + $bonus;
        return [
            'used' => $used,
            'limit' => $limit,
            'left' => max(0, $limit - $used),
            'freeLeft' => max(0, self::DAILY - $used),
            'bonus' => $bonus,
            'pack' => self::PACK,
            'packCost' => self::PACK_COST,
        ];
    }

    /**
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function give(array $player): array
    {
        $left = self::remaining($player);
        if ($left['left'] <= 0) {
            throw new HttpError('You are out of hints. Buy 3 more for ' . self::PACK_COST . ' Morse coins.', 429);
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

    /**
     * Buy a pack of 3 hints for PACK_COST Morse coins. Signed-in only.
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function buy(array $player): array
    {
        $morse = MorseLink::status($player);
        if (empty($morse['signedIn']) || empty($morse['linked'])) {
            throw new HttpError('Sign in to buy hints with Morse coins.', 401);
        }
        $link = Db::one('SELECT * FROM cc_morse_links WHERE player_id = ?', [$player['id']]);
        if (!$link) {
            throw new HttpError('Sign in to buy hints with Morse coins.', 401);
        }
        $day = Player::today();
        $uid = (string) $link['morse_user_id'];
        $amount = self::PACK_COST;
        Db::transaction(function () use ($player, $uid, $day, $amount): void {
            $n = Db::run('UPDATE profiles SET coins = coins - ? WHERE user_id = ? AND coins >= ?', [$amount, $uid, $amount]);
            if ($n < 1) {
                $have = (int) (Db::value('SELECT coins FROM profiles WHERE user_id = ?', [$uid]) ?? 0);
                throw new HttpError('You need ' . $amount . ' Morse coins (you have ' . $have . ').', 402);
            }
            Game::insertPublic('cc_hint_buys', [
                'player_id' => $player['id'],
                'morse_user_id' => $uid,
                'day' => $day,
                'amount' => $amount,
                'hints' => self::PACK,
                'created_at' => Db::now(),
            ]);
        });
        $spent = MorseLink::status($player);
        $spent['justSpent'] = $amount;
        $hints = self::remaining($player);
        return ['ok' => true, 'hints' => $hints, 'morse' => $spent, 'bought' => self::PACK, 'cost' => self::PACK_COST];
    }

    /** @param array<string,mixed> $player @return array<string,mixed>|null */
    public static function find(array $player): ?array
    {
        $owned = [];
        $byKey = [];
        foreach (Game::collection($player) as $e) {
            $owned[(int) $e['id']] = $e;
            $byKey[Text::key((string) $e['name'])] = $e;
        }
        try {
            $daily = Daily::nextHint($player, $byKey);
            if ($daily) {
                return $daily;
            }
        } catch (\Throwable $e) {
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
