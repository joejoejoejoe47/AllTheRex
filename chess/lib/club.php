<?php
declare(strict_types=1);

function handle_post(): void
{
    if (!csrf_ok()) {
        $_SESSION['flash'] = 'That form expired. Try once more.';
        redirect('index.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'setup') {
        do_setup();
    }
    if ($action === 'register') {
        do_register();
    }
    if ($action === 'login') {
        do_login();
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect('index.php');
    }
    $user = current_user();
    if (!$user) {
        redirect('index.php');
    }
    if ($action === 'bot') {
        $id = create_game((int) $user['id'], null, clock_choice());
        redirect('index.php?g=' . $id);
    }
    if ($action === 'challenge') {
        $opp = find_user((string) ($_POST['opponent'] ?? ''));
        if (!$opp) {
            $_SESSION['flash'] = 'No member uses that name.';
            redirect('index.php');
        }
        if ((int) $opp['id'] === (int) $user['id']) {
            $_SESSION['flash'] = 'You cannot challenge yourself.';
            redirect('index.php');
        }
        $id = create_game((int) $user['id'], (int) $opp['id'], clock_choice());
        redirect('index.php?g=' . $id);
    }
    if ($action === 'resign') {
        resign_game((int) ($_POST['game'] ?? 0), (int) $user['id']);
        redirect('index.php?g=' . (int) ($_POST['game'] ?? 0));
    }
    redirect('index.php');
}

function do_setup(): void
{
    $driver = (string) ($_POST['driver'] ?? 'mysql');
    try {
        if ($driver === 'sqlite') {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('This server has no file database. Use MySQL.');
            }
            $cfg = ['driver' => 'sqlite'];
            $pdo = connect_config($cfg);
            migrate($pdo, 'sqlite');
            write_config($cfg);
        } else {
            $cfg = [
                'driver' => 'mysql',
                'db' => [
                    'host' => trim((string) ($_POST['host'] ?? 'localhost')) ?: 'localhost',
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'user' => trim((string) ($_POST['user'] ?? '')),
                    'pass' => (string) ($_POST['pass'] ?? ''),
                ],
            ];
            if ($cfg['db']['name'] === '' || $cfg['db']['user'] === '') {
                throw new RuntimeException('The database name and user are required.');
            }
            $pdo = connect_config($cfg);
            migrate($pdo, 'mysql');
            write_config($cfg);
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = $e->getMessage();
        redirect('index.php');
    }
    redirect('index.php');
}

function do_register(): void
{
    $name = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $name)) {
        $_SESSION['flash'] = 'Use 3 to 20 letters, numbers, or underscores.';
        redirect('index.php');
    }
    if (strlen($pass) < 6) {
        $_SESSION['flash'] = 'Use a password of at least 6 characters.';
        redirect('index.php');
    }
    if (find_user($name)) {
        $_SESSION['flash'] = 'That name is already in the club.';
        redirect('index.php');
    }
    $st = db()->prepare('INSERT INTO users (username, password_hash, coins, rating, created_at) VALUES (?, ?, 100, 1200, ?)');
    $st->execute([$name, password_hash($pass, PASSWORD_DEFAULT), now()]);
    $_SESSION['uid'] = (int) db()->lastInsertId();
    session_regenerate_id(true);
    redirect('index.php');
}

function do_login(): void
{
    $name = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $user = find_user($name);
    if (!$user || !password_verify($pass, (string) $user['password_hash'])) {
        $_SESSION['flash'] = 'That name or password is not in the book.';
        redirect('index.php');
    }
    $_SESSION['uid'] = (int) $user['id'];
    session_regenerate_id(true);
    redirect('index.php');
}

function find_user(string $name): ?array
{
    if (!app_ready()) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?)');
    $st->execute([trim($name)]);
    $row = $st->fetch();
    return $row ?: null;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function clock_choice(): int
{
    return (int) ($_POST['clock'] ?? 0) === 300 ? 300 : 0;
}

