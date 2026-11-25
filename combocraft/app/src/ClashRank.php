<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Combo Clash ranks and XP. Additive cc_clash_ranks table only.
 * Tiers are generic sport ranks (no crowns, no third-party marks).
 */
final class ClashRank
{
    public const START_MMR = 600;
    public const XP_PLAY = 50;
    public const XP_WIN = 80;
    public const XP_LOSS = 40;
    public const XP_MVP = 25;
    public const XP_PER_LEVEL = 100;

    /** @var list<array{name:string,min:int,short:string}> */
    public const TIERS = [
        ['name' => 'Bronze', 'min' => 0, 'short' => 'B'],
        ['name' => 'Silver', 'min' => 700, 'short' => 'S'],
        ['name' => 'Gold', 'min' => 900, 'short' => 'G'],
        ['name' => 'Platinum', 'min' => 1100, 'short' => 'P'],
        ['name' => 'Diamond', 'min' => 1300, 'short' => 'D'],
        ['name' => 'Champion', 'min' => 1500, 'short' => 'C'],
        ['name' => 'Grand Champion', 'min' => 1700, 'short' => 'GC'],
    ];

    /** @return array<string,mixed> */
    public static function of(string $playerId): array
    {
        if ($playerId === '' || str_starts_with($playerId, 'pai_')) {
            return self::view(self::blank($playerId), $playerId);
        }
        $row = Db::one('SELECT * FROM cc_clash_ranks WHERE player_id = ?', [$playerId]);
        if (!$row) {
            return self::view(self::blank($playerId), $playerId);
        }
        return self::view($row, $playerId);
    }

