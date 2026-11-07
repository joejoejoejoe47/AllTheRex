<?php

declare(strict_types=1);

namespace WorldDomination;

/**
 * Runtime configuration from real environment variables, then app/.env.
 */
final class Config
{
    /** @var array<string,string>|null */
    private static ?array $file = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && trim($env) !== '') {
            return trim($env);
        }
        if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
            return trim((string) $_ENV[$key]);
        }
        self::$file ??= self::loadFile(self::root() . '/.env');
        $value = self::$file[$key] ?? null;
        return ($value !== null && trim($value) !== '') ? trim($value) : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function storagePath(string $suffix = ''): string
    {
        $dir = self::root() . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $suffix === '' ? $dir : $dir . '/' . ltrim($suffix, '/');
    }

    public static function dbConfigured(): bool
    {
        $driver = strtolower(self::get('DB_DRIVER', '') ?? '');
        if ($driver === 'sqlite') {
            return true;
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            return (self::get('DB_NAME') !== null && self::get('DB_USER') !== null);
        }
        // Implicit mysql if a name was provided, otherwise not configured.
        return self::get('DB_NAME') !== null && self::get('DB_USER') !== null;
    }

    /** @return array<string,string> */
    private static function loadFile(string $path): array
    {
        $out = [];
        if (!is_file($path) || !is_readable($path)) {
            return $out;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                $val = substr($val, 1, -1);
            }
            $out[$key] = $val;
        }
        return $out;
    }
}
