<?php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . "/lib/Store.php";

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$raw = file_get_contents("php://input") ?: "";
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}
$action = $_GET["action"] ?? ($body["action"] ?? "");

try {
    if ($action === "boards") {
        out(["boards" => boards()]);
    }
    if ($action === "register") {
        $name = trim((string) ($body["username"] ?? ""));
        $pass = (string) ($body["password"] ?? "");
        if (!preg_match("/^[A-Za-z0-9_]{3,20}$/", $name)) {
            out(["error" => "Username is 3–20 letters, numbers, or underscores."], 400);
        }
        if (strlen($pass) < 6) {
            out(["error" => "Password needs at least 6 characters."], 400);
        }
        $id = uid();
        $st = Db::pdo()->prepare("insert into mc_users (id, username, username_lc, password_hash) values (?, ?, ?, ?)");
        try {
            $st->execute([$id, $name, strtolower($name), password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException) {
            out(["error" => "That username is taken."], 409);
        }
        $_SESSION["uid"] = $id;
        out(["user" => publicUser(currentUser())]);
    }
    if ($action === "login") {
        $name = strtolower(trim((string) ($body["username"] ?? "")));
        $pass = (string) ($body["password"] ?? "");
        $st = Db::pdo()->prepare("select * from mc_users where username_lc = ?");
        $st->execute([$name]);
        $row = $st->fetch();
        if (!$row || !password_verify($pass, $row["password_hash"])) {
            out(["error" => "Wrong username or password."], 401);
        }
        $_SESSION["uid"] = $row["id"];
        out(["user" => publicUser($row)]);
    }
    if ($action === "logout") {
        $_SESSION = [];
        out(["ok" => true]);
    }
    if ($action === "me") {
        $me = currentUser();
        out(["user" => $me ? publicUser($me) : null, "boards" => boards()]);
    }

    $me = currentUser();
    if (!$me) {
        out(["error" => "Sign in first."], 401);
    }

    if ($action === "board") {
        $id = (string) ($body["id"] ?? "");
        $board = boardBy($id);
        if ($board["id"] !== $id) {
            out(["error" => "Unknown board."], 404);
        }
        if ($board["cost"] > 0) {
            $owned = array_filter(explode(",", (string) Db::pdo()->query("select owned_boards from mc_users where id = " . Db::pdo()->quote($me["id"]))->fetchColumn()));
            if (!in_array($id, $owned, true)) {
                if ((int) $me["coins"] < $board["cost"]) {
                    out(["error" => "You need {$board["cost"]} Morse coins."], 400);
                }
                $owned[] = $id;
                Db::pdo()->prepare("update mc_users set coins = coins - ?, owned_boards = ?, equipped_board = ? where id = ?")
                    ->execute([$board["cost"], implode(",", $owned), $id, $me["id"]]);
                $me = currentUser();
                out(["user" => publicUser($me)]);
            }
        }
        Db::pdo()->prepare("update mc_users set equipped_board = ? where id = ?")->execute([$id, $me["id"]]);
        $me = currentUser();
        out(["user" => publicUser($me)]);
    }

    if ($action === "bot") {
        $id = uid();
        Db::pdo()->prepare("insert into mc_games (id, white_id, black_id, fen, status) values (?, ?, ?, ?, 'active')")
            ->execute([$id, $me["id"], BOT_ID, START_FEN]);
        out(["game" => presentGame(gameRow($id), $me)]);
    }

    if ($action === "host") {
        $id = substr(uid(), 0, 6);
        Db::pdo()->prepare("insert into mc_games (id, white_id, black_id, fen, status) values (?, ?, '', ?, 'waiting')")
            ->execute([$id, $me["id"], START_FEN]);
        out(["game" => presentGame(gameRow($id), $me)]);
    }

    if ($action === "join") {
        $id = strtolower(trim((string) ($body["id"] ?? "")));
        $g = gameRow($id);
        if (!$g) {
            out(["error" => "No game with that code."], 404);
        }
        if ($g["status"] === "waiting" && $g["white_id"] !== $me["id"]) {
            Db::pdo()->prepare("update mc_games set black_id = ?, status = 'active' where id = ?")->execute([$me["id"], $id]);
            $g = gameRow($id);
        }
        out(["game" => presentGame($g, $me)]);
    }

    if ($action === "game") {
        $g = gameRow((string) ($body["id"] ?? $_GET["id"] ?? ""));
        if (!$g) {
            out(["error" => "Game not found."], 404);
        }
        out(["game" => presentGame($g, $me)]);
    }

    if ($action === "move") {
        $g = gameRow((string) ($body["id"] ?? ""));
        if (!$g || $g["status"] !== "active") {
            out(["error" => "That game is not active."], 400);
        }
        $chess = MorseChess::fromFen($g["fen"]);
        $side = $chess->turn === "w" ? $g["white_id"] : $g["black_id"];
        if ($side !== $me["id"]) {
            out(["error" => "Not your turn."], 400);
        }
        $ok = $chess->play((string) $body["from"], (string) $body["to"], (string) ($body["promo"] ?? ""));
        if (!$ok) {
            out(["error" => "Illegal move."], 400);
        }
        $g["fen"] = $chess->fen();
        $g["last_from"] = strtolower((string) $body["from"]);
        $g["last_to"] = strtolower((string) $body["to"]);
        Db::pdo()->prepare("update mc_games set fen = ?, last_from = ?, last_to = ? where id = ?")
            ->execute([$g["fen"], $g["last_from"], $g["last_to"], $g["id"]]);
        finishIfOver($g, $chess);
        $g = gameRow($g["id"]);
        botReply($g);
        $g = gameRow($g["id"]);
        out(["game" => presentGame($g, currentUser())]);
    }

    if ($action === "resign") {
        $g = gameRow((string) ($body["id"] ?? ""));
        if (!$g || $g["status"] !== "active") {
            out(["error" => "That game is not active."], 400);
        }
        $winner = $g["white_id"] === $me["id"] ? $g["black_id"] : $g["white_id"];
        $status = $g["white_id"] === $me["id"] ? "black_win" : "white_win";
        Db::pdo()->prepare("update mc_games set status = ?, winner_id = ? where id = ?")->execute([$status, $winner, $g["id"]]);
        out(["game" => presentGame(gameRow($g["id"]), $me)]);
    }

    out(["error" => "Unknown action."], 404);
} catch (Throwable $err) {
    out(["error" => $err->getMessage()], 500);
}
