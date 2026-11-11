<?php

declare(strict_types=1);

namespace SaintQuest;

/**
 * Runtime configuration.
 *
 * Precedence: real environment variables (DB_* or SQ_DB_*), then this app's
 * guarded app/.env, then optional SQ_CONFIG (DB_* keys only), then the
 * sibling Morse Chess app/.env DB_* keys only. Other keys from Morse
 * (API tokens, mail, etc.) are never loaded.
 */
final class Config
{
    private const DB_KEYS = ['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SOCKET', 'DB_SQLITE'];

    /** @var array<string,string>|null */
    private static ?array $file = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function reset(): void
    {
        self::$file = null;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        foreach (self::candidates($key) as $candidate) {
            $env = getenv($candidate);
            if ($env !== false && trim($env) !== '') {
                return trim($env);
            }
            if (isset($_ENV[$candidate]) && trim((string) $_ENV[$candidate]) !== '') {
                return trim((string) $_ENV[$candidate]);
            }
        }
        self::hydrate();
        foreach (self::candidates($key) as $candidate) {
            $value = self::$file[$candidate] ?? null;
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }
        return $default;
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
        $override = getenv('SQ_STORAGE');
        $dir = is_string($override) && $override !== '' ? rtrim($override, '/') : self::root() . '/storage';
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
        return self::get('DB_NAME') !== null && self::get('DB_USER') !== null;
    }

    /** @return list<string> */
    private static function candidates(string $key): array
    {
        $out = [$key];
        if (str_starts_with($key, 'DB_')) {
            $out[] = 'SQ_' . $key;
        }
        return $out;
    }

    private static function hydrate(): void
    {
        if (self::$file !== null) {
            return;
        }
        self::$file = self::loadFile(self::root() . '/.env');
        if (self::arrayHasDb(self::$file) || self::envHasDb()) {
            return;
        }
        $wdConfig = getenv('SQ_CONFIG');
        if (is_string($wdConfig) && $wdConfig !== '' && is_file($wdConfig)) {
            self::$file = array_merge(self::$file, self::dbKeysOnly(self::loadFile($wdConfig)));
            if (self::arrayHasDb(self::$file)) {
                return;
            }
        }
        $sibling = self::allowedSiblingMorseEnv();
        if ($sibling !== null) {
            self::$file = array_merge(self::$file, self::dbKeysOnly(self::loadFile($sibling)));
        }
    }

    private static function envHasDb(): bool
    {
        $driver = getenv('DB_DRIVER') ?: getenv('SQ_DB_DRIVER') ?: '';
        if (strtolower(trim((string) $driver)) === 'sqlite') {
            return true;
        }
        $name = getenv('DB_NAME') ?: getenv('SQ_DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: getenv('SQ_DB_USER') ?: '';
        return trim((string) $name) !== '' && trim((string) $user) !== '';
    }

    /** @param array<string,string> $file */
    private static function arrayHasDb(array $file): bool
    {
        $driver = strtolower($file['DB_DRIVER'] ?? $file['SQ_DB_DRIVER'] ?? '');
        if ($driver === 'sqlite') {
            return true;
        }
        $name = $file['DB_NAME'] ?? $file['SQ_DB_NAME'] ?? '';
        $user = $file['DB_USER'] ?? $file['SQ_DB_USER'] ?? '';
        return trim($name) !== '' && trim($user) !== '';
    }

    /** @return array<string,string> */
    public static function dbKeysOnly(array $data): array
    {
        $out = [];
        foreach (self::DB_KEYS as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                $out[$key] = (string) $data[$key];
            }
            $alias = 'SQ_' . $key;
            if (isset($data[$alias]) && trim((string) $data[$alias]) !== '') {
                $out[$key] = (string) $data[$alias];
            }
        }
        return $out;
    }

    /**
     * Non-sensitive description of where database settings come from, for
     * the health endpoint. Never includes values, hosts, or paths.
     *
     * @return array{source:?string,chess:string}
     */
    public static function diagnose(): array
    {
        $source = null;
        if (self::envHasDb()) {
            $source = 'env';
        } elseif (self::arrayHasDb(self::loadFile(self::root() . '/.env'))) {
            $source = 'app_env';
        } else {
            $wdConfig = getenv('SQ_CONFIG');
            if (is_string($wdConfig) && $wdConfig !== '' && @is_file($wdConfig) && self::arrayHasDb(self::dbKeysOnly(self::loadFile($wdConfig)))) {
                $source = 'sq_config';
            }
        }
        $chess = self::siblingState();
        if ($source === null && $chess === 'ok') {
            $source = 'chess_env';
        }
        return ['source' => $source, 'chess' => $chess];
    }

    /** 'ok' | 'missing' | 'open_basedir' | 'unreadable' | 'no_db_keys' */
    private static function siblingState(): string
    {
        $path = dirname(self::root(), 2) . '/chess/app/.env';
        $basedir = (string) ini_get('open_basedir');
        if ($basedir !== '') {
            $inside = false;
            $norm = str_replace('\\', '/', strtolower($path));
            foreach (explode(PATH_SEPARATOR, $basedir) as $dir) {
                $dir = str_replace('\\', '/', strtolower(trim($dir)));
                if ($dir !== '' && str_starts_with($norm, rtrim($dir, '/') . '/')) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                return 'open_basedir';
            }
        }
        if (!@file_exists($path)) {
            return 'missing';
        }
        if (!@is_readable($path)) {
            return 'unreadable';
        }
        return self::arrayHasDb(self::dbKeysOnly(self::loadFile($path))) ? 'ok' : 'no_db_keys';
    }

    public static function allowedSiblingMorseEnv(): ?string
    {
        $path = dirname(self::root(), 2) . '/chess/app/.env';
        $real = @realpath($path);
        if ($real === false || !@is_readable($real)) {
            return null;
        }
        $norm = str_replace('\\', '/', $real);
        if (!str_ends_with($norm, '/chess/app/.env')) {
            return null;
        }
        return $real;
    }

    /** @return array<string,string> */
    public static function loadFile(string $path): array
    {
        $out = [];
        if (!@is_file($path) || !@is_readable($path)) {
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
