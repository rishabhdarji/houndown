<?php
require_once __DIR__ . '/../smart_engine.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$context = hound_client_context();
$context['location_denied'] = !empty($input['location_denied']);
$context['gps_retry_count'] = (int) ($input['gps_retry_count'] ?? 0);
$context['session_id'] = $input['session_id'] ?? null;
$context['bot_name'] = ($context['branding']['bot_name'] ?? 'Admin');

$message = trim($input['message'] ?? '');
if ($message === '') {
    echo json_encode(['reply' => 'Please type a message.']);
    exit;
}

$reply = hound_chat_response($message, $context);

echo json_encode(['reply' => $reply, 'locale' => $context['locale']]);
