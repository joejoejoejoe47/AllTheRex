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

    $stmt = $pdo->prepare("SELECT coins, score, unit_roster FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Verify password
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($password, $hash)) {
        echo json_encode(['success' => false, 'message' => 'Authentication failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'coins' => (int)$user['coins'],
        'score' => (int)$user['score'],
        'unitRoster' => json_decode($user['unit_roster'], true)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Load failed']);
}