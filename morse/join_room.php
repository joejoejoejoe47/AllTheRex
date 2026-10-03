<?php
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$roomCode     = strtoupper(trim($data['roomCode'] ?? ''));
$guestUsername = trim($data['username'] ?? '');
$password     = $data['password'] ?? '';

if (!$roomCode || !$guestUsername || !$password) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

try {
    $pdo = getDB();

    // Verify guest account
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$guestUsername]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }

    // Find room
    $stmt = $pdo->prepare("SELECT * FROM game_rooms WHERE room_code = ?");
    $stmt->execute([$roomCode]);
    $room = $stmt->fetch();

    if (!$room) {
        echo json_encode(['success' => false, 'message' => 'Room not found']);
        exit;
    }

    if ($room['status'] !== 'waiting') {
        echo json_encode(['success' => false, 'message' => 'Room is not available']);
        exit;
    }

    if ($room['host_username'] === $guestUsername) {
        echo json_encode(['success' => false, 'message' => 'You cannot join your own room']);
        exit;
    }

    // Join the room
    $stmt = $pdo->prepare("
        UPDATE game_rooms 
        SET guest_username = ?, status = 'playing', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$guestUsername, $room['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Joined room',
        'room' => [
            'roomCode' => $roomCode,
            'host' => $room['host_username'],
            'guest' => $guestUsername,
            'currentTurn' => 'host'
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not join room']);
}
