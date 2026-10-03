<?php
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$roomCode   = strtoupper(trim($data['roomCode'] ?? ''));
$username   = trim($data['username'] ?? '');
$password   = $data['password'] ?? '';
$gameState  = $data['gameState'] ?? null;
$currentTurn = $data['currentTurn'] ?? null; // 'host' or 'guest'
$status     = $data['status'] ?? null;
$winner     = $data['winner'] ?? null;

if (!$roomCode || !$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

try {
    $pdo = getDB();

    // Verify user
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($password, $hash)) {
        echo json_encode(['success' => false, 'message' => 'Authentication failed']);
        exit;
    }

    // Load room
    $stmt = $pdo->prepare("SELECT * FROM game_rooms WHERE room_code = ?");
    $stmt->execute([$roomCode]);
    $room = $stmt->fetch();

    if (!$room) {
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    // Only host or guest can update
    if ($username !== $room['host_username'] && $username !== $room['guest_username']) {
        echo json_encode(['success' => false, 'message' => 'Not a player in this room']);
        exit;
    }

    $fields = [];
    $params = [];

    if ($gameState !== null) {
        $fields[] = "game_state = ?";
        $params[] = json_encode($gameState);
    }
    if ($currentTurn !== null) {
        $fields[] = "current_turn = ?";
        $params[] = $currentTurn;
    }
    if ($status !== null) {
        $fields[] = "status = ?";
        $params[] = $status;
    }
    if ($winner !== null) {
        $fields[] = "winner = ?";
        $params[] = $winner;
    }

    if (empty($fields)) {
        echo json_encode(['success' => false, 'message' => 'Nothing to update']);
        exit;
    }

    $fields[] = "updated_at = NOW()";
    $params[] = $room['id'];

    $sql = "UPDATE game_rooms SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true, 'message' => 'Game updated']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}
