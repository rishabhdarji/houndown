<?php
require_once __DIR__ . '/../smart_engine.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    echo json_encode(['sessions' => hound_list_sessions()], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'analytics') {
    echo json_encode(hound_read_json('analytics.json'), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'session') {
    $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $path = hound_data_dir() . '/sessions/' . $sessionId . '.json';
    if (!file_exists($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }
    echo file_get_contents($path);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid_action']);
