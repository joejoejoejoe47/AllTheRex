<?php

final class Db
{
    public static function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo) {
            return $pdo;
        }
        $cfg = self::config();
        if ($cfg === null) {
            throw new RuntimeException("Missing database settings. Open install.php first.");
        }
        $db = $cfg["db"] ?? $cfg;
        $host = $db["host"] ?? "localhost";
        $name = $db["name"] ?? "";
        $user = $db["user"] ?? "";
        $pass = $db["pass"] ?? "";
        $port = $db["port"] ?? 3306;
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::ensure($pdo);
        return $pdo;
    }

    public static function ensure(PDO $pdo): void
    {
        $pdo->exec("create table if not exists mc_users (
            id varchar(32) primary key,
            username varchar(20) not null,
            username_lc varchar(20) not null unique,
            password_hash varchar(255) not null,
            score int not null default 1200,
            coins int not null default 0,
            equipped_board varchar(40) not null default 'lodge',
            owned_boards varchar(255) not null default '',
            created_at timestamp default current_timestamp
        ) engine=InnoDB default charset=utf8mb4");
        $pdo->exec("create table if not exists mc_games (
            id varchar(16) primary key,
            white_id varchar(32) not null,
            black_id varchar(32) not null default '',
            fen text not null,
            status varchar(20) not null default 'active',
            last_from varchar(2) null,
            last_to varchar(2) null,
            winner_id varchar(32) null,
            created_at timestamp default current_timestamp
        ) engine=InnoDB default charset=utf8mb4");
        $pdo->exec("insert ignore into mc_users (id, username, username_lc, password_hash, score) values ('bot-mores', 'MorseBot', 'moresbot', '-', 1200)");
    }

    public static function ready(): bool
    {
        return self::config() !== null;
    }

    public static function config(): ?array
    {
        $name = trim((string) (getenv("MORSE_DB_NAME") ?: ""));
        if ($name !== "") {
            return [
                "db" => [
                    "host" => trim((string) (getenv("MORSE_DB_HOST") ?: "localhost")) ?: "localhost",
                    "name" => $name,
                    "user" => trim((string) (getenv("MORSE_DB_USER") ?: "")),
                    "pass" => (string) (getenv("MORSE_DB_PASS") ?: ""),
                    "port" => (int) (getenv("MORSE_DB_PORT") ?: 3306),
                ],
            ];
        }
        foreach ([self::outsidePath(), dirname(__DIR__) . "/config.php"] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $cfg = require $file;
            if (is_array($cfg) && (isset($cfg["db"]) || isset($cfg["name"]))) {
                return $cfg;
            }
        }
        return null;
    }

    public static function outsidePath(): string
    {
        return dirname(__DIR__, 3) . "/morse-private/config.php";
    }

    public static function write(array $flat): void
    {
        $cfg = ["driver" => "mysql", "db" => $flat];
        $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($cfg, true) . ";\n";
        $path = self::outsidePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $path = dirname(__DIR__) . "/config.php";
        }
        if (file_put_contents($path, $php) === false) {
            throw new RuntimeException("Could not save the database settings. Make the folder writable, then try again.");
        }
    }
}
