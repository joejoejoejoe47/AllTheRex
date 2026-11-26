<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/**
 * Optional Morse Chess account link. Reads the existing morse_session cookie
 * on alltherex.com (path /, SameSite=Lax) and credits coins on First
 * Discoveries through the same `UPDATE profiles SET coins = coins + ?` pattern
 * Morse Chess uses. Never writes passwords, never updates the session table,
 * never modifies Morse schema. New rows live only in cc_morse_links / cc_coin_grants.
 */
final class MorseLink
{
    public const COOKIE = 'morse_session';
    public const COINS_FIRST = 3;

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function status(array $player): array
    {
        $session = self::peekSession();
        $name = (string) ($session['name'] ?? '');
        if ($session['userId'] && strtolower($name) !== 'mastergus') {
            try {
                $player = self::attach($player);
            } catch (Throwable $e) {
                error_log('[combocraft morse attach] ' . $e->getMessage());
            }
        }
        $link = Db::one('SELECT morse_user_id, morse_name, linked_at FROM cc_morse_links WHERE player_id = ?', [$player['id']]);
        $uid = $link['morse_user_id'] ?? $session['userId'];
        $coins = 0;
        $legendary = false;
        if ($uid) {
            try {
                $coins = (int) (Db::value('SELECT coins FROM profiles WHERE user_id = ?', [$uid]) ?? 0);
            } catch (Throwable $e) {
            }
            $legendary = MorseAuth::isLegendary((string) $uid);
        }
        $earned = (int) (Db::value('SELECT COALESCE(SUM(amount),0) FROM cc_coin_grants WHERE player_id = ?', [$player['id']]) ?? 0);
        return [
            'available' => $session['available'],
            'linked' => $link !== null,
            'morseName' => $link['morse_name'] ?? ($session['name'] ?? null),
            'signedIn' => $session['userId'] !== null,
            'canLink' => $link === null && $session['userId'] !== null,
            'earned' => $earned,
            'perFirst' => self::COINS_FIRST,
            'coins' => $coins,
            'legendary' => $legendary,
        ];
    }

