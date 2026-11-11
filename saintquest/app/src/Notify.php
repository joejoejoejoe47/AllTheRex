<?php

declare(strict_types=1);

namespace SaintQuest;

/** In-app notifications shown under the bell the next time a player checks. */
final class Notify
{
    public static function send(string $userId, string $kind, string $title, ?string $body = null, ?string $refType = null, ?string $refId = null): void
    {
        Db::run(
            'INSERT INTO sq_notifications (id, user_id, kind, title, body, ref_type, ref_id, seen, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
            [Auth::randomId(), $userId, $kind, mb_substr($title, 0, 160), $body, $refType, $refId, Db::nowPrecise()]
        );
    }

    public static function unseen(string $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM sq_notifications WHERE user_id = ? AND seen = 0', [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function list(string $userId): array
    {
        $rows = Db::all(
            'SELECT id, kind, title, body, ref_type, ref_id, seen, created_at FROM sq_notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 40',
            [$userId]
        );
        return array_map(fn ($r) => [
            'id' => $r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'body' => $r['body'],
            'refType' => $r['ref_type'], 'refId' => $r['ref_id'], 'seen' => (int) $r['seen'] === 1, 'at' => $r['created_at'],
        ], $rows);
    }

    public static function markSeen(string $userId): void
    {
        Db::run('UPDATE sq_notifications SET seen = 1 WHERE user_id = ? AND seen = 0', [$userId]);
    }
}
