<?php
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';   // we re-verify for security
$coins    = $data['coins'] ?? null;
$score    = $data['score'] ?? null;
$roster   = $data['unitRoster'] ?? null;

if (!$username || !$password || $coins === null || $score === null || !$roster) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

try {
    $pdo = getDB();

    // Verify the user first
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Authentication failed']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE users 
        SET coins = ?, score = ?, unit_roster = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        (int)$coins,
        (int)$score,
        json_encode($roster),
        $user['id']
    ]);

    echo json_encode(['success' => true, 'message' => 'Progress saved']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Save failed']);
}