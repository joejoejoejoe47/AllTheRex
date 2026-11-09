<?php

declare(strict_types=1);

namespace WorldDomination;

use PDO;
use PDOException;
use Throwable;

/**
 * Thin PDO wrapper. MySQL/MariaDB in production; SQLite for local play and tests.
 *
 * Queries use positional `?` placeholders only. Timestamps are UTC strings
 * written in PHP so both drivers store the same values.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'mysql';
    /** Which database the schema marker belongs to (DSN only, never credentials). */
    private static string $identity = '';

    public static function driver(): string
    {
        self::pdo();
        return self::$driver;
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    public static function useConnection(PDO $pdo, string $driver): void
    {
        self::$pdo = $pdo;
        self::$driver = $driver;
        self::$identity = 'injected:' . spl_object_id($pdo);
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$driver = 'mysql';
        self::$identity = '';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $driver = strtolower(Config::get('DB_DRIVER', 'mysql') ?? 'mysql');
        if ($driver === 'sqlite') {
            $path = Config::get('DB_SQLITE') ?? Config::storagePath('worlddom.sqlite');
            if ($path !== ':memory:' && !str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
                $path = Config::root() . '/' . ltrim($path, '/');
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            self::$driver = 'sqlite';
            self::$identity = 'sqlite:' . $path;
        } else {
            $socket = Config::get('DB_SOCKET');
            $dsn = $socket
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, Config::get('DB_NAME', 'world_domination'))
                : sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    Config::get('DB_HOST', '127.0.0.1'),
                    Config::get('DB_PORT', '3306'),
                    Config::get('DB_NAME', 'world_domination')
                );
            self::$identity = $dsn;
            $opts = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];
            for ($try = 0; ; $try++) {
                try {
                    $pdo = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''), $opts);
                    break;
                } catch (PDOException $e) {
                    $code = (int) ($e->errorInfo[1] ?? $e->getCode());
                    if ($try >= 3 || !in_array($code, [1040, 1203, 2002, 2006, 2013], true)) {
                        throw $e;
                    }
                    usleep([250000, 600000, 1200000][$try]);
                }
            }
            $pdo->exec("SET time_zone = '+00:00'");
            self::$driver = 'mysql';
        }
        return self::$pdo = $pdo;
    }

    /** @param array<int,mixed> $params @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::bind($params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int,mixed> $params @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::bind($params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row === false ? null : $row;
    }

    /** @param array<int,mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $row = self::one($sql, $params);
        if ($row === null) {
            return null;
        }
        return reset($row);
    }

    /** @param array<int,mixed> $params */
    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::bind($params));
        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keyCols
     * @param list<string> $updateCols
     */
    public static function upsert(string $table, array $row, array $keyCols, array $updateCols): int
    {
        $cols = array_keys($row);
        $base = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        if (self::isSqlite()) {
            $set = implode(', ', array_map(fn ($c) => self::ident($c) . ' = excluded.' . self::ident($c), $updateCols));
            $sql = $base . ' ON CONFLICT (' . implode(', ', array_map([self::class, 'ident'], $keyCols)) . ') DO UPDATE SET ' . $set;
        } else {
            $set = implode(', ', array_map(fn ($c) => self::ident($c) . ' = VALUES(' . self::ident($c) . ')', $updateCols));
            $sql = $base . ' ON DUPLICATE KEY UPDATE ' . $set;
        }
        return self::run($sql, array_values($row));
    }

    /** @template T @param callable():T $fn @return T */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function toMs(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            $n = (float) $v;
            return (int) ($n < 1e12 ? $n * 1000 : $n);
        }
        if (is_string($v) && $v !== '') {
            if (ctype_digit($v)) {
                $n = (float) $v;
                return (int) ($n < 1e12 ? $n * 1000 : $n);
            }
            $s = $v;
            if (!preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s)) {
                $s = str_replace(' ', 'T', $s) . 'Z';
            }
            $t = strtotime($s);
            if ($t !== false) {
                return $t * 1000;
            }
        }
        return self::nowMs();
    }

    public static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** @param array<int,mixed> $params @return array<int,mixed> */
    private static function bind(array $params): array
    {
        $out = [];
        foreach (array_values($params) as $p) {
            $out[] = is_bool($p) ? ($p ? 1 : 0) : $p;
        }
        return $out;
    }

    public static function ensureSchema(bool $force = false): void
    {
        $file = Config::root() . '/sql/schema.sql';
        $sql = (string) file_get_contents($file);
        // The marker is per database, so pointing the app at a new or empty
        // database (another DB_SQLITE file, another DB_NAME) still migrates it.
        $hash = sha1($sql . self::driver() . '|' . self::$identity);
        $flag = Config::storagePath('schema-' . substr($hash, 0, 16) . '.ok');
        if (!$force && is_file($flag)) {
            return;
        }
        self::runSchema($sql);
        @file_put_contents($flag, gmdate('c'));
    }

    public static function runSchema(string $sql): void
    {
        $pdo = self::pdo();
        foreach (self::splitStatements($sql) as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $code = (int) ($e->errorInfo[1] ?? 0);
                if ($code === 1061 || $code === 1050 || stripos($msg, 'already exists') !== false) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /** @return list<string> */
    private static function splitStatements(string $sql): array
    {
        $parts = [];
        $buf = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $buf .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $parts[] = $stmt;
                }
                $buf = '';
            }
        }
        $tail = trim($buf);
        if ($tail !== '') {
            $parts[] = $tail;
        }
        return $parts;
    }
}
