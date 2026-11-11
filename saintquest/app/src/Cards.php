<?php

declare(strict_types=1);

namespace SaintQuest;

/**
 * Printable saint cards with an unguessable code (QR + printed short code).
 *
 * Code: "SQ-XXXX-XXXX-XXXX", 11 random Crockford base-32 characters (55 bits
 * from random_bytes) plus one check character, so typos are caught before
 * the database is asked and codes cannot be guessed. Only codes that were
 * printed here exist in sq_cards.
 *
 * Scanning:
 *  - new card            -> it and its saint join the scanner's account
 *  - your own card       -> nothing changes
 *  - someone else's card -> a claim; the owner is asked "Did you trade this
 *    card?". Yes moves card + saint. No asks the owner for a photo of the card
 *    and the claim stays disputed. While a claim is open the saint is frozen:
 *    neither player can list, sell or battle-trade it.
 */
final class Cards
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const PRINT_PER_DAY = 12;

    public static function newCode(): string
    {
        $body = '';
        $bytes = random_bytes(11);
        for ($i = 0; $i < 11; $i++) {
            $body .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        $raw = $body . self::checkChar($body);
        return 'SQ-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
    }

    public static function checkChar(string $body11): string
    {
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $v = strpos(self::ALPHABET, $body11[$i]);
            $sum += ($v === false ? 0 : $v) * ($i + 3);
        }
        return self::ALPHABET[$sum % 31];
    }

    /** Accepts a raw code, a QR payload or a card URL. Returns the canonical code or null. */
    public static function normalize(string $input): ?string
    {
        $s = strtoupper(trim($input));
        if (preg_match('/[?&]CARD=([A-Z0-9\-]+)/', $s, $m)) {
            $s = $m[1];
        }
        $s = preg_replace('/[^A-Z0-9]/', '', $s) ?? '';
        if (str_starts_with($s, 'SQ') && strlen($s) === 14) {
            $s = substr($s, 2);
        }
        $s = strtr($s, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
        if (strlen($s) !== 12 || strspn($s, self::ALPHABET) !== 12) {
            return null;
        }
        if (self::checkChar(substr($s, 0, 11)) !== $s[11]) {
            return null;
        }
        return 'SQ-' . substr($s, 0, 4) . '-' . substr($s, 4, 4) . '-' . substr($s, 8, 4);
    }

    /** Print new cards. $key is a saint key or 'random' (common to rare saints only). */
    public static function print(string $userId, string $key, int $count): array
    {
        $count = max(1, min(6, $count));
        $today = gmdate('Y-m-d') . ' 00:00:00';
        $printed = (int) Db::value('SELECT COUNT(*) FROM sq_cards WHERE printed_by = ? AND created_at >= ?', [$userId, $today]);
        if ($printed + $count > self::PRINT_PER_DAY) {
            throw new HttpError('You can print ' . self::PRINT_PER_DAY . ' cards a day. Come back tomorrow for more.', 429);
        }
        $cat = Saints::catalog();
        $pool = array_keys(array_filter($cat, fn ($s) => in_array($s['rarity'], ['common', 'uncommon', 'rare'], true)));
        if ($key !== 'random') {
            $def = Saints::def($key);
            if (!in_array($def['rarity'], ['common', 'uncommon', 'rare'], true)) {
                throw new HttpError('Epic and legendary saints cannot be printed. Win them in the store!', 400);
            }
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $k = $key === 'random' ? $pool[random_int(0, count($pool) - 1)] : $key;
            for ($try = 0; $try < 5; $try++) {
                $code = self::newCode();
                try {
                    Db::run(
                        "INSERT INTO sq_cards (id, code, saint_key, printed_by, owner_id, saint_id, status, created_at, claimed_at) VALUES (?, ?, ?, ?, NULL, NULL, 'new', ?, NULL)",
                        [Auth::randomId(), $code, $k, $userId, Db::now()]
                    );
                    $out[] = ['code' => $code, 'key' => $k, 'name' => $cat[$k]['name']];
                    break;
                } catch (\PDOException $e) {
                    if ($try === 4) {
                        throw $e;
                    }
                }
            }
        }
        return $out;
    }

    /** Cards this player printed that nobody has scanned yet (so they can print them again). */
    public static function printedUnclaimed(string $userId): array
    {
        $cat = Saints::catalog();
        return array_map(fn ($r) => ['code' => (string) $r['code'], 'key' => (string) $r['saint_key'], 'name' => $cat[$r['saint_key']]['name'] ?? ''],
            Db::all("SELECT code, saint_key FROM sq_cards WHERE printed_by = ? AND status = 'new' ORDER BY created_at DESC LIMIT 24", [$userId]));
    }

    public static function scan(string $userId, string $input): array
    {
        Auth::throttle('scan:' . $userId, 40, 3600, true);
        $code = self::normalize($input);
        $failBucket = 'scanfail:' . $userId;
        if ($code === null) {
            Auth::throttle($failBucket, 10, 900, true);
            throw new HttpError('That does not look like a Saint Quest card code. Check the letters and try again.', 400);
        }
        Auth::throttle($failBucket, 10, 900, false);
        if (Db::value('SELECT id FROM sq_cards WHERE code = ?', [$code]) === null) {
            Auth::throttle($failBucket, 10, 900, true);
            throw new HttpError('No Saint Quest card has that code.', 404);
        }
        return Db::transaction(function () use ($userId, $code): array {
            $card = Db::one('SELECT * FROM sq_cards WHERE code = ?' . (Db::isSqlite() ? '' : ' FOR UPDATE'), [$code]);
            $cat = Saints::catalog();
            $name = $cat[$card['saint_key']]['name'] ?? 'a saint';
            $now = Db::now();
            if ($card['status'] === 'new') {
                $sid = Saints::grant($userId, (string) $card['saint_key'], 'card', (string) $card['id']);
                if (Db::run("UPDATE sq_cards SET owner_id = ?, saint_id = ?, status = 'owned', claimed_at = ? WHERE id = ? AND status = 'new'", [$userId, $sid, $now, $card['id']]) !== 1) {
                    throw new HttpError('That card was just added by someone else. Try again.', 409);
                }
                return ['result' => 'added', 'saintId' => $sid, 'key' => $card['saint_key'], 'name' => $name, 'message' => $name . ' joined your saints!'];
            }
            if ($card['owner_id'] === $userId) {
                return ['result' => 'already-yours', 'saintId' => $card['saint_id'], 'key' => $card['saint_key'], 'name' => $name, 'message' => 'This card is already yours.'];
            }
            $open = Db::one("SELECT * FROM sq_card_claims WHERE card_id = ? AND status IN ('pending', 'awaiting_photo', 'disputed') LIMIT 1", [$card['id']]);
            if ($open !== null) {
                if ($open['claimant_id'] === $userId) {
                    return ['result' => 'claim-open', 'claimId' => $open['id'], 'status' => $open['status'], 'key' => $card['saint_key'], 'name' => $name,
                        'message' => 'You already asked about this card. ' . self::statusText((string) $open['status'], false)];
                }
                throw new HttpError('This card is frozen while another claim is checked.', 409);
            }
            // A player who already gave this card up (said yes/withdrew) cannot spam new claims for a day.
            $recent = Db::value(
                "SELECT COUNT(*) FROM sq_card_claims WHERE card_id = ? AND claimant_id = ? AND status IN ('withdrawn') AND updated_at >= ?",
                [$card['id'], $userId, gmdate('Y-m-d H:i:s', time() - 86400)]
            );
            if ((int) $recent > 0) {
                throw new HttpError('You withdrew a claim on this card today. Try again tomorrow.', 429);
            }
            $claimId = Auth::randomId();
            Db::run(
                "INSERT INTO sq_card_claims (id, card_id, claimant_id, owner_id, status, owner_photo_id, claimant_photo_id, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NULL, NULL, ?, ?)",
                [$claimId, $card['id'], $userId, $card['owner_id'], $now, $now]
            );
            Db::run("UPDATE sq_cards SET status = 'pending' WHERE id = ?", [$card['id']]);
            // Freeze the saint; an open listing is taken down so nobody can buy a disputed card.
            Db::run("UPDATE sq_listings SET status = 'cancelled', closed_at = ? WHERE saint_id = ? AND status = 'open'", [$now, $card['saint_id']]);
            Db::run("UPDATE sq_saints SET status = 'frozen', updated_at = ? WHERE id = ?", [$now, $card['saint_id']]);
            $who = (string) Db::value('SELECT username FROM sq_users WHERE id = ?', [$userId]);
            Notify::send((string) $card['owner_id'], 'claim', 'Did you trade your ' . $name . ' card?', $who . ' just scanned the card ' . $code . '. Tell us if you traded it.', 'claim', $claimId);
            return ['result' => 'claim-started', 'claimId' => $claimId, 'status' => 'pending', 'key' => $card['saint_key'], 'name' => $name,
                'message' => 'Someone else already added this card. We asked them if they traded it to you.'];
        });
    }

    public static function statusText(string $status, bool $forOwner): string
    {
        return match ($status) {
            'pending' => $forOwner ? 'Please answer: did you trade this card?' : 'Waiting for the other player to answer.',
            'awaiting_photo' => $forOwner ? 'You said you did not trade it. Please send a photo of the card.' : 'The other player says they still have it. Waiting for their card photo.',
            'disputed' => 'Disputed: both players say the card is theirs. The saint is frozen until one of you gives it up.',
            'accepted' => 'Traded: the card moved to its new owner.',
            'withdrawn' => 'The claim was withdrawn. The card stayed with its owner.',
            default => $status,
        };
    }

    /** Claims that involve this player and are still open, with what each side must do. */
    public static function openQuestions(string $userId): array
    {
        $rows = Db::all(
            "SELECT k.*, c.code, c.saint_key, uo.username AS owner_name, uc.username AS claimant_name
             FROM sq_card_claims k JOIN sq_cards c ON c.id = k.card_id
             JOIN sq_users uo ON uo.id = k.owner_id JOIN sq_users uc ON uc.id = k.claimant_id
             WHERE (k.owner_id = ? OR k.claimant_id = ?) AND k.status IN ('pending', 'awaiting_photo', 'disputed')
             ORDER BY k.created_at DESC LIMIT 20",
            [$userId, $userId]
        );
        $cat = Saints::catalog();
        return array_map(function ($r) use ($userId, $cat) {
            $owner = $r['owner_id'] === $userId;
            return [
                'id' => (string) $r['id'], 'role' => $owner ? 'owner' : 'claimant', 'status' => (string) $r['status'],
                'text' => self::statusText((string) $r['status'], $owner),
                'code' => $owner ? (string) $r['code'] : null,
                'key' => (string) $r['saint_key'], 'name' => $cat[$r['saint_key']]['name'] ?? '',
                'other' => (string) ($owner ? $r['claimant_name'] : $r['owner_name']),
                'ownerPhoto' => $r['owner_photo_id'] ? (string) $r['owner_photo_id'] : null,
                'claimantPhoto' => $r['claimant_photo_id'] ? (string) $r['claimant_photo_id'] : null,
                'at' => $r['created_at'],
            ];
        }, $rows);
    }

    private static function claimFor(string $claimId, string $userId, string $role): array
    {
        $k = Db::one('SELECT * FROM sq_card_claims WHERE id = ?' . (Db::isSqlite() ? '' : ' FOR UPDATE'), [$claimId]);
        if ($k === null || ($role === 'owner' ? $k['owner_id'] : $k['claimant_id']) !== $userId) {
            throw new HttpError('That card question is not for you.', 403);
        }
        return $k;
    }

    /** Owner answers "Did you trade this card?" */
    public static function respond(string $userId, string $claimId, bool $traded): array
    {
        return Db::transaction(function () use ($userId, $claimId, $traded): array {
            $k = self::claimFor($claimId, $userId, 'owner');
            if (!in_array($k['status'], ['pending', 'awaiting_photo', 'disputed'], true)) {
                throw new HttpError('This card question is already closed.', 409);
            }
            $card = Db::one('SELECT * FROM sq_cards WHERE id = ?', [$k['card_id']]);
            $name = Saints::catalog()[$card['saint_key']]['name'] ?? 'the saint';
            $now = Db::now();
            if ($traded) {
                Db::run("UPDATE sq_card_claims SET status = 'accepted', updated_at = ? WHERE id = ?", [$now, $claimId]);
                Db::run("UPDATE sq_cards SET owner_id = ?, status = 'owned', claimed_at = ? WHERE id = ?", [$k['claimant_id'], $now, $card['id']]);
                Db::run("UPDATE sq_saints SET owner_id = ?, status = 'ok', updated_at = ? WHERE id = ?", [$k['claimant_id'], $now, $card['saint_id']]);
                Notify::send((string) $k['claimant_id'], 'claim-ok', $name . ' is yours!', 'The previous owner confirmed the trade.', 'saint', (string) $card['saint_id']);
                return ['status' => 'accepted', 'text' => self::statusText('accepted', true)];
            }
            if ($k['status'] === 'pending') {
                Db::run("UPDATE sq_card_claims SET status = 'awaiting_photo', updated_at = ? WHERE id = ?", [$now, $claimId]);
                Notify::send((string) $k['claimant_id'], 'claim-no', 'The owner says they did not trade ' . $name . '.', 'They will send a photo of the card. The saint is frozen until this is sorted out.', 'claim', $claimId);
            }
            $status = $k['status'] === 'pending' ? 'awaiting_photo' : (string) $k['status'];
            return ['status' => $status, 'text' => self::statusText($status, true)];
        });
    }

    /** Either side can add a photo of the card. The owner's photo turns the claim into a dispute. */
    public static function addPhoto(string $userId, string $claimId, string $imageId): array
    {
        return Db::transaction(function () use ($userId, $claimId, $imageId): array {
            $k = Db::one('SELECT * FROM sq_card_claims WHERE id = ?', [$claimId]);
            if ($k === null || ($k['owner_id'] !== $userId && $k['claimant_id'] !== $userId)) {
                throw new HttpError('That card question is not for you.', 403);
            }
            if (!in_array($k['status'], ['pending', 'awaiting_photo', 'disputed'], true)) {
                throw new HttpError('This card question is already closed.', 409);
            }
            $now = Db::now();
            if ($k['owner_id'] === $userId) {
                if ($k['status'] === 'pending') {
                    throw new HttpError('First answer whether you traded the card.', 409);
                }
                Db::run("UPDATE sq_card_claims SET owner_photo_id = ?, status = 'disputed', updated_at = ? WHERE id = ?", [$imageId, $now, $claimId]);
                Db::run("UPDATE sq_cards SET status = 'disputed' WHERE id = ?", [$k['card_id']]);
                Notify::send((string) $k['claimant_id'], 'claim-photo', 'The owner sent a photo of the card.', 'This card is now disputed and frozen. If it is not really yours, please withdraw your claim.', 'claim', $claimId);
                return ['status' => 'disputed', 'text' => self::statusText('disputed', true)];
            }
            Db::run('UPDATE sq_card_claims SET claimant_photo_id = ?, updated_at = ? WHERE id = ?', [$imageId, $now, $claimId]);
            Notify::send((string) $k['owner_id'], 'claim-photo', 'The other player sent a photo of the card.', null, 'claim', $claimId);
            return ['status' => (string) $k['status'], 'text' => self::statusText((string) $k['status'], false)];
        });
    }

    /** The claimant gives up: the card stays with (and unfreezes for) its owner. */
    public static function withdraw(string $userId, string $claimId): array
    {
        return Db::transaction(function () use ($userId, $claimId): array {
            $k = self::claimFor($claimId, $userId, 'claimant');
            if (!in_array($k['status'], ['pending', 'awaiting_photo', 'disputed'], true)) {
                throw new HttpError('This card question is already closed.', 409);
            }
            $now = Db::now();
            Db::run("UPDATE sq_card_claims SET status = 'withdrawn', updated_at = ? WHERE id = ?", [$now, $claimId]);
            Db::run("UPDATE sq_cards SET status = 'owned' WHERE id = ?", [$k['card_id']]);
            $card = Db::one('SELECT saint_id, saint_key FROM sq_cards WHERE id = ?', [$k['card_id']]);
            Db::run("UPDATE sq_saints SET status = 'ok', updated_at = ? WHERE id = ? AND status = 'frozen'", [$now, $card['saint_id']]);
            Notify::send((string) $k['owner_id'], 'claim-withdrawn', 'Your card is safe!', 'The other player withdrew their claim.', 'saint', (string) $card['saint_id']);
            return ['status' => 'withdrawn', 'text' => self::statusText('withdrawn', false)];
        });
    }
}