function create_game(int $white, ?int $black, int $clock): int
{
    $ms = $clock * 1000;
    $st = db()->prepare(
        "INSERT INTO games (white_id, black_id, moves, status, clock_seconds, white_ms, black_ms, turn_started_at, created_at)
         VALUES (?, ?, '', 'play', ?, ?, ?, ?, ?)"
    );
    $st->bindValue(1, $white, PDO::PARAM_INT);
    if ($black === null) {
        $st->bindValue(2, null, PDO::PARAM_NULL);
    } else {
        $st->bindValue(2, $black, PDO::PARAM_INT);
    }
    $st->bindValue(3, $clock, PDO::PARAM_INT);
    $st->bindValue(4, $ms, PDO::PARAM_INT);
    $st->bindValue(5, $ms, PDO::PARAM_INT);
    $st->bindValue(6, now(), PDO::PARAM_STR);
    $st->bindValue(7, now(), PDO::PARAM_STR);
    $st->execute();
    return (int) db()->lastInsertId();
}

function game_row(int $id): ?array
{
    $st = db()->prepare(
        'SELECT g.*, w.username AS white_name, w.rating AS white_rating,
                b.username AS black_name, b.rating AS black_rating
         FROM games g
         JOIN users w ON w.id = g.white_id
         LEFT JOIN users b ON b.id = g.black_id
         WHERE g.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function move_list(string $moves): array
{
    $moves = trim($moves);
    if ($moves === '') {
        return [];
    }
    return preg_split('/\s+/', $moves) ?: [];
}

function side_to_move(array $game): string
{
    return count(move_list((string) $game['moves'])) % 2 === 0 ? 'white' : 'black';
}

function remain_ms(array $game, string $side): int
{
    $base = (int) $game[$side . '_ms'];
    if ((int) $game['clock_seconds'] === 0 || $game['status'] !== 'play') {
        return $base;
    }
    if (side_to_move($game) !== $side || empty($game['turn_started_at'])) {
        return $base;
    }
    $elapsed = max(0, time() - (int) strtotime((string) $game['turn_started_at'])) * 1000;
    return max(0, $base - $elapsed);
}

function flag_if_needed(array $game): array
{
    if ($game['status'] !== 'play' || (int) $game['clock_seconds'] === 0) {
        return $game;
    }
    $side = side_to_move($game);
    if (remain_ms($game, $side) > 0) {
        return $game;
    }
    finish_game($game, $side === 'white' ? 'black' : 'white');
    return game_row((int) $game['id']) ?? $game;
}

function finish_game(array $game, string $result): void
{
    if ($game['status'] !== 'play' || !in_array($result, ['white', 'black', 'draw'], true)) {
        return;
    }
    $st = db()->prepare("UPDATE games SET status = ?, white_ms = ?, black_ms = ? WHERE id = ? AND status = 'play'");
    $st->execute([
        $result,
        remain_ms($game, 'white'),
        remain_ms($game, 'black'),
        (int) $game['id'],
    ]);
    if ($st->rowCount() === 0) {
        return;
    }
    $white = find_id((int) $game['white_id']);
    $blackRating = 1000;
    $blackId = $game['black_id'] !== null ? (int) $game['black_id'] : 0;
    if ($blackId) {
        $black = find_id($blackId);
        $blackRating = (int) ($black['rating'] ?? 1000);
    }
    $whiteRating = (int) ($white['rating'] ?? 1200);
    if ($result === 'draw') {
        bump((int) $white['id'], 5, elo($whiteRating, $blackRating, 0.5));
        if ($blackId) {
            bump($blackId, 5, elo($blackRating, $whiteRating, 0.5));
        }
        return;
    }
    $whiteScore = $result === 'white' ? 1.0 : 0.0;
    bump((int) $white['id'], $whiteScore === 1.0 ? 20 : 0, elo($whiteRating, $blackRating, $whiteScore));
    if ($blackId) {
        bump($blackId, $whiteScore === 0.0 ? 20 : 0, elo($blackRating, $whiteRating, 1 - $whiteScore));
    }
}

function find_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function bump(int $id, int $coins, int $rating): void
{
    $st = db()->prepare('UPDATE users SET coins = coins + ?, rating = ? WHERE id = ?');
    $st->execute([$coins, max(100, $rating), $id]);
}

function elo(int $mine, int $theirs, float $score): int
{
    $expected = 1 / (1 + 10 ** (($theirs - $mine) / 400));
    return (int) round($mine + 24 * ($score - $expected));
}

function resign_game(int $id, int $uid): void
{
    $game = game_row($id);
    if (!$game || $game['status'] !== 'play') {
        return;
    }
    $side = player_side($game, $uid);
    if ($side === null) {
        return;
    }
    finish_game($game, $side === 'white' ? 'black' : 'white');
}

function player_side(array $game, int $uid): ?string
{
    if ((int) $game['white_id'] === $uid) {
        return 'white';
    }
    if ($game['black_id'] !== null && (int) $game['black_id'] === $uid) {
        return 'black';
    }
    return null;
}

function api_dispatch(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        $body = [];
    }
    $action = (string) ($body['action'] ?? ($_GET['action'] ?? 'state'));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_ok()) {
        http_response_code(403);
        return ['error' => 'Refresh the club and try that again.'];
    }
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        return ['error' => 'Sign in first.'];
    }
    if ($action === 'state') {
        $id = (int) ($body['game'] ?? ($_GET['g'] ?? 0));
        $game = game_row($id);
        if (!$game || player_side($game, (int) $user['id']) === null) {
            http_response_code(404);
            return ['error' => 'That table is not yours.'];
        }
        $game = flag_if_needed($game);
        return ['game' => game_payload($game, (int) $user['id'])];
    }
    if ($action === 'move') {
        return post_move($user, $body);
    }
    http_response_code(400);
    return ['error' => 'Unknown request.'];
}

