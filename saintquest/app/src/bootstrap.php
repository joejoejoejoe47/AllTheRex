<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'SaintQuest\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
