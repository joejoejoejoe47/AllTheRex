<?php
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

if (strlen($username) < 3 || strlen($username) > 50) {
    echo json_encode(['success' => false, 'message' => 'Username must be 3–50 characters']);
    exit;
}

if (strlen($password) < 4) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 4 characters']);
    exit;
}

try {
    $pdo = getDB();

    // Check if username already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username already taken']);
        exit;
    }

    // Default starting roster (you can expand this later)
    $defaultRoster = [
        'easy' => [],
        'medium' => [],
        'hard' => [],
        'legendary' => []
    ];

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (username, password_hash, coins, score, unit_roster)
        VALUES (?, ?, 1500, 0, ?)
    ");
    $stmt->execute([
        $username,
        $passwordHash,
        json_encode($defaultRoster)
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully',
        'user' => [
            'username' => $username,
            'coins' => 1500,
            'score' => 0
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Registration failed']);
}