function post_move(array $user, array $body): array
{
    $game = game_row((int) ($body['game'] ?? 0));
    if (!$game) {
        http_response_code(404);
        return ['error' => 'That table is gone.'];
    }
    $uid = (int) $user['id'];
    $side = player_side($game, $uid);
    $bot = $game['black_id'] === null;
    if ($side === null || ($bot && $side !== 'white')) {
        http_response_code(403);
        return ['error' => 'You are not seated here.'];
    }
    $game = flag_if_needed($game);
    if ($game['status'] !== 'play') {
        return ['game' => game_payload($game, $uid), 'bot' => false];
    }
    $uci = strtolower((string) ($body['uci'] ?? ''));
    if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci)) {
        http_response_code(422);
        return ['error' => 'That is not a move.'];
    }
    $turn = side_to_move($game);
    if ($bot && $turn === 'black') {
        // MorseBot's move is sent by the seated player.
    } elseif ($turn !== $side) {
        http_response_code(409);
        return ['error' => 'It is not your turn.', 'game' => game_payload($game, $uid)];
    }
    $over = (string) ($body['over'] ?? '');
    if (!in_array($over, ['', 'white', 'black', 'draw'], true)) {
        $over = '';
    }
    $moves = trim(trim((string) $game['moves']) . ' ' . $uci);
    $whiteMs = remain_ms($game, 'white');
    $blackMs = remain_ms($game, 'black');
    $st = db()->prepare(
        "UPDATE games SET moves = ?, white_ms = ?, black_ms = ?, turn_started_at = ? WHERE id = ? AND status = 'play'"
    );
    $st->execute([$moves, $whiteMs, $blackMs, now(), (int) $game['id']]);
    $fresh = game_row((int) $game['id']);
    if ($fresh && $over !== '') {
        finish_game($fresh, $over);
        $fresh = game_row((int) $game['id']);
    }
    $payload = game_payload($fresh ?? $game, $uid);
    $botTurn = $bot && $payload['status'] === 'play' && $payload['turn'] === 'black';
    return ['game' => $payload, 'bot' => $botTurn];
}

