<?php

declare(strict_types=1);

namespace SaintQuest;

/** Public comments on saints, with optional picture, report and hide. */
final class Comments
{
    public const AUTO_HIDE_REPORTS = 3;

    public static function add(string $authorId, string $saintId, string $body, ?string $imageId): array
    {
        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '');
        if ($body === '' && $imageId === null) {
            throw new HttpError('Write something or add a picture.', 400);
        }
        if (mb_strlen($body) > 500) {
            throw new HttpError('Keep comments under 500 characters.', 400);
        }
        $s = Db::one('SELECT owner_id, saint_key FROM sq_saints WHERE id = ?', [$saintId]);
        if ($s === null) {
            throw new HttpError('That saint was not found.', 404);
        }
        $id = Auth::randomId();
        Db::run(
            'INSERT INTO sq_comments (id, saint_id, author_id, body, image_id, hidden, reports, created_at) VALUES (?, ?, ?, ?, ?, 0, 0, ?)',
            [$id, $saintId, $authorId, $body, $imageId, Db::nowPrecise()]
        );
        if ($s['owner_id'] !== $authorId) {
            $who = (string) Db::value('SELECT username FROM sq_users WHERE id = ?', [$authorId]);
            $name = Saints::catalog()[$s['saint_key']]['name'] ?? 'your saint';
            Notify::send((string) $s['owner_id'], 'comment', $who . ' commented on ' . $name, mb_substr($body, 0, 120), 'saint', $saintId);
        }
        return ['commentId' => $id];
    }

    public static function forSaint(string $saintId, ?string $viewerId, bool $viewerIsOwner): array
    {
        $rows = Db::all(
            'SELECT c.id, c.body, c.image_id, c.hidden, c.reports, c.created_at, c.author_id, u.username
             FROM sq_comments c JOIN sq_users u ON u.id = c.author_id WHERE c.saint_id = ? ORDER BY c.created_at ASC, c.id ASC LIMIT 200',
            [$saintId]
        );
        $out = [];
        foreach ($rows as $r) {
            $hidden = (int) $r['hidden'] === 1;
            $isAuthor = $viewerId !== null && $viewerId === $r['author_id'];
            if ($hidden && !$viewerIsOwner && !$isAuthor) {
                $out[] = ['id' => (string) $r['id'], 'hidden' => true, 'author' => null, 'body' => null, 'image' => null, 'at' => $r['created_at']];
                continue;
            }
            $out[] = [
                'id' => (string) $r['id'], 'hidden' => $hidden, 'author' => (string) $r['username'], 'body' => (string) $r['body'],
                'image' => $r['image_id'] ? (string) $r['image_id'] : null, 'at' => $r['created_at'], 'mine' => $isAuthor,
                'canHide' => $viewerIsOwner || $isAuthor, 'canReport' => $viewerId !== null && !$isAuthor,
            ];
        }
        return $out;
    }

    public static function report(string $userId, string $commentId): array
    {
        $c = Db::one('SELECT author_id FROM sq_comments WHERE id = ?', [$commentId]);
        if ($c === null) {
            throw new HttpError('Comment not found.', 404);
        }
        if ($c['author_id'] === $userId) {
            throw new HttpError('You cannot report your own comment.', 400);
        }
        Auth::throttle('report:' . $userId, 30, 3600, true);
        $already = Db::value('SELECT 1 FROM sq_comment_reports WHERE comment_id = ? AND reporter_id = ?', [$commentId, $userId]);
        if ($already === null) {
            Db::run('INSERT INTO sq_comment_reports (comment_id, reporter_id, created_at) VALUES (?, ?, ?)', [$commentId, $userId, Db::now()]);
            Db::run('UPDATE sq_comments SET reports = reports + 1 WHERE id = ?', [$commentId]);
            Db::run('UPDATE sq_comments SET hidden = 1 WHERE id = ? AND reports >= ?', [$commentId, self::AUTO_HIDE_REPORTS]);
        }
        return ['hidden' => (int) Db::value('SELECT hidden FROM sq_comments WHERE id = ?', [$commentId]) === 1];
    }

    /** The saint's owner or the comment's author can hide (or unhide) it. */
    public static function setHidden(string $userId, string $commentId, bool $hidden): void
    {
        $c = Db::one('SELECT c.author_id, s.owner_id FROM sq_comments c JOIN sq_saints s ON s.id = c.saint_id WHERE c.id = ?', [$commentId]);
        if ($c === null) {
            throw new HttpError('Comment not found.', 404);
        }
        if ($userId !== $c['owner_id'] && $userId !== $c['author_id']) {
            throw new HttpError('Only the saint\'s owner or the writer can hide this.', 403);
        }
        if (!$hidden && $userId !== $c['owner_id']) {
            throw new HttpError('Only the saint\'s owner can show a hidden comment again.', 403);
        }
        Db::run('UPDATE sq_comments SET hidden = ? WHERE id = ?', [$hidden ? 1 : 0, $commentId]);
    }
}
