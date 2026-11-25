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
    public const COINS_FIRST = 10;

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function status(array $player): array
    {
        $link = Db::one('SELECT morse_user_id, morse_name, linked_at FROM cc_morse_links WHERE player_id = ?', [$player['id']]);
        $session = self::peekSession();
        $earned = (int) (Db::value('SELECT COALESCE(SUM(amount),0) FROM cc_coin_grants WHERE player_id = ?', [$player['id']]) ?? 0);
        return [
            'available' => $session['available'],
            'linked' => $link !== null,
            'morseName' => $link['morse_name'] ?? ($session['name'] ?? null),
            'signedIn' => $session['userId'] !== null,
            'canLink' => $link === null && $session['userId'] !== null,
            'earned' => $earned,
            'perFirst' => self::COINS_FIRST,
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
            throw new HttpError('That Morse Chess account is already linked to another ComboCraft player.', 409);
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
        return self::status($player);
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
        $link = Db::one('SELECT * FROM cc_morse_links WHERE player_id = ?', [$player['id']]);
        if ($link === null) {
            return self::status($player);
        }
        $eid = (int) $result['id'];
        $had = Db::value('SELECT 1 FROM cc_coin_grants WHERE morse_user_id = ? AND element_id = ?', [$link['morse_user_id'], $eid]);
        if ($had !== null) {
            return self::status($player) + ['justGranted' => 0];
        }
        try {
            $n = Db::run('UPDATE profiles SET coins = coins + ? WHERE user_id = ?', [self::COINS_FIRST, $link['morse_user_id']]);
            if ($n < 1) {
                error_log('[combocraft morse] profiles row missing for ' . $link['morse_user_id']);
                return self::status($player);
            }
            Game::insertPublic('cc_coin_grants', [
                'player_id' => $player['id'], 'morse_user_id' => $link['morse_user_id'],
                'element_id' => $eid, 'amount' => self::COINS_FIRST, 'created_at' => Db::now(),
            ]);
            $status = self::status($player);
            $status['justGranted'] = self::COINS_FIRST;
            return $status;
        } catch (Throwable $e) {
            error_log('[combocraft morse credit] ' . $e->getMessage());
            return self::status($player);
        }
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

    private static function tablesExist(): bool
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