function game_payload(array $game, int $uid): array
{
    $side = player_side($game, $uid);
    return [
        'id' => (int) $game['id'],
        'moves' => trim((string) $game['moves']),
        'status' => (string) $game['status'],
        'you' => $side,
        'turn' => side_to_move($game),
        'bot' => $game['black_id'] === null,
        'clock' => (int) $game['clock_seconds'],
        'whiteMs' => remain_ms($game, 'white'),
        'blackMs' => remain_ms($game, 'black'),
        'serverNow' => time(),
        'white' => ['name' => (string) $game['white_name'], 'rating' => (int) $game['white_rating']],
        'black' => [
            'name' => $game['black_name'] ? (string) $game['black_name'] : 'MorseBot',
            'rating' => $game['black_name'] ? (int) $game['black_rating'] : 1000,
        ],
    ];
}

function render_page(): string
{
    if (!app_ready()) {
        return layout('Set up the club', setup_view(), false);
    }
    $user = current_user();
    if (!$user) {
        return layout('Enter the club', auth_view(), false);
    }
    $gid = (int) ($_GET['g'] ?? 0);
    if ($gid > 0) {
        $game = game_row($gid);
        if (!$game || player_side($game, (int) $user['id']) === null) {
            $_SESSION['flash'] = 'That table is not yours.';
            redirect('index.php');
        }
        return layout('Table ' . $gid, game_view($user, flag_if_needed($game)), true);
    }
    return layout('The club', hall_view($user), false);
}

function layout(string $title, string $body, bool $board): string
{
    $flash = (string) ($_SESSION['flash'] ?? '');
    unset($_SESSION['flash']);
    $token = h(csrf_token());
    $script = $board
        ? '<script type="module" src="assets/game.js"></script>'
        : '';
    $note = $flash !== '' ? '<p class="flash">' . h($flash) . '</p>' : '';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' · Morse Chess Club</title>'
        . '<link rel="stylesheet" href="assets/club.css">'
        . '<meta name="morse-token" content="' . $token . '">'
        . '</head><body class="' . ($board ? 'on-board' : 'in-club') . '">'
        . $note . $body . $script . '</body></html>';
}

function render_error(string $message): string
{
    return layout('Club', '<main class="sheet"><h1>Morse Chess Club</h1><p>' . h($message) . '</p></main>', false);
}

function setup_view(): string
{
    $token = h(csrf_token());
    return '<main class="sheet narrow">'
        . '<p class="eyebrow">Plesk</p><h1>Open the club</h1>'
        . '<p class="lede">Point this at the MySQL database you created in Plesk. The host is usually localhost.</p>'
        . '<form method="post" class="stack">'
        . '<input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="setup">'
        . '<input type="hidden" name="driver" value="mysql">'
        . '<label>Database host<input name="host" value="localhost" autocomplete="off"></label>'
        . '<label>Database name<input name="name" required autocomplete="off"></label>'
        . '<label>Database user<input name="user" required autocomplete="off"></label>'
        . '<label>Database password<input name="pass" type="password" autocomplete="new-password"></label>'
        . '<button type="submit" class="brass">Save and open</button></form>'
        . '<form method="post" class="stack quiet">'
        . '<input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="setup">'
        . '<input type="hidden" name="driver" value="sqlite">'
        . '<button type="submit" class="texty">Use a private file database instead</button>'
        . '</form></main>';
}

function auth_view(): string
{
    $token = h(csrf_token());
    return '<main class="gate"><section class="sheet narrow">'
        . '<p class="eyebrow">Members</p><h1>Morse Chess Club</h1>'
        . '<p class="lede">A table, a name, and a clock. Sign in, or take a seat.</p>'
        . '<form method="post" class="stack">'
        . '<input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="login">'
        . '<label>Username<input name="username" autocomplete="username" required></label>'
        . '<label>Password<input name="password" type="password" autocomplete="current-password" required></label>'
        . '<button type="submit" class="brass">Sign in</button></form>'
        . '<form method="post" class="stack split">'
        . '<input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="register">'
        . '<label>New username<input name="username" autocomplete="off" required></label>'
        . '<label>New password<input name="password" type="password" autocomplete="new-password" required></label>'
        . '<button type="submit" class="ink">Create account</button></form>'
        . '</section></main>';
}

