<?php

declare(strict_types=1);

namespace ComboCraft;

/** Achievements, toasts and the world-unlocked progress bar. */
final class Goals
{
    /** @var array<string,array{title:string,detail:string,icon:string}> */
    public const BADGES = [
        'discover_10' => ['title' => 'Apprentice Crafter', 'detail' => 'Discover 10 elements', 'icon' => '🌱'],
        'discover_50' => ['title' => 'Seasoned Inventor', 'detail' => 'Discover 50 elements', 'icon' => '🧪'],
        'discover_100' => ['title' => 'World Builder', 'detail' => 'Discover 100 elements', 'icon' => '🌌'],
        'first_first' => ['title' => 'First of Firsts', 'detail' => 'Land a First Discovery', 'icon' => '⭐'],
        'something_alive' => ['title' => 'It\'s Alive!', 'detail' => 'Make something alive', 'icon' => '🐾'],
        'daily_win' => ['title' => 'Daily Champion', 'detail' => 'Solve a Daily Challenge', 'icon' => '🎯'],
        'daily_streak_7' => ['title' => 'Week of Fire', 'detail' => 'A 7-day daily streak', 'icon' => '🔥'],
        'legendary_find' => ['title' => 'Legendary Touch', 'detail' => 'Craft a Legendary element', 'icon' => '💎'],
        'speedrun_finish' => ['title' => 'Speed Crafter', 'detail' => 'Finish a speedrun', 'icon' => '⚡'],
        'hint_taker' => ['title' => 'A Little Help', 'detail' => 'Use a hint', 'icon' => '💡'],
        'chatty' => ['title' => 'Town Crier', 'detail' => 'Post in chat', 'icon' => '💬'],
        'world_half' => ['title' => 'Halfway There', 'detail' => 'Unlock half the known world', 'icon' => '🌍'],
    ];

    private const ALIVE = [
        'cat', 'dog', 'puppy', 'kitten', 'dragon', 'fish', 'shark', 'bird', 'plant', 'tree', 'flower',
        'animal', 'creature', 'beast', 'monster', 'human', 'person', 'baby', 'life', 'alive', 'organism',
        'frog', 'bee', 'fox', 'wolf', 'bear', 'whale', 'dolphin', 'horse', 'mouse', 'rat', 'snake',
        'lizard', 'dinosaur', 'phoenix', 'unicorn', 'fairy', 'angel', 'demon', 'ghost', 'zombie',
        'robot', 'ai', 'boyfriend', 'politician', 'mayor', 'feline', 'meow', 'purr', 'kitty', 'puppy',
        'nature', 'forest', 'garden', 'seed', 'vine', 'coral', 'octopus',
    ];

    private const ALIVE_EMOJI = '🐱🐶🐉🐟🦈🐦🌱🌳🌸🐸🐝🦊🐺🐻🐋🐬🐴🐭🐍🦎🦖🦄👼🤖🐾🐈😺😸😻😾😼🐈‍⬛🥀🌺🌻🌼🍀🪴';

    /** @param array<string,mixed> $player @return list<array<string,mixed>> */
    public static function all(array $player): array
    {
        $have = [];
        foreach (Db::all('SELECT badge, unlocked_at FROM cc_achievements WHERE player_id = ?', [$player['id']]) as $r) {
            $have[$r['badge']] = $r['unlocked_at'];
        }
        $out = [];
        foreach (self::BADGES as $id => $meta) {
            $out[] = $meta + ['id' => $id, 'unlocked' => isset($have[$id]), 'at' => isset($have[$id]) ? Db::toMs($have[$id]) : null];
        }
        return $out;
    }

    /** @param array<string,mixed> $player @return array{unlocked:int,world:int,pct:int} */
    public static function progress(array $player): array
    {
        $world = (int) (Db::value('SELECT COUNT(*) FROM cc_elements') ?? 0);
        $mine = (int) (Db::value(
            'SELECT COUNT(*) FROM cc_elements e WHERE e.is_base = 1 OR EXISTS (SELECT 1 FROM cc_collection c WHERE c.element_id = e.id AND c.player_id = ?)',
            [$player['id']]
        ) ?? 0);
        $pct = $world > 0 ? (int) floor(100 * $mine / $world) : 0;
        return ['unlocked' => $mine, 'world' => $world, 'pct' => $pct];
    }

    /**
     * @param array<string,mixed> $player
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>> newly unlocked badges
     */
    public static function onCombine(array $player, array $result, bool $first, bool $newForYou): array
    {
        $new = [];
        $discoveries = (int) (Db::value('SELECT COUNT(*) FROM cc_collection WHERE player_id = ?', [$player['id']]) ?? 0);
        if ($discoveries >= 10) {
            $new = array_merge($new, self::unlock($player, 'discover_10'));
        }
        if ($discoveries >= 50) {
            $new = array_merge($new, self::unlock($player, 'discover_50'));
        }
        if ($discoveries >= 100) {
            $new = array_merge($new, self::unlock($player, 'discover_100'));
        }
        if ($first) {
            $new = array_merge($new, self::unlock($player, 'first_first'));
        }
        if ($newForYou && self::isAlive($result)) {
            $new = array_merge($new, self::unlock($player, 'something_alive'));
        }
        if (($result['rarity'] ?? '') === 'Legendary' && $newForYou) {
            $new = array_merge($new, self::unlock($player, 'legendary_find'));
        }
        $daily = Daily::status($player);
        if (!empty($daily['solved'])) {
            $new = array_merge($new, self::unlock($player, 'daily_win'));
        }
        if ((int) ($daily['streak'] ?? 0) >= 7) {
            $new = array_merge($new, self::unlock($player, 'daily_streak_7'));
        }
        $prog = self::progress($player);
        if ($prog['pct'] >= 50) {
            $new = array_merge($new, self::unlock($player, 'world_half'));
        }
        return $new;
    }

    /** @param array<string,mixed> $player @return list<array<string,mixed>> */
    public static function unlock(array $player, string $badge): array
    {
        if (!isset(self::BADGES[$badge])) {
            return [];
        }
        $had = Db::value('SELECT 1 FROM cc_achievements WHERE player_id = ? AND badge = ?', [$player['id'], $badge]);
        if ($had !== null) {
            return [];
        }
        Game::insertPublic('cc_achievements', [
            'player_id' => $player['id'], 'badge' => $badge, 'unlocked_at' => Db::now(),
        ]);
        $meta = self::BADGES[$badge];
        return [['id' => $badge] + $meta + ['unlocked' => true]];
    }

    /** @param array<string,mixed> $el */
    public static function isAlive(array $el): bool
    {
        $name = Text::key((string) ($el['name'] ?? ''));
        foreach (self::ALIVE as $w) {
            if (str_contains($name, $w)) {
                return true;
            }
        }
        $emoji = (string) ($el['emoji'] ?? '');
        if ($emoji === '') {
            return false;
        }
        return str_contains(self::ALIVE_EMOJI, $emoji) || preg_match('/\p{So}/u', $emoji) && (
            str_contains($emoji, '🐱') || str_contains($emoji, '🐉') || str_contains($emoji, '🌱') ||
            str_contains($emoji, '🐶') || str_contains($emoji, '🐾') || str_contains($emoji, '🌸')
        );
    }
}
