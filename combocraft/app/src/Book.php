<?php

declare(strict_types=1);

namespace ComboCraft;

/** Recipe book: how an element was made and what it has made. */
final class Book
{
    /**
     * @param array<string,mixed>|null $player
     * @return array<string,mixed>
     */
    public static function about(int $id, ?array $player): array
    {
        $e = Game::element($id);
        if ($e === null) {
            throw new HttpError('Not found', 404);
        }
        $view = Game::view($e, true);
        $madeFrom = $view['recipe'] ?? null;
        $yours = null;
        if ($player) {
            $row = Db::one('SELECT via_a, via_b FROM cc_collection WHERE player_id = ? AND element_id = ?', [$player['id'], $id]);
            if ($row && $row['via_a'] && $row['via_b']) {
                $a = Game::element((int) $row['via_a']);
                $b = Game::element((int) $row['via_b']);
                if ($a && $b) {
                    $yours = [Game::mini($a), Game::mini($b)];
                }
            }
        }
        $owned = [];
        if ($player) {
            foreach (Game::collection($player) as $el) {
                $owned[(int) $el['id']] = true;
            }
        }
        $makes = [];
        $rows = Db::all('SELECT a_id, b_id, result_id FROM cc_recipes WHERE a_id = ? OR b_id = ? ORDER BY created_at DESC LIMIT 40', [$id, $id]);
        foreach ($rows as $r) {
            $otherId = (int) $r['a_id'] === $id ? (int) $r['b_id'] : (int) $r['a_id'];
            $other = Game::element($otherId);
            $res = Game::element((int) $r['result_id']);
            if (!$other || !$res) {
                continue;
            }
            $has = isset($owned[(int) $res['id']]);
            $makes[] = [
                'with' => Game::mini($other),
                'result' => $has ? Game::view($res) : ['id' => (int) $res['id'], 'name' => '???', 'emoji' => '❓', 'hidden' => true],
                'owned' => $has,
            ];
        }
        $tree = null;
        try {
            $tree = Tree::of($id, $player);
        } catch (\Throwable $ex) {
            error_log('[combocraft tree] ' . $ex->getMessage());
        }
        return [
            'element' => $view,
            'madeFrom' => $madeFrom,
            'yours' => $yours,
            'makes' => $makes,
            'owned' => isset($owned[$id]) || (int) $e['is_base'] === 1,
            'tree' => $tree,
        ];
    }
}
