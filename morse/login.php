<?php
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

if (!$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'Username and password required']);
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, username, password_hash, coins, score, unit_roster FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'coins' => (int)$user['coins'],
            'score' => (int)$user['score'],
            'unitRoster' => json_decode($user['unit_roster'], true)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Login failed']);
}