<?php
declare(strict_types=1);

/**
 * Table definitions. MySQL variant is written for MySQL 5.5 (GoDaddy's a2nlmysql55plsk host):
 * InnoDB, utf8mb4, one TIMESTAMP default per table, index keys under the 767-byte column limit.
 * The SQLite variant exists so the test suite can run without a MySQL server.
 */
function rex_schema(string $driver): array
{
    if ($driver === 'sqlite') {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE IF NOT EXISTS items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category TEXT NOT NULL,
                title TEXT NOT NULL,
                creator TEXT NOT NULL DEFAULT \'\',
                year INTEGER NULL,
                dedupe_key TEXT NOT NULL UNIQUE,
                created_by INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE INDEX IF NOT EXISTS idx_items_cat ON items (category)',
            'CREATE TABLE IF NOT EXISTS ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
                rating INTEGER NOT NULL,
                rated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, item_id)
            )',
            'CREATE INDEX IF NOT EXISTS idx_ratings_item ON ratings (item_id)',
            'CREATE TABLE IF NOT EXISTS item_sim (
                item_a INTEGER NOT NULL,
                item_b INTEGER NOT NULL,
                sim REAL NOT NULL,
                n INTEGER NOT NULL,
                PRIMARY KEY (item_a, item_b)
            )',
            'CREATE INDEX IF NOT EXISTS idx_sim_b ON item_sim (item_b)',
            'CREATE TABLE IF NOT EXISTS login_attempts (
                ip TEXT NOT NULL,
                attempted_at INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_login_ip ON login_attempts (ip, attempted_at)',
        ];
    }

    $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            display_name VARCHAR(60) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_email (email)
        ) $tail",
        "CREATE TABLE IF NOT EXISTS items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category VARCHAR(10) NOT NULL,
            title VARCHAR(190) NOT NULL,
            creator VARCHAR(190) NOT NULL DEFAULT '',
            year SMALLINT UNSIGNED NULL,
            dedupe_key CHAR(40) NOT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_items_dedupe (dedupe_key),
            KEY idx_items_cat (category)
        ) $tail",
        "CREATE TABLE IF NOT EXISTS ratings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            rated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ratings_user_item (user_id, item_id),
            KEY idx_ratings_item (item_id),
            CONSTRAINT fk_ratings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_ratings_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
        ) $tail",
        "CREATE TABLE IF NOT EXISTS item_sim (
            item_a INT UNSIGNED NOT NULL,
            item_b INT UNSIGNED NOT NULL,
            sim DOUBLE NOT NULL,
            n SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (item_a, item_b),
            KEY idx_sim_b (item_b)
        ) $tail",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            ip VARCHAR(45) NOT NULL,
            attempted_at INT UNSIGNED NOT NULL,
            KEY idx_login_ip (ip, attempted_at)
        ) $tail",
    ];
}

function rex_install(PDO $pdo): void
{
    foreach (rex_schema($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) as $sql) {
        $pdo->exec($sql);
    }
}
