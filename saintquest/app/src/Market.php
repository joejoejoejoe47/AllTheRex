<?php

declare(strict_types=1);

namespace SaintQuest;

/** Public trade listings: list a saint for virtue points, buy with an atomic transfer. */
final class Market
{
    public const MIN_PRICE = 10;
    public const MAX_PRICE = 100000;

    public static function list(string $userId, string $saintId, int $price, string $benefit): array
    {
        $benefit = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $benefit) ?? '');
        if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
            throw new HttpError('Pick a price between ' . self::MIN_PRICE . ' and ' . self::MAX_PRICE . ' virtue points.', 400);
        }
        if (mb_strlen($benefit) < 3) {
            throw new HttpError('Tell everyone how this saint helped you (at least a few words).', 400);
        }
        if (mb_strlen($benefit) > 300) {
            throw new HttpError('Keep the story under 300 characters.', 400);
        }
        Auth::throttle('list:' . $userId, 20, 3600, true);
        return Db::transaction(function () use ($userId, $saintId, $price, $benefit): array {
            $n = Db::run("UPDATE sq_saints SET status = 'listed', updated_at = ? WHERE id = ? AND owner_id = ? AND status = 'ok'", [Db::now(), $saintId, $userId]);
            if ($n !== 1) {
                $s = Db::one('SELECT status, owner_id FROM sq_saints WHERE id = ?', [$saintId]);
                if ($s === null || $s['owner_id'] !== $userId) {
                    throw new HttpError('That saint is not yours.', 403);
                }
                throw new HttpError($s['status'] === 'frozen' ? 'This saint is frozen while its card is being checked.' : 'This saint is already listed.', 409);
            }
            $id = Auth::randomId();
            Db::run(
                "INSERT INTO sq_listings (id, saint_id, seller_id, price, benefit, status, buyer_id, created_at, closed_at) VALUES (?, ?, ?, ?, ?, 'open', NULL, ?, NULL)",
                [$id, $saintId, $userId, $price, $benefit, Db::now()]
            );
            return ['listingId' => $id];
        });
    }

    public static function cancel(string $userId, string $listingId): void
    {
        Db::transaction(function () use ($userId, $listingId): void {
            $l = Db::one("SELECT * FROM sq_listings WHERE id = ? AND seller_id = ? AND status = 'open'", [$listingId, $userId]);
            if ($l === null) {
                throw new HttpError('That listing is not open.', 404);
            }
            Db::run("UPDATE sq_listings SET status = 'cancelled', closed_at = ? WHERE id = ? AND status = 'open'", [Db::now(), $listingId]);
            Db::run("UPDATE sq_saints SET status = 'ok', updated_at = ? WHERE id = ? AND status = 'listed'", [Db::now(), $l['saint_id']]);
        });
    }

    /**
     * Atomic purchase. Every step is a conditional UPDATE checked by its row
     * count inside one transaction, so two buyers can never both win, a buyer
     * can never overspend, and the saint, card and points move together.
     */
    public static function buy(string $buyerId, string $listingId): array
    {
        Auth::throttle('buy:' . $buyerId, 30, 600, true);
        return Db::transaction(function () use ($buyerId, $listingId): array {
            $l = Db::one('SELECT * FROM sq_listings WHERE id = ?' . (Db::isSqlite() ? '' : ' FOR UPDATE'), [$listingId]);
            if ($l === null || $l['status'] !== 'open') {
                throw new HttpError('Sorry, that saint was already sold or taken off the market.', 409);
            }
            if ($l['seller_id'] === $buyerId) {
                throw new HttpError('You cannot buy your own saint.', 400);
            }
            $price = (int) $l['price'];
            $now = Db::now();
            if (Db::run("UPDATE sq_listings SET status = 'sold', buyer_id = ?, closed_at = ? WHERE id = ? AND status = 'open'", [$buyerId, $now, $listingId]) !== 1) {
                throw new HttpError('Sorry, that saint was already sold.', 409);
            }
            if (Db::run('UPDATE sq_users SET virtue = virtue - ?, updated_at = ? WHERE id = ? AND virtue >= ?', [$price, $now, $buyerId, $price]) !== 1) {
                throw new HttpError('Not enough virtue points for this saint.', 402);
            }
            if (Db::run("UPDATE sq_saints SET owner_id = ?, status = 'ok', updated_at = ? WHERE id = ? AND owner_id = ? AND status = 'listed'", [$buyerId, $now, $l['saint_id'], $l['seller_id']]) !== 1) {
                throw new HttpError('That saint can no longer be sold.', 409);
            }
            Db::run('UPDATE sq_users SET virtue = virtue + ?, updated_at = ? WHERE id = ?', [$price, $now, $l['seller_id']]);
            Db::run("UPDATE sq_cards SET owner_id = ? WHERE saint_id = ? AND status = 'owned'", [$buyerId, $l['saint_id']]);
            $s = Db::one('SELECT saint_key FROM sq_saints WHERE id = ?', [$l['saint_id']]);
            $name = Saints::catalog()[$s['saint_key']]['name'] ?? 'your saint';
            $buyer = (string) Db::value('SELECT username FROM sq_users WHERE id = ?', [$buyerId]);
            Notify::send((string) $l['seller_id'], 'sold', $buyer . ' bought ' . $name . ' for ' . $price . ' virtue points!', null, 'saint', (string) $l['saint_id']);
            return ['saintId' => (string) $l['saint_id'], 'price' => $price, 'name' => $name];
        });
    }

    /** Open listings, optionally filtered by saint name/patron/seller. */
    public static function open(string $q = '', int $limit = 40): array
    {
        $rows = Db::all(
            "SELECT l.id, l.price, l.benefit, l.created_at, l.saint_id, s.saint_key, s.rating, s.wins, s.card_id, u.username
             FROM sq_listings l JOIN sq_saints s ON s.id = l.saint_id JOIN sq_users u ON u.id = l.seller_id
             WHERE l.status = 'open' ORDER BY l.created_at DESC LIMIT 200"
        );
        $q = mb_strtolower(trim($q));
        $cat = Saints::catalog();
        $out = [];
        foreach ($rows as $r) {
            $def = $cat[$r['saint_key']] ?? ['name' => $r['saint_key'], 'patron' => ''];
            if ($q !== '' && !str_contains(mb_strtolower($def['name'] . ' ' . $def['patron'] . ' ' . $r['username'] . ' ' . $r['benefit']), $q)) {
                continue;
            }
            $out[] = [
                'id' => (string) $r['id'], 'saintId' => (string) $r['saint_id'], 'key' => (string) $r['saint_key'], 'name' => $def['name'],
                'price' => (int) $r['price'], 'benefit' => (string) $r['benefit'], 'seller' => (string) $r['username'],
                'rating' => (int) $r['rating'], 'wins' => (int) $r['wins'], 'fromCard' => $r['card_id'] !== null, 'at' => $r['created_at'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Public saint page: owner, open listing, comments. */
    public static function saint(string $saintId, ?string $viewerId): array
    {
        $s = Db::one('SELECT s.*, u.username, c.code AS card_code, c.status AS card_status FROM sq_saints s JOIN sq_users u ON u.id = s.owner_id LEFT JOIN sq_cards c ON c.id = s.card_id WHERE s.id = ?', [$saintId]);
        if ($s === null) {
            throw new HttpError('That saint was not found.', 404);
        }
        $l = Db::one("SELECT id, price, benefit, created_at FROM sq_listings WHERE saint_id = ? AND status = 'open' LIMIT 1", [$saintId]);
        $mine = $viewerId !== null && $viewerId === $s['owner_id'];
        $row = Saints::row($s);
        if (!$mine) {
            $row['card'] = null; // never reveal a card code to other players
        }
        return [
            'saint' => $row + ['owner' => (string) $s['username'], 'mine' => $mine, 'cardStatus' => $s['card_status'] ?? null],
            'listing' => $l ? ['id' => (string) $l['id'], 'price' => (int) $l['price'], 'benefit' => (string) $l['benefit'], 'at' => $l['created_at']] : null,
            'comments' => Comments::forSaint($saintId, $viewerId, $mine),
        ];
    }
}
