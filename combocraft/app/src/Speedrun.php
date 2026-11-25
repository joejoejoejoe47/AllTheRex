<?php

declare(strict_types=1);

namespace ComboCraft;

/** Reach a target from the base elements in as few combos as possible. */
final class Speedrun
{
    public const POOL = ['Steam', 'Ocean', 'Storm', 'Lava', 'Tsunami', 'Dragon', 'Cloud', 'Rain', 'Mud Pie', 'Firenado'];

    /** @return list<array<string,mixed>> */
    public static function targets(): array
    {
        $out = [];
        foreach (self::POOL as $name) {
            $e = Db::one('SELECT * FROM cc_elements WHERE name_key = ?', [Text::key($name)]);
            if ($e) {
                $out[] = Game::view($e);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function start(array $player, ?int $targetId): array
    {
        $targets = self::targets();
        if (!$targets) {
            throw new HttpError('No speedrun targets are ready yet.', 404);
        }
        $target = null;
        foreach ($targets as $t) {
            if ($targetId && (int) $t['id'] === $targetId) {
                $target = $t;
                break;
            }
        }
        if ($target === null) {
            $target = $targets[array_rand($targets)];
        }
        $bases = Db::all('SELECT id FROM cc_elements WHERE is_base = 1');
        $inv = array_map(static fn ($r) => (int) $r['id'], $bases);
        $id = 'sr' . bin2hex(random_bytes(8));
        $now = Db::now();
        // close any open run
        Db::run('UPDATE cc_speedrun_live SET done = 1 WHERE player_id = ? AND done = 0', [$player['id']]);
        Game::insertPublic('cc_speedrun_live', [
            'id' => $id, 'player_id' => $player['id'], 'target_id' => $target['id'],
            'started_at' => $now, 'combos' => 0, 'inv' => json_encode($inv), 'done' => 0,
        ]);
        return self::viewRun($id, $player);
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function viewRun(string $id, array $player): array
    {
        $run = Db::one('SELECT * FROM cc_speedrun_live WHERE id = ? AND player_id = ?', [$id, $player['id']]);
        if (!$run) {
            throw new HttpError('No such speedrun.', 404);
        }
        $target = Game::element((int) $run['target_id']);
        $inv = json_decode((string) $run['inv'], true) ?: [];
        $els = [];
        foreach ($inv as $eid) {
            $e = Game::element((int) $eid);
            if ($e) {
                $els[] = Game::view($e);
            }
        }
        $ms = max(0, (int) ((microtime(true) * 1000) - Db::toMs($run['started_at'])));
        return [
            'id' => $run['id'],
            'target' => $target ? Game::view($target) : null,
            'combos' => (int) $run['combos'],
            'ms' => $ms,
            'done' => (int) $run['done'] === 1,
            'elements' => $els,
        ];
    }

    /** Play one combo inside a run (also applies it to the main game). @param array<string,mixed> $player */
    public static function combine(array $player, string $runId, int $a, int $b): array
    {
        $run = Db::one('SELECT * FROM cc_speedrun_live WHERE id = ? AND player_id = ?', [$runId, $player['id']]);
        if (!$run || (int) $run['done'] === 1) {
            throw new HttpError('That speedrun is not running.', 422);
        }
        $inv = json_decode((string) $run['inv'], true) ?: [];
        $inv = array_map('intval', $inv);
        if (!in_array($a, $inv, true) || !in_array($b, $inv, true)) {
            throw new HttpError('You can only use elements from this speedrun.', 422);
        }
        $res = Game::combine($a, $b, true);
        if (!empty($res['pending'])) {
            return $res + ['run' => self::viewRun($runId, $player)];
        }
        return self::applyResult($player, $run, $inv, $res);
    }

    /**
     * @param array<string,mixed> $player
     * @param array<string,mixed> $run
     * @param list<int> $inv
     * @param array<string,mixed> $res
     * @return array<string,mixed>
     */
    public static function applyResult(array $player, array $run, array $inv, array $res): array
    {
        $rid = (int) ($res['result']['id'] ?? 0);
        if ($rid && !in_array($rid, $inv, true)) {
            $inv[] = $rid;
        }
        $combos = (int) $run['combos'] + 1;
        $done = $rid === (int) $run['target_id'];
        $ms = max(0, (int) ((microtime(true) * 1000) - Db::toMs($run['started_at'])));
        Db::run('UPDATE cc_speedrun_live SET combos = ?, inv = ?, done = ? WHERE id = ?', [$combos, json_encode($inv), $done ? 1 : 0, $run['id']]);
        $best = null;
        if ($done) {
            Game::insertPublic('cc_speedruns', [
                'player_id' => $player['id'], 'target_id' => $run['target_id'],
                'combos' => $combos, 'ms' => $ms, 'created_at' => Db::now(),
            ]);
            Goals::unlock($player, 'speedrun_finish');
            $mine = Db::one(
                'SELECT combos, ms FROM cc_speedruns WHERE player_id = ? AND target_id = ? ORDER BY combos ASC, ms ASC LIMIT 1',
                [$player['id'], $run['target_id']]
            );
            $best = $mine ? ['combos' => (int) $mine['combos'], 'ms' => (int) $mine['ms']] : ['combos' => $combos, 'ms' => $ms];
        }
        $res['run'] = self::viewRun((string) $run['id'], $player);
        $res['run']['justFinished'] = $done;
        $res['run']['ms'] = $ms;
        $res['run']['best'] = $best;
        return $res;
    }

    /** After a relay resolve during a speedrun. @param array<string,mixed> $player */
    public static function afterResolve(array $player, string $runId, array $res): array
    {
        $run = Db::one('SELECT * FROM cc_speedrun_live WHERE id = ? AND player_id = ?', [$runId, $player['id']]);
        if (!$run || (int) $run['done'] === 1) {
            return $res;
        }
        $inv = json_decode((string) $run['inv'], true) ?: [];
        $inv = array_map('intval', $inv);
        return self::applyResult($player, $run, $inv, $res);
    }

    /** @return array{rows:list<array<string,mixed>>,targets:list<array<string,mixed>>} */
    public static function board(?int $targetId): array
    {
        $targets = self::targets();
        if ($targetId === null && $targets) {
            $targetId = (int) $targets[0]['id'];
        }
        $rows = [];
        if ($targetId) {
            $sql = Db::isSqlite()
                ? 'SELECT player_id, MIN(combos) AS combos, MIN(ms) AS ms FROM cc_speedruns WHERE target_id = ? GROUP BY player_id ORDER BY combos ASC, ms ASC LIMIT 50'
                : 'SELECT s.player_id, s.combos, s.ms FROM cc_speedruns s INNER JOIN (
                     SELECT player_id, MIN(combos * 10000000 + ms) AS score FROM cc_speedruns WHERE target_id = ? GROUP BY player_id
                   ) b ON b.player_id = s.player_id AND (s.combos * 10000000 + s.ms) = b.score AND s.target_id = ? ORDER BY s.combos ASC, s.ms ASC LIMIT 50';
            $found = Db::isSqlite() ? Db::all($sql, [$targetId]) : Db::all($sql, [$targetId, $targetId]);
            $rank = 0;
            foreach ($found as $r) {
                $rank++;
                $p = Db::one('SELECT nickname, avatar FROM cc_players WHERE id = ?', [$r['player_id']]);
                $rows[] = [
                    'rank' => $rank,
                    'id' => $r['player_id'],
                    'nickname' => $p['nickname'] ?? 'Anonymous crafter',
                    'avatar' => $p['avatar'] ?? '🐱',
                    'combos' => (int) $r['combos'],
                    'ms' => (int) $r['ms'],
                ];
            }
        }
        return ['rows' => $rows, 'targets' => $targets, 'targetId' => $targetId];
    }
}
