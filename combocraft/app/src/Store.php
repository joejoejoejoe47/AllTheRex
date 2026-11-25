<?php

declare(strict_types=1);

namespace ComboCraft;

/** Tiny key/value table (cooldowns, secrets). */
final class Store
{
    public static function get(string $k): ?string
    {
        $v = Db::value('SELECT v FROM cc_kv WHERE k = ?', [$k]);
        return $v === null ? null : (string) $v;
    }

    public static function set(string $k, string $v): void
    {
        Db::upsert('cc_kv', ['k' => $k, 'v' => $v, 'updated_at' => Db::now()], ['k'], ['v', 'updated_at']);
    }

    public static function secret(): string
    {
        $s = self::get('ticket_secret');
        if ($s === null || strlen($s) < 32) {
            $s = bin2hex(random_bytes(32));
            self::set('ticket_secret', $s);
            $s = self::get('ticket_secret') ?? $s;
        }
        return $s;
    }
}
