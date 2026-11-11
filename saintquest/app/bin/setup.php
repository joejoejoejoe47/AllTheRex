<?php

declare(strict_types=1);

/**
 * CLI-only setup. Refuses HTTP. Never writes credentials into the web tree.
 * Configuration must already exist via DB_* / SQ_DB_* environment variables,
 * a privately placed guarded app/.env, or sibling chess/app/.env DB_* keys.
 *
 *   php app/bin/setup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SaintQuest\Config;
use SaintQuest\Db;

if (in_array('--write-env', $argv ?? [], true)) {
    fwrite(STDERR, "Refusing to write credentials into the web tree. Use environment variables or sibling Morse chess/app/.env DB_* keys.\n");
    exit(5);
}

if (!Config::dbConfigured()) {
    fwrite(STDERR, "No database settings found. Set DB_* or SQ_DB_* (same contract as Morse Chess), or rely on sibling chess/app/.env.\n");
    exit(3);
}

try {
    Db::pdo();
    Db::ensureSchema(true);
} catch (Throwable $e) {
    fwrite(STDERR, "Configured settings do not connect: " . $e->getMessage() . "\n");
    exit(2);
}

fwrite(STDOUT, "Already configured. Configuration is immutable; tables checked.\n");
