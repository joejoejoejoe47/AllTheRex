<?php
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$hostUsername = trim($data['username'] ?? '');
$password     = $data['password'] ?? '';

if (!$hostUsername || !$password) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

try {
    $pdo = getDB();

    // Verify host account
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$hostUsername]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }

    // Generate short room code
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $roomCode = '';
    for ($i = 0; $i < 6; $i++) {
        $roomCode .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $stmt = $pdo->prepare("
        INSERT INTO game_rooms (room_code, host_username, status, current_turn)
        VALUES (?, ?, 'waiting', 'host')
    ");
    $stmt->execute([$roomCode, $hostUsername]);

    echo json_encode([
        'success' => true,
        'roomCode' => $roomCode,
        'message' => 'Room created'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not create room']);
}
