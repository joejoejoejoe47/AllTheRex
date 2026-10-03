<?php
header('Content-Type: application/json');

$appId = $_GET['id'] ?? null;
if (!$appId) {
    http_response_code(400);
    echo json_encode(['error' => 'No ID provided']);
    exit;
}

$sanitizedId = preg_replace('/[^a-zA-Z0-9_-]/', '', $appId);
$filePath = __DIR__ . '/saved_apps/' . $sanitizedId . '.json';

if (file_exists($filePath)) {
    echo file_get_contents($filePath);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'App configuration not found']);
}