    /**
     * Quietly link this ComboCraft player to the Morse session cookie, if any.
     * Does not merge other players' progress.
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function attach(array $player): array
    {
        $session = self::peekSession();
        if ($session['userId'] === null) {
            return $player;
        }
        $uid = (string) $session['userId'];
        $name = (string) ($session['name'] ?? 'Morse player');
        $existing = Db::one('SELECT player_id FROM cc_morse_links WHERE morse_user_id = ?', [$uid]);
        if ($existing && $existing['player_id'] !== $player['id']) {
            return $player;
        }
        if (!Db::one('SELECT 1 FROM cc_morse_links WHERE player_id = ?', [$player['id']])) {
            try {
                Game::insertPublic('cc_morse_links', [
                    'player_id' => $player['id'],
                    'morse_user_id' => $uid,
                    'morse_name' => $name,
                    'linked_at' => Db::now(),
                ]);
                try {
                    self::payPending($player);
                } catch (Throwable $e) {
                }
            } catch (Throwable $e) {
                error_log('[combocraft morse attach] ' . $e->getMessage());
            }
        }
        return $player;
    }

    /**
     * ComboCraft player already tied to this Morse account, or an unlinked
     * player whose nickname matches the Morse username (so a long-time
     * crafter like MasterGus is restored instead of getting a new guest).
     */
    public static function playerIdForMorse(string $morseUserId, string $name = ''): ?string
    {
        if ($morseUserId !== '') {
            try {
                $existing = Db::one('SELECT player_id FROM cc_morse_links WHERE morse_user_id = ?', [$morseUserId]);
                $pid = isset($existing['player_id']) ? (string) $existing['player_id'] : '';
                if ($pid !== '') {
                    return $pid;
                }
            } catch (Throwable $e) {
            }
        }
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return null;
        }
        try {
            $row = Db::one(
                'SELECT p.id FROM cc_players p
                 LEFT JOIN cc_morse_links l ON l.player_id = p.id
                 WHERE p.nick_key = ? AND l.player_id IS NULL
                 ORDER BY p.firsts DESC, p.combos DESC, p.created_at ASC
                 LIMIT 1',
                [$key]
            );
            return $row ? (string) $row['id'] : null;
        } catch (Throwable $e) {
            error_log('[combocraft morse find] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Re-issue a cookie for the ComboCraft player already tied to this
     * Morse session. Used when cc_tok is missing or no longer matches.
     * Restores a linked account, or an unlinked player with the same
     * nickname; it does not create a new link here.
     * @return array<string,mixed>|null
     */
    public static function restoreFromSession(): ?array
    {
        if (!self::tablesExist()) {
            return null;
        }
        try {
            $session = self::peekSession();
            $uid = $session['userId'] ?? null;
            if (!is_string($uid) || $uid === '') {
                return null;
            }
            $pid = self::playerIdForMorse($uid, (string) ($session['name'] ?? ''));
            if ($pid === null || $pid === '') {
                return null;
            }
            return Player::adopt($pid);
        } catch (Throwable $e) {
            error_log('[combocraft morse restore] ' . $e->getMessage());
            return null;
        }
    }

    public static function tablesExist(): bool
    {
        return self::tablesExistInner();
    }

    public static function guestStatus(): array
    {
        return [
            'available' => self::tablesExist(),
            'linked' => false,
            'morseName' => null,
            'signedIn' => false,
            'canLink' => false,
            'earned' => 0,
            'perFirst' => self::COINS_FIRST,
            'coins' => 0,
            'legendary' => false,
        ];
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function link(array $player): array
    {
        $session = self::peekSession();
        if ($session['userId'] === null) {
            throw new HttpError('Sign in to Morse Chess on this site, then come back and tap Link. We never see your password.', 401);
        }
        $existing = Db::one('SELECT player_id FROM cc_morse_links WHERE morse_user_id = ?', [$session['userId']]);
        if ($existing && $existing['player_id'] !== $player['id']) {
            try {
                $player = Player::adopt((string) $existing['player_id']);
                return self::status($player);
            } catch (Throwable $e) {
                error_log('[combocraft morse link adopt] ' . $e->getMessage());
                throw new HttpError('That Morse Chess account is already linked to another ComboCraft player.', 409);
            }
        }
        if (Db::one('SELECT 1 FROM cc_morse_links WHERE player_id = ?', [$player['id']])) {
            return self::status($player);
        }
        Game::insertPublic('cc_morse_links', [
            'player_id' => $player['id'],
            'morse_user_id' => $session['userId'],
            'morse_name' => $session['name'] ?? 'Morse player',
            'linked_at' => Db::now(),
        ]);
        $paid = self::payPending($player);
        $status = self::status($player);
        if ($paid > 0) {
            $status['justGranted'] = $paid;
        }
        return $status;
    }

    /**
     * Credit coins for a First Discovery, once per Morse account + element.
     * @param array<string,mixed> $player
     * @param array<string,mixed> $result
     * @return array<string,mixed>|null
     */
    public static function onFirstDiscovery(array $player, array $result, bool $first): ?array
    {
        if (!$first) {
            return self::status($player);
        }
        $eid = (int) ($result['id'] ?? 0);
        if ($eid <= 0 || Firsts::isLegacy($eid) || Firsts::alreadyPaid($eid)) {
            return self::status($player);
        }
        $morse = self::resolveAccount($player);
        if ($morse === null) {
            return self::status($player);
        }
        $granted = self::grantFirstCoins($player, $morse, $eid);
        $status = self::status($player);
        if ($granted > 0) {
            $status['justGranted'] = $granted;
        }
        return $status;
    }

    /**
     * Pay any unpaid non-legacy firsts now that a Morse account is known.
     * Never pays grandfathered rows.
     * @param array<string,mixed> $player
     */
    public static function payPending(array $player): int
    {
        $morse = self::resolveAccount($player);
        if ($morse === null) {
            return 0;
        }
        $total = 0;
        foreach (Firsts::unpaidFor($player) as $eid) {
            $total += self::grantFirstCoins($player, $morse, $eid);
        }
        return $total;
    }

    /**
     * @param array<string,mixed> $player
     * @param array{morse_user_id:string,morse_name:string} $morse
     */
    private static function grantFirstCoins(array $player, array $morse, int $eid): int
    {
        if ($eid <= 0 || Firsts::isLegacy($eid) || Firsts::alreadyPaid($eid)) {
            return 0;
        }
        $uid = (string) $morse['morse_user_id'];
        $had = Db::value('SELECT 1 FROM cc_coin_grants WHERE morse_user_id = ? AND element_id = ?', [$uid, $eid]);
        if ($had !== null) {
            Firsts::markPaid($eid);
            return 0;
        }
        try {
            $n = Db::run('UPDATE profiles SET coins = coins + ? WHERE user_id = ?', [self::COINS_FIRST, $uid]);
            if ($n < 1) {
                error_log('[combocraft morse] profiles row missing for ' . $uid);
                return 0;
            }
            Game::insertPublic('cc_coin_grants', [
                'player_id' => $player['id'], 'morse_user_id' => $uid,
                'element_id' => $eid, 'amount' => self::COINS_FIRST, 'created_at' => Db::now(),
            ]);
            Firsts::markPaid($eid);
            return self::COINS_FIRST;
        } catch (Throwable $e) {
            error_log('[combocraft morse credit] ' . $e->getMessage());
            return 0;
        }
    }

    /** Coins for one Daily Challenge level: 2 at L1, +1 each level, cap 10. */
    public static function dailyAmount(int $level): int
    {
        return max(1, min(10, 1 + max(1, $level)));
    }

    /**
     * Credit Morse coins for every solved Daily level today that has not
     * already been paid for this ComboCraft player. Idempotent per
     * player / day / level. Returns the amount granted on this call.
     * @param array<string,mixed> $player
     * @param list<int> $levels
     * @return array{justCoins:int, byLevel:array<int,int>}
     */
    public static function grantDailyLevels(array $player, string $day, array $levels): array
    {
        $out = ['justCoins' => 0, 'byLevel' => []];
        $levels = array_values(array_unique(array_filter(array_map('intval', $levels), static fn ($n) => $n > 0)));
        if ($levels === [] || $day === '') {
            return $out;
        }
        $morse = self::resolveAccount($player);
        if ($morse === null) {
            return $out;
        }
        foreach ($levels as $level) {
            $n = self::grantDailyLevel($player, $day, $level, $morse);
            if ($n > 0) {
                $out['justCoins'] += $n;
                $out['byLevel'][$level] = $n;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $player
     * @param array{morse_user_id:string,morse_name:string} $morse
     */
    private static function grantDailyLevel(array $player, string $day, int $level, array $morse): int
    {
        $amount = self::dailyAmount($level);
        $pid = (string) $player['id'];
        $uid = (string) $morse['morse_user_id'];
        try {
            $had = Db::one(
                'SELECT 1 FROM cc_daily_coin_grants WHERE player_id = ? AND day = ? AND level = ?',
                [$pid, $day, $level]
            );
            if ($had) {
                return 0;
            }
            // Reserve the grant first so a retry cannot double-pay.
            Game::insertPublic('cc_daily_coin_grants', [
                'player_id' => $pid,
                'day' => $day,
                'level' => $level,
                'amount' => $amount,
                'morse_user_id' => $uid,
                'created_at' => Db::now(),
            ]);
        } catch (Throwable $e) {
            // Duplicate key = already paid (or a race we lost).
            return 0;
        }
        try {
            $n = Db::run('UPDATE profiles SET coins = coins + ? WHERE user_id = ?', [$amount, $uid]);
            if ($n < 1) {
                error_log('[combocraft daily coins] profiles row missing for ' . $uid);
                Db::run('DELETE FROM cc_daily_coin_grants WHERE player_id = ? AND day = ? AND level = ?', [$pid, $day, $level]);
                return 0;
            }
            return $amount;
        } catch (Throwable $e) {
            error_log('[combocraft daily coins] ' . $e->getMessage());
            try {
                Db::run('DELETE FROM cc_daily_coin_grants WHERE player_id = ? AND day = ? AND level = ?', [$pid, $day, $level]);
            } catch (Throwable $e2) {
            }
            return 0;
        }
    }

    /**
     * Linked Morse account, or auto-link the current Morse Chess session
     * when it is not already tied to another ComboCraft player.
     * @param array<string,mixed> $player
     * @return array{morse_user_id:string,morse_name:string}|null
     */
    private static function resolveAccount(array $player): ?array
    {
        $link = Db::one('SELECT morse_user_id, morse_name FROM cc_morse_links WHERE player_id = ?', [$player['id']]);
        if ($link) {
            return ['morse_user_id' => (string) $link['morse_user_id'], 'morse_name' => (string) ($link['morse_name'] ?: 'Morse player')];
        }
        $session = self::peekSession();
        if ($session['userId'] === null) {
            return null;
        }
        $taken = Db::one('SELECT player_id FROM cc_morse_links WHERE morse_user_id = ?', [$session['userId']]);
        if ($taken && $taken['player_id'] !== $player['id']) {
            return null;
        }
        try {
            if (!$taken) {
                Game::insertPublic('cc_morse_links', [
                    'player_id' => $player['id'],
                    'morse_user_id' => $session['userId'],
                    'morse_name' => $session['name'] ?? 'Morse player',
                    'linked_at' => Db::now(),
                ]);
            }
        } catch (Throwable $e) {
            error_log('[combocraft daily coins link] ' . $e->getMessage());
            // If a race linked this Morse user to us, we can still pay.
            $again = Db::one('SELECT morse_user_id, morse_name FROM cc_morse_links WHERE player_id = ?', [$player['id']]);
            if (!$again) {
                return null;
            }
            return ['morse_user_id' => (string) $again['morse_user_id'], 'morse_name' => (string) ($again['morse_name'] ?: 'Morse player')];
        }
        return ['morse_user_id' => (string) $session['userId'], 'morse_name' => (string) ($session['name'] ?? 'Morse player')];
    }

    /** @return array{available:bool,userId:?string,name:?string} */
    private static function peekSession(): array
    {
        $empty = ['available' => false, 'userId' => null, 'name' => null];
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($token) || $token === '') {
            // Cookie missing still means the feature is available on this host if the tables exist.
            return ['available' => self::tablesExist(), 'userId' => null, 'name' => null];
        }
        if (!self::tablesExist()) {
            return $empty;
        }
        try {
            $row = Db::one(
                'SELECT s.`expiresAt` AS expiresAt, u.`id` AS uid, u.`name` AS name, p.`username` AS username
                 FROM `session` s
                 JOIN `user` u ON u.`id` = s.`userId`
                 LEFT JOIN `profiles` p ON p.`user_id` = u.`id`
                 WHERE s.`token` = ? LIMIT 1',
                [hash('sha256', $token)]
            );
        } catch (Throwable $e) {
            error_log('[combocraft morse peek] ' . $e->getMessage());
            return $empty;
        }
        if ($row === null) {
            return ['available' => true, 'userId' => null, 'name' => null];
        }
        try {
            if (Db::toMs($row['expiresAt']) < Db::nowMs()) {
                return ['available' => true, 'userId' => null, 'name' => null];
            }
        } catch (Throwable $e) {
            // If timestamp helpers disagree, still allow a valid-looking row.
        }
        $name = (string) ($row['username'] ?: $row['name'] ?: 'Morse player');
        return ['available' => true, 'userId' => (string) $row['uid'], 'name' => $name];
    }

    private static function tablesExistInner(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            if (Db::isSqlite()) {
                $ok = false;
                return false;
            }
            $n = Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('session','user','profiles')");
            $ok = (int) $n === 3;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}
