<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/** Likes, comments, views, and posts for Discoveries / share cards. Additive cc_* only. */
final class Discover
{
    /**
     * @param array<string,mixed>|null $player
     * @return array<string,mixed>
     */
    public static function about(int $id, ?array $player, bool $countView = false): array
    {
        $e = Game::element($id);
        if ($e === null || (int) $e['is_base'] === 1) {
            throw new HttpError('Not found', 404);
        }
        if ($countView && $player) {
            self::view($id, $player);
        }
        $el = Game::view($e, true);
        $counts = self::counts([$id])[$id] ?? self::emptyCounts();
        $liked = false;
        $posted = $counts['posted'];
        $canPost = false;
        $comments = self::comments($id);
        if ($player) {
            $liked = (bool) Db::value('SELECT 1 FROM cc_discovery_likes WHERE element_id = ? AND player_id = ?', [$id, $player['id']]);
            $owned = (bool) Db::value('SELECT 1 FROM cc_collection WHERE player_id = ? AND element_id = ?', [$player['id'], $id]);
            $canPost = $owned && !$posted;
        }
        return [
            'element' => $el,
            'views' => $counts['views'],
            'likes' => $counts['likes'],
            'comments' => $counts['comments'],
            'commentList' => $comments,
            'liked' => $liked,
            'posted' => $posted,
            'canPost' => $canPost,
        ];
    }

    /**
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function view(int $id, array $player): array
    {
        self::mustExist($id);
        try {
            Game::insertPublic('cc_discovery_views', [
                'element_id' => $id,
                'player_id' => $player['id'],
                'created_at' => Db::now(),
            ]);
        } catch (Throwable $e) {
        }
        return self::about($id, $player, false);
    }

    /**
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function like(int $id, array $player): array
    {
        self::mustExist($id);
        $row = Db::one('SELECT 1 FROM cc_discovery_likes WHERE element_id = ? AND player_id = ?', [$id, $player['id']]);
        if ($row) {
            Db::run('DELETE FROM cc_discovery_likes WHERE element_id = ? AND player_id = ?', [$id, $player['id']]);
        } else {
            Game::insertPublic('cc_discovery_likes', [
                'element_id' => $id,
                'player_id' => $player['id'],
                'created_at' => Db::now(),
            ]);
        }
        return self::about($id, $player, false);
    }

    /**
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function comment(int $id, array $player, string $body): array
    {
        self::mustExist($id);
        $body = trim($body);
        if (mb_strlen($body) < 1) {
            throw new HttpError('Write a comment first.', 400);
        }
        if (mb_strlen($body) > 240) {
            throw new HttpError('Comments can be 240 characters.', 400);
        }
        if (empty($player['nickname'])) {
            throw new HttpError('Pick a nickname to comment.', 401);
        }
        $recent = (int) (Db::value(
            'SELECT COUNT(*) FROM cc_discovery_comments WHERE player_id = ? AND created_at >= ?',
            [$player['id'], date('Y-m-d H:i:s', time() - 30)]
        ) ?? 0);
        if ($recent >= 3) {
            throw new HttpError('Easy — wait a moment before commenting again.', 429);
        }
        Game::insertPublic('cc_discovery_comments', [
            'element_id' => $id,
            'player_id' => $player['id'],
            'body' => $body,
            'created_at' => Db::now(),
        ]);
        return self::about($id, $player, false);
    }

    /**
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function post(int $id, array $player): array
    {
        self::mustExist($id);
        $owned = Db::one('SELECT 1 FROM cc_collection WHERE player_id = ? AND element_id = ?', [$player['id'], $id]);
        if (!$owned) {
            throw new HttpError('Discover it first, then you can post it.', 403);
        }
        if (Db::one('SELECT 1 FROM cc_discovery_posts WHERE element_id = ?', [$id])) {
            return self::about($id, $player, false);
        }
        Game::insertPublic('cc_discovery_posts', [
            'element_id' => $id,
            'player_id' => $player['id'],
            'created_at' => Db::now(),
        ]);
        return self::about($id, $player, false);
    }

    /**
     * Attach like/comment/view/posted counts onto gallery cards.
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function decorate(array $items): array
    {
        $ids = [];
        foreach ($items as $it) {
            if (!empty($it['id'])) {
                $ids[] = (int) $it['id'];
            }
        }
        $counts = self::counts($ids);
        foreach ($items as &$it) {
            $c = $counts[(int) ($it['id'] ?? 0)] ?? self::emptyCounts();
            $it['views'] = $c['views'];
            $it['likes'] = $c['likes'];
            $it['comments'] = $c['comments'];
            $it['posted'] = $c['posted'];
        }
        unset($it);
        return $items;
    }

    /** @return list<array<string,mixed>> */
    public static function posted(): array
    {
        $rows = Db::all(
            'SELECT e.* FROM cc_discovery_posts p JOIN cc_elements e ON e.id = p.element_id
             ORDER BY p.created_at DESC LIMIT 60'
        );
        $out = [];
        foreach ($rows as $r) {
            $v = Game::view($r, true);
            $v['label'] = $v['label'] ?: 'POSTED';
            $out[] = $v;
        }
        return self::decorate($out);
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{views:int,likes:int,comments:int,posted:bool}>
     */
    private static function counts(array $ids): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($n) => $n > 0)));
        foreach ($ids as $id) {
            $out[$id] = self::emptyCounts();
        }
        if ($ids === []) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $fill = static function (string $sql, string $key) use ($ids, $ph, &$out): void {
            try {
                foreach (Db::all($sql, $ids) as $r) {
                    $id = (int) $r['element_id'];
                    if (isset($out[$id])) {
                        $out[$id][$key] = $key === 'posted' ? true : (int) $r['c'];
                    }
                }
            } catch (Throwable $e) {
            }
        };
        $fill("SELECT element_id, COUNT(*) c FROM cc_discovery_views WHERE element_id IN ($ph) GROUP BY element_id", 'views');
        $fill("SELECT element_id, COUNT(*) c FROM cc_discovery_likes WHERE element_id IN ($ph) GROUP BY element_id", 'likes');
        $fill("SELECT element_id, COUNT(*) c FROM cc_discovery_comments WHERE element_id IN ($ph) GROUP BY element_id", 'comments');
        $fill("SELECT element_id, 1 c FROM cc_discovery_posts WHERE element_id IN ($ph)", 'posted');
        return $out;
    }

    /** @return array{views:int,likes:int,comments:int,posted:bool} */
    private static function emptyCounts(): array
    {
        return ['views' => 0, 'likes' => 0, 'comments' => 0, 'posted' => false];
    }

    /** @return list<array<string,mixed>> */
    private static function comments(int $id): array
    {
        try {
            $rows = Db::all(
                'SELECT c.body, c.created_at, p.nickname, p.avatar
                 FROM cc_discovery_comments c
                 JOIN cc_players p ON p.id = c.player_id
                 WHERE c.element_id = ?
                 ORDER BY c.id DESC LIMIT 40',
                [$id]
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $nick = (string) ($r['nickname'] ?? 'Anonymous crafter');
            if (TestNicks::isHidden($nick)) {
                continue;
            }
            $out[] = [
                'body' => (string) $r['body'],
                'nickname' => $nick,
                'avatar' => (string) ($r['avatar'] ?? ''),
                'at' => $r['created_at'],
            ];
        }
        return $out;
    }

    private static function mustExist(int $id): void
    {
        $e = Game::element($id);
        if ($e === null || (int) $e['is_base'] === 1) {
            throw new HttpError('Not found', 404);
        }
    }
}
