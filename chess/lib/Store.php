<?php

require_once __DIR__ . "/Chess.php";
require_once __DIR__ . "/Db.php";

const BOT_ID = "bot-mores";
const START_FEN = "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1";

function uid(): string
{
    return bin2hex(random_bytes(8));
}

function currentUser(): ?array
{
    if (empty($_SESSION["uid"])) {
        return null;
    }
    $st = Db::pdo()->prepare("select id, username, score, coins, equipped_board from mc_users where id = ?");
    $st->execute([$_SESSION["uid"]]);
    $row = $st->fetch();
    return $row ?: null;
}

function publicUser(array $row): array
{
    return [
        "id" => $row["id"],
        "username" => $row["username"],
        "score" => (int) $row["score"],
        "coins" => (int) $row["coins"],
        "board" => $row["equipped_board"],
    ];
}

function boards(): array
{
    return [
        ["id" => "lodge", "name" => "Lodge", "cost" => 0, "light" => "#f6e7c4", "dark" => "#17362a"],
        ["id" => "inlaid", "name" => "Inlaid", "cost" => 20, "light" => "#e7d3ae", "dark" => "#6b3e24"],
        ["id" => "grassland", "name" => "COLUSSEUM", "cost" => 100, "light" => "#c5d89a", "dark" => "#3c6e38"],
        ["id" => "marble", "name" => "Marble", "cost" => 0, "light" => "#f4efe6", "dark" => "#6e7c86"],
    ];
}

function boardBy(string $id): array
{
    foreach (boards() as $b) {
        if ($b["id"] === $id) {
            return $b;
        }
    }
    return boards()[0];
}

function gameRow(string $id): ?array
{
    $st = Db::pdo()->prepare("select * from mc_games where id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function nameOf(string $id): string
{
    if ($id === BOT_ID) {
        return "MorseBot";
    }
    $st = Db::pdo()->prepare("select username from mc_users where id = ?");
    $st->execute([$id]);
    return (string) ($st->fetchColumn() ?: "Player");
}

function presentGame(array $g, ?array $me): array
{
    $chess = MorseChess::fromFen($g["fen"]);
    $you = "s";
    if ($me && $g["white_id"] === $me["id"]) {
        $you = "w";
    } elseif ($me && $g["black_id"] === $me["id"]) {
        $you = "b";
    }
    return [
        "id" => $g["id"],
        "fen" => $g["fen"],
        "status" => $g["status"],
        "turn" => $chess->turn,
        "you" => $you,
        "white" => nameOf($g["white_id"]),
        "black" => $g["black_id"] ? nameOf($g["black_id"]) : "",
        "lastFrom" => $g["last_from"],
        "lastTo" => $g["last_to"],
        "board" => $me["equipped_board"] ?? "lodge",
        "waiting" => $g["status"] === "waiting",
        "moves" => $g["status"] === "active" && $you === $chess->turn ? $chess->legal() : [],
    ];
}

function finishIfOver(array &$g, MorseChess $chess): void
{
    $result = $chess->result();
    if ($result === "active") {
        return;
    }
    $winner = null;
    if ($result === "white_win") {
        $winner = $g["white_id"];
    } elseif ($result === "black_win") {
        $winner = $g["black_id"];
    }
    $g["status"] = $result;
    $g["winner_id"] = $winner;
    Db::pdo()->prepare("update mc_games set status = ?, winner_id = ?, fen = ? where id = ?")
        ->execute([$result, $winner, $chess->fen(), $g["id"]]);
    if ($winner && $winner !== BOT_ID) {
        Db::pdo()->prepare("update mc_users set score = score + 12, coins = coins + 5 where id = ?")->execute([$winner]);
    }
    $loser = $winner === $g["white_id"] ? $g["black_id"] : ($winner === $g["black_id"] ? $g["white_id"] : null);
    if ($loser && $loser !== BOT_ID && $result !== "draw") {
        Db::pdo()->prepare("update mc_users set score = greatest(100, score - 8) where id = ?")->execute([$loser]);
    }
}

function botReply(array &$g): void
{
    if ($g["status"] !== "active" || $g["black_id"] !== BOT_ID) {
        return;
    }
    $chess = MorseChess::fromFen($g["fen"]);
    if ($chess->turn !== "b") {
        return;
    }
    $moves = $chess->legal();
    if (!$moves) {
        finishIfOver($g, $chess);
        return;
    }
    $pick = $moves[array_rand($moves)];
    foreach ($moves as $m) {
        $copy = MorseChess::fromFen($g["fen"]);
        $before = $copy->sq;
        $copy->play($m["from"], $m["to"], $m["promo"] ?: "q");
        $bi = (8 - (ord($m["to"][1]) - 48)) * 8 + (ord($m["to"][0]) - 97);
        if (($before[$bi] ?? ".") !== ".") {
            $pick = $m;
            break;
        }
    }
    $chess->play($pick["from"], $pick["to"], $pick["promo"] ?: "q");
    $g["fen"] = $chess->fen();
    $g["last_from"] = $pick["from"];
    $g["last_to"] = $pick["to"];
    Db::pdo()->prepare("update mc_games set fen = ?, last_from = ?, last_to = ? where id = ?")
        ->execute([$g["fen"], $pick["from"], $pick["to"], $g["id"]]);
    finishIfOver($g, $chess);
}
