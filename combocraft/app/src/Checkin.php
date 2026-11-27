<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * One free basic per America/Chicago calendar day, from a fixed queue.
 * Missed days do not stack. Existing unlocks are kept.
 */
final class Checkin
{
    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Chicago')))->format('Y-m-d');
    }

    /** @param array<string,mixed> $player @return array{element:array<string,mixed>,new:bool,day:string}|null */
    public static function claim(array $player): ?array
    {
        Game::ensureBase();
        $day = self::today();
        $pid = (string) $player['id'];
        return Db::transaction(function () use ($player, $pid, $day) {
            $row = Db::one('SELECT * FROM cc_checkins WHERE player_id = ? AND day = ?', [$pid, $day]);
            if ($row) {
                $el = Game::element((int) $row['element_id']);
                return $el ? ['element' => Game::view($el), 'new' => false, 'day' => $day] : null;
            }
            $have = [];
            foreach (Db::all('SELECT element_id FROM cc_checkins WHERE player_id = ?', [$pid]) as $r) {
                $have[(int) $r['element_id']] = true;
            }
            foreach (Db::all(
                'SELECT e.id FROM cc_elements e JOIN cc_collection c ON c.element_id = e.id WHERE c.player_id = ? AND e.source = ?',
                [$pid, 'checkin']
            ) as $r) {
                $have[(int) $r['id']] = true;
            }
            $next = null;
            foreach (array_keys(Seeds::CHECKIN) as $name) {
                $el = Db::one('SELECT * FROM cc_elements WHERE name_key = ?', [Text::key($name)]);
                if (!$el) {
                    continue;
                }
                $id = (int) $el['id'];
                if (!isset($have[$id])) {
                    $next = $el;
                    break;
                }
            }
            if ($next === null) {
                return null;
            }
            Game::grantElement($player, (int) $next['id']);
            $verb = Db::isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            Db::run(
                $verb . ' INTO cc_checkins (player_id, day, element_id, created_at) VALUES (?,?,?,?)',
                [$pid, $day, (int) $next['id'], Db::now()]
            );
            $saved = Db::one('SELECT * FROM cc_checkins WHERE player_id = ? AND day = ?', [$pid, $day]);
            if (!$saved) {
                return null;
            }
            $el = Game::element((int) $saved['element_id']) ?: $next;
            $fresh = (int) $saved['element_id'] === (int) $next['id'];
            return ['element' => Game::view($el), 'new' => $fresh, 'day' => $day];
        });
    }
}
