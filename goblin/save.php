<?php
// CORS & Output Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Read raw JSON input
$jsonInput = file_get_contents('php://input');

if (!$jsonInput) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No JSON data received']);
    exit;
}

$data = json_decode($jsonInput, true);

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON structure']);
    exit;
}

// Target directory
$dir = __DIR__ . '/saved_apps';

// Verify directory exists
if (!is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'The saved_apps folder does not exist. Please create it manually in Plesk File Manager.']);
    exit;
}

// Sanitize App ID for filename
$appId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['id']);
$filePath = $dir . '/' . $appId . '.json';

// Write file directly
if (@file_put_contents($filePath, $jsonInput) !== false) {
    echo json_encode(['status' => 'success', 'id' => $appId]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Permission denied: Could not write file inside saved_apps folder. Check folder permissions in Plesk.']);
}