<?php
declare(strict_types=1);
// Entry page. Served by PHP (not static HTML) so we can send a strict Content-Security-Policy.
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self)');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://accounts.google.com/gsi/client; "
    . "style-src 'self' https://accounts.google.com/gsi/style; img-src 'self' https: data:; media-src 'self' blob:; "
    . "connect-src 'self' https://accounts.google.com/gsi/; frame-src https://accounts.google.com/gsi/; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$v = (string)@filemtime(__DIR__ . '/assets/app.js'); // cache-bust after each build
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>vchat</title>
  <link rel="stylesheet" href="assets/app.css?v=<?= htmlspecialchars($v) ?>">
</head>
<body>
  <div id="app"></div>
  <script src="assets/app.js?v=<?= htmlspecialchars($v) ?>"></script>
</body>
</html>
