<?php
session_start();
require_once __DIR__ . "/lib/Db.php";

function schema(PDO $pdo): void
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
    $pdo->prepare("insert ignore into mc_users (id, username, username_lc, password_hash, score) values ('bot-mores', 'MorseBot', 'moresbot', '-', 1200)")
        ->execute();
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $c = [
        "host" => trim($_POST["host"] ?? "localhost"),
        "name" => trim($_POST["name"] ?? ""),
        "user" => trim($_POST["user"] ?? ""),
        "pass" => (string) ($_POST["pass"] ?? ""),
        "port" => (int) ($_POST["port"] ?? 3306),
    ];
    try {
        $pdo = new PDO(
            "mysql:host={$c["host"]};port={$c["port"]};dbname={$c["name"]};charset=utf8mb4",
            $c["user"],
            $c["pass"],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        schema($pdo);
        Db::write($c);
        header("Location: index.php");
        exit;
    } catch (Throwable $err) {
        $error = $err->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Install Morse Chess</title>
  <link rel="stylesheet" href="assets/app.css" />
</head>
<body>
  <main class="card">
    <p class="eyebrow">Plesk</p>
    <h1>Install Morse Chess</h1>
    <p class="muted">Create a MySQL database in Plesk first, then enter it here. Settings are saved outside this Git folder.</p>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <label>Host <input name="host" value="localhost" required /></label>
      <label>Database <input name="name" required /></label>
      <label>User <input name="user" required /></label>
      <label>Password <input name="pass" type="password" /></label>
      <label>Port <input name="port" value="3306" /></label>
      <button type="submit">Create tables</button>
    </form>
  </main>
</body>
</html>
