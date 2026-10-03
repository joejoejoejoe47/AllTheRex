<?php
declare(strict_types=1);

function config_path(): string
{
    $outside = outside_config_path();
    if (is_file($outside)) {
        return $outside;
    }
    return dirname(__DIR__) . '/config.php';
}

function outside_config_path(): string
{
    return dirname(__DIR__, 3) . '/morse-private/config.php';
}

function env_config(): ?array
{
    $name = trim((string) (getenv('MORSE_DB_NAME') ?: ''));
    if ($name === '') {
        return null;
    }
    return [
        'driver' => 'mysql',
        'db' => [
            'host' => trim((string) (getenv('MORSE_DB_HOST') ?: 'localhost')) ?: 'localhost',
            'name' => $name,
            'user' => trim((string) (getenv('MORSE_DB_USER') ?: '')),
            'pass' => (string) (getenv('MORSE_DB_PASS') ?: ''),
        ],
    ];
}

function app_config(): ?array
{
    $fromEnv = env_config();
    if ($fromEnv !== null) {
        return $fromEnv;
    }
    $path = config_path();
    if (!is_file($path)) {
        return null;
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : null;
}

function app_ready(): bool
{
    return app_config() !== null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = app_config();
    if ($cfg === null) {
        throw new RuntimeException('The club is not set up yet.');
    }
    $pdo = connect_config($cfg);
    migrate($pdo, (string) ($cfg['driver'] ?? 'mysql'));
    return $pdo;
}

function connect_config(array $cfg): PDO
{
    if (($cfg['driver'] ?? '') === 'sqlite') {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $dir . '/morse.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }
    $db = $cfg['db'] ?? [];
    return new PDO(
        'mysql:host=' . ($db['host'] ?? 'localhost') . ';dbname=' . ($db['name'] ?? '') . ';charset=utf8mb4',
        (string) ($db['user'] ?? ''),
        (string) ($db['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function write_config(array $cfg): void
{
    $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($cfg, true) . ";\n";
    $dir = dirname(outside_config_path());
    $path = outside_config_path();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $path = dirname(__DIR__) . '/config.php';
    }
    if (file_put_contents($path, $php) === false) {
        throw new RuntimeException('Could not save the club settings. Make the folder writable, then try again.');
    }
}

function migrate(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                coins INTEGER NOT NULL DEFAULT 100,
                rating INTEGER NOT NULL DEFAULT 1200,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS games (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                white_id INTEGER NOT NULL,
                black_id INTEGER,
                moves TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'play\',
                clock_seconds INTEGER NOT NULL DEFAULT 0,
                white_ms INTEGER NOT NULL DEFAULT 0,
                black_ms INTEGER NOT NULL DEFAULT 0,
                turn_started_at TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (white_id) REFERENCES users(id),
                FOREIGN KEY (black_id) REFERENCES users(id)
            )'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(24) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            coins INT NOT NULL DEFAULT 100,
            rating INT NOT NULL DEFAULT 1200,
            created_at DATETIME NOT NULL,
            KEY rating_idx (rating)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS games (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            white_id INT UNSIGNED NOT NULL,
            black_id INT UNSIGNED NULL,
            moves TEXT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT \'play\',
            clock_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            white_ms INT UNSIGNED NOT NULL DEFAULT 0,
            black_ms INT UNSIGNED NOT NULL DEFAULT 0,
            turn_started_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY players (white_id, black_id),
            CONSTRAINT games_white FOREIGN KEY (white_id) REFERENCES users(id),
            CONSTRAINT games_black FOREIGN KEY (black_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