function hall_view(array $user): string
{
    $token = h(csrf_token());
    $st = db()->prepare(
        'SELECT g.id, g.status, g.white_id, g.black_id, w.username AS white_name, b.username AS black_name, g.created_at
         FROM games g
         JOIN users w ON w.id = g.white_id
         LEFT JOIN users b ON b.id = g.black_id
         WHERE g.white_id = ? OR g.black_id = ?
         ORDER BY g.id DESC LIMIT 16'
    );
    $st->execute([(int) $user['id'], (int) $user['id']]);
    $games = $st->fetchAll();
    $standings = db()->query('SELECT username, rating, coins FROM users ORDER BY rating DESC, username ASC LIMIT 12')->fetchAll();
    $rows = '';
    foreach ($standings as $i => $row) {
        $rows .= '<li><span>' . ($i + 1) . '</span><strong>' . h((string) $row['username']) . '</strong><em>' . (int) $row['rating'] . '</em></li>';
    }
    $list = '';
    foreach ($games as $game) {
        $black = $game['black_name'] ? (string) $game['black_name'] : 'MorseBot';
        $label = $game['status'] === 'play' ? 'In play' : ucfirst((string) $game['status']);
        $list .= '<a class="game-row" href="index.php?g=' . (int) $game['id'] . '"><span>'
            . h((string) $game['white_name']) . ' vs ' . h($black) . '</span><em>' . h($label) . '</em></a>';
    }
    if ($list === '') {
        $list = '<p class="empty">No games yet. Sit down with MorseBot, or challenge a member.</p>';
    }
    $clock = '<label class="clockpick">Clock<select name="clock"><option value="0">Breeze</option><option value="300">5 minutes</option></select></label>';
    return '<header class="top"><a class="mark" href="index.php">Morse Chess</a>'
        . '<p class="coins"><i>M</i><span>' . (int) $user['coins'] . '</span></p>'
        . '<p class="who">' . h((string) $user['username']) . '<small>' . (int) $user['rating'] . '</small></p>'
        . '<form method="post">' . '<input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="logout"><button class="texty" type="submit">Sign out</button></form></header>'
        . '<main class="hall"><section class="sheet seat"><p class="eyebrow">A table</p><h1>Sit down</h1>'
        . '<form method="post" class="stack">'
        . '<input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="bot">' . $clock
        . '<button class="brass" type="submit">Play MorseBot</button></form>'
        . '<form method="post" class="stack">'
        . '<input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="challenge">'
        . '<label>Challenge a member<input name="opponent" placeholder="Their username" required></label>'
        . $clock
        . '<button class="ink" type="submit">Send the challenge</button></form></section>'
        . '<section class="sheet board-list"><p class="eyebrow">Your games</p><h2>Tables</h2>' . $list . '</section>'
        . '<aside class="sheet standings"><p class="eyebrow">Club</p><h2>Standings</h2><ol>' . $rows . '</ol></aside></main>';
}

function game_view(array $user, array $game): string
{
    $token = h(csrf_token());
    $payload = game_payload($game, (int) $user['id']);
    $json = h(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
    return '<header class="top"><a class="mark" href="index.php">Morse Chess</a>'
        . '<p class="coins"><i>M</i><span>' . (int) $user['coins'] . '</span></p>'
        . '<a class="texty back" href="index.php">Back to the club</a></header>'
        . '<main class="table" data-game="' . $json . '">'
        . '<section class="board-wrap"><div class="plate" id="far-plate"></div><div id="board" class="board"></div><div class="plate" id="near-plate"></div></section>'
        . '<aside class="sheet play-side"><h1 id="result">In play</h1><ol id="moves"></ol>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . $token . '">'
        . '<input type="hidden" name="action" value="resign"><input type="hidden" name="game" value="' . (int) $game['id'] . '">'
        . '<button class="texty" type="submit">Resign</button></form></aside></main>';
}