    /** @param array<string,mixed> $m */
    public static function applyMatch(array $m, string $winnerId): void
    {
        $a = (string) $m['a_id'];
        $b = (string) $m['b_id'];
        $loserId = $a === $winnerId ? $b : $a;
        $ai = Clash::isAiId($a) || Clash::isAiId($b);
        $k = $ai ? 12 : 24;
        $mvp = self::mvpId($m['id']);

        $summary = [];
        foreach ([$a, $b] as $pid) {
            if (Clash::isAiId($pid)) {
                continue;
            }
            $won = $pid === $winnerId;
            $before = self::ensure($pid);
            $mmrDelta = $won ? $k : -$k;
            $mmr = max(0, (int) $before['mmr'] + $mmrDelta);
            $xpGain = self::XP_PLAY + ($won ? self::XP_WIN : self::XP_LOSS);
            if ($mvp === $pid) {
                $xpGain += self::XP_MVP;
            }
            $xp = (int) $before['xp'] + $xpGain;
            $level = 1 + intdiv($xp, self::XP_PER_LEVEL);
            $wins = (int) $before['wins'] + ($won ? 1 : 0);
            $losses = (int) $before['losses'] + ($won ? 0 : 1);
            Db::run(
                'UPDATE cc_clash_ranks SET mmr = ?, xp = ?, level = ?, wins = ?, losses = ?, updated_at = ? WHERE player_id = ?',
                [$mmr, $xp, $level, $wins, $losses, Db::now(), $pid]
            );
            $after = array_merge($before, compact('mmr', 'xp', 'level', 'wins', 'losses'));
            $summary[$pid] = [
                'before' => self::view($before, $pid),
                'after' => self::view($after, $pid),
                'mmrDelta' => $mmrDelta,
                'xpGained' => $xpGain,
                'levelUp' => $level > (int) $before['level'],
                'levelDown' => false,
                'mvp' => $mvp === $pid,
                'won' => $won,
            ];
        }
        try {
            Store::set('rk:' . $m['id'], json_encode($summary, JSON_UNESCAPED_UNICODE) ?: '{}');
        } catch (\Throwable $e) {
            error_log('[combocraft rank store] ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed>|null */
    public static function summaryFor(string $matchId, string $playerId): ?array
    {
        $raw = Store::get('rk:' . $matchId);
        if ($raw === null || $raw === '') {
            return null;
        }
        $all = json_decode($raw, true);
        if (!is_array($all) || !isset($all[$playerId])) {
            return null;
        }
        return $all[$playerId];
    }

    /** @return list<array<string,mixed>> */
    public static function matchStats(string $matchId): array
    {
        $rows = Db::all(
            "SELECT p.player_id,
                    COUNT(*) AS combos,
                    SUM(CASE WHEN p.breakdown LIKE '%\"chain\":true%' OR p.breakdown LIKE '%\"chain\": true%' THEN 1 ELSE 0 END) AS chains,
                    SUM(p.is_attack) AS attacks,
                    SUM(p.score) AS score,
                    MAX(p.score) AS best
             FROM cc_clash_plays p
             JOIN cc_clash_rounds r ON r.id = p.round_id
             WHERE r.match_id = ?
             GROUP BY p.player_id",
            [$matchId]
        );
        $out = [];
        $top = -1;
        $mvp = null;
        foreach ($rows as $r) {
            $score = (int) $r['score'];
            $item = [
                'playerId' => (string) $r['player_id'],
                'combos' => (int) $r['combos'],
                'chains' => (int) $r['chains'],
                'attacks' => (int) $r['attacks'],
                'score' => $score,
                'best' => (int) $r['best'],
                'mvp' => false,
            ];
            $out[] = $item;
            if ($score > $top) {
                $top = $score;
                $mvp = $item['playerId'];
            }
        }
        foreach ($out as &$item) {
            $mini = Db::one('SELECT nickname, avatar FROM cc_players WHERE id = ?', [$item['playerId']]);
            $item['name'] = $mini['nickname'] ?? 'Player';
            $item['avatar'] = Text::safeEmoji((string) (($mini['avatar'] ?? '') ?: '🐱'));
            $item['mvp'] = $mvp !== null && $item['playerId'] === $mvp;
            $item['rank'] = self::of($item['playerId']);
        }
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    public static function titleForLevel(int $level): string
    {
        return match (true) {
            $level >= 30 => 'Elite',
            $level >= 20 => 'All-Star',
            $level >= 15 => 'Ace',
            $level >= 10 => 'Veteran',
            $level >= 5 => 'Starter',
            default => 'Rookie',
        };
    }

    public static function tierName(int $mmr): string
    {
        $name = 'Bronze';
        foreach (self::TIERS as $t) {
            if ($mmr >= $t['min']) {
                $name = $t['name'];
            }
        }
        return $name;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function view(array $row, string $playerId): array
    {
        $mmr = (int) ($row['mmr'] ?? self::START_MMR);
        $xp = (int) ($row['xp'] ?? 0);
        $level = max(1, (int) ($row['level'] ?? (1 + intdiv($xp, self::XP_PER_LEVEL))));
        $into = $xp % self::XP_PER_LEVEL;
        $tier = self::tierName($mmr);
        $short = 'B';
        foreach (self::TIERS as $t) {
            if ($mmr >= $t['min']) {
                $short = $t['short'];
            }
        }
        return [
            'playerId' => $playerId,
            'mmr' => $mmr,
            'xp' => $xp,
            'level' => $level,
            'xpInto' => $into,
            'xpNext' => self::XP_PER_LEVEL,
            'wins' => (int) ($row['wins'] ?? 0),
            'losses' => (int) ($row['losses'] ?? 0),
            'tier' => $tier,
            'tierShort' => $short,
            'title' => self::titleForLevel($level),
        ];
    }

    /** @return array<string,mixed> */
    private static function ensure(string $playerId): array
    {
        $row = Db::one('SELECT * FROM cc_clash_ranks WHERE player_id = ?', [$playerId]);
        if ($row) {
            return $row;
        }
        $blank = self::blank($playerId);
        try {
            Db::run(
                'INSERT INTO cc_clash_ranks (player_id, mmr, xp, level, wins, losses, updated_at) VALUES (?,?,?,?,?,?,?)',
                [$playerId, $blank['mmr'], 0, 1, 0, 0, Db::now()]
            );
        } catch (\Throwable $e) {
            $row = Db::one('SELECT * FROM cc_clash_ranks WHERE player_id = ?', [$playerId]);
            if ($row) {
                return $row;
            }
        }
        return $blank;
    }

    /** @return array<string,mixed> */
    private static function blank(string $playerId): array
    {
        return [
            'player_id' => $playerId,
            'mmr' => self::START_MMR,
            'xp' => 0,
            'level' => 1,
            'wins' => 0,
            'losses' => 0,
        ];
    }

    private static function mvpId(string $matchId): ?string
    {
        $row = Db::one(
            'SELECT p.player_id, SUM(p.score) AS s FROM cc_clash_plays p JOIN cc_clash_rounds r ON r.id = p.round_id WHERE r.match_id = ? GROUP BY p.player_id ORDER BY s DESC, p.player_id ASC',
            [$matchId]
        );
        return $row ? (string) $row['player_id'] : null;
    }

    /**
     * Scoreboard rows for a party match: every human and bot on both teams.
     * @param array<string,mixed> $m  party match row (needs a_party, b_party, a_name, b_name)
     * @return list<array<string,mixed>>
     */
    public static function partyMatchStats(string $matchId, array $m): array
    {
        $plays = Db::all(
            'SELECT player_id, party_id, COUNT(*) AS combos, SUM(is_hit) AS hits
             FROM cc_clash_party_plays WHERE match_id = ? GROUP BY player_id, party_id',
            [$matchId]
        );
        $by = [];
        foreach ($plays as $r) {
            $by[(string) $r['player_id']] = $r;
        }
        $out = [];
        $top = -1;
        $mvp = null;
        foreach (['a', 'b'] as $side) {
            $pid = (string) ($m[$side . '_party'] ?? '');
            $team = (string) ($m[$side . '_name'] ?? 'Party');
            $members = $pid !== '' ? Db::all('SELECT player_id FROM cc_clash_party_members WHERE party_id = ? ORDER BY joined_at', [$pid]) : [];
            if (!$members && $pid !== '') {
                $members = [['player_id' => $pid]];
            }
            foreach ($members as $mem) {
                $id = (string) $mem['player_id'];
                $row = $by[$id] ?? ['combos' => 0, 'hits' => 0];
                $combos = (int) $row['combos'];
                $hits = (int) $row['hits'];
                $score = $hits * 100 + $combos * 10;
                $mini = Db::one('SELECT nickname, avatar FROM cc_players WHERE id = ?', [$id]);
                $name = $mini['nickname'] ?? (str_starts_with($id, 'pai_') ? 'Bot' : 'Player');
                $item = [
                    'playerId' => $id,
                    'name' => $name,
                    'avatar' => Text::safeEmoji((string) (($mini['avatar'] ?? '') ?: '🐱')),
                    'team' => $team,
                    'side' => $side,
                    'combos' => $combos,
                    'chains' => $hits,
                    'attacks' => 0,
                    'score' => $score,
                    'mvp' => false,
                    'rank' => self::of($id),
                ];
                $out[] = $item;
                if ($score > $top) {
                    $top = $score;
                    $mvp = $id;
                }
            }
        }
        foreach ($out as &$item) {
            $item['mvp'] = $mvp !== null && $item['playerId'] === $mvp && $top > 0;
        }
        unset($item);
        usort($out, static function ($a, $b) {
            if ($a['side'] !== $b['side']) {
                return $a['side'] <=> $b['side'];
            }
            return $b['score'] <=> $a['score'];
        });
        return $out;
    }

    /**
     * Award XP / MMR to every human in a finished party match. Winner party
     * may be null for a draw. Bots are skipped.
     * @param array<string,mixed> $m
     */
    public static function applyPartyMatch(array $m, ?string $winnerParty): void
    {
        $ai = false;
        foreach ([$m['a_party'] ?? '', $m['b_party'] ?? ''] as $pid) {
            foreach (Db::all('SELECT player_id FROM cc_clash_party_members WHERE party_id = ?', [$pid]) as $r) {
                if (Clash::isAiId((string) $r['player_id'])) {
                    $ai = true;
                    break 2;
                }
            }
        }
        $k = $ai ? 8 : 16;
        $stats = self::partyMatchStats((string) $m['id'], $m);
        $mvp = null;
        foreach ($stats as $s) {
            if (!empty($s['mvp'])) {
                $mvp = $s['playerId'];
                break;
            }
        }
        $draw = $winnerParty === null || $winnerParty === '';
        $summary = [];
        foreach ($stats as $s) {
            $pid = (string) $s['playerId'];
            if (Clash::isAiId($pid)) {
                continue;
            }
            $won = !$draw && $s['side'] && (($s['side'] === 'a' && ($m['a_party'] ?? '') === $winnerParty) || ($s['side'] === 'b' && ($m['b_party'] ?? '') === $winnerParty));
            $before = self::ensure($pid);
            $mmrDelta = $draw ? 0 : ($won ? $k : -$k);
            $mmr = max(0, (int) $before['mmr'] + $mmrDelta);
            $xpGain = self::XP_PLAY + ($draw ? 50 : ($won ? self::XP_WIN : self::XP_LOSS));
            if ($mvp === $pid) {
                $xpGain += self::XP_MVP;
            }
            $xp = (int) $before['xp'] + $xpGain;
            $level = 1 + intdiv($xp, self::XP_PER_LEVEL);
            $wins = (int) $before['wins'] + ($won ? 1 : 0);
            $losses = (int) $before['losses'] + (!$won && !$draw ? 1 : 0);
            Db::run(
                'UPDATE cc_clash_ranks SET mmr = ?, xp = ?, level = ?, wins = ?, losses = ?, updated_at = ? WHERE player_id = ?',
                [$mmr, $xp, $level, $wins, $losses, Db::now(), $pid]
            );
            $after = array_merge($before, compact('mmr', 'xp', 'level', 'wins', 'losses'));
            $summary[$pid] = [
                'before' => self::view($before, $pid),
                'after' => self::view($after, $pid),
                'mmrDelta' => $mmrDelta,
                'xpGained' => $xpGain,
                'levelUp' => $level > (int) $before['level'],
                'levelDown' => false,
                'mvp' => $mvp === $pid,
                'won' => $won,
                'draw' => $draw,
            ];
        }
        try {
            Store::set('rk:' . $m['id'], json_encode($summary, JSON_UNESCAPED_UNICODE) ?: '{}');
        } catch (\Throwable $e) {
            error_log('[combocraft party rank store] ' . $e->getMessage());
        }
    }

}
