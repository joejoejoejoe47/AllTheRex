<?php
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$roomCode = strtoupper(trim($data['roomCode'] ?? ''));

if (!$roomCode) {
    echo json_encode(['success' => false, 'message' => 'Room code required']);
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM game_rooms WHERE room_code = ?");
    $stmt->execute([$roomCode]);
    $room = $stmt->fetch();

    if (!$room) {
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'room' => [
            'roomCode' => $room['room_code'],
            'host' => $room['host_username'],
            'guest' => $room['guest_username'],
            'status' => $room['status'],
            'currentTurn' => $room['current_turn'],
            'gameState' => $room['game_state'] ? json_decode($room['game_state'], true) : null,
            'winner' => $room['winner']
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not load game']);
}
