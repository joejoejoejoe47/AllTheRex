<?php

declare(strict_types=1);

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
$fail = 0;
foreach ($files as $file) {
    echo '== ' . basename($file) . " ==\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($cmd, $code);
    if ($code !== 0) {
        $fail++;
    }
}
exit($fail > 0 ? 1 : 0);
