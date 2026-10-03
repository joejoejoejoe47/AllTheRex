<?php
require_once __DIR__ . "/lib/Db.php";
if (!Db::ready()) {
    header("Location: install.php");
    exit;
}
$base = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Morse Chess</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/app.css" />
</head>
<body>
  <div id="app"></div>
  <script>window.MORSE_BASE = <?= json_encode($base) ?>;</script>
  <script src="<?= htmlspecialchars($base) ?>/assets/app.js"></script>
</body>
</html>
