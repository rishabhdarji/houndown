<?php
require_once __DIR__ . '/../smart_engine.php';
require_once __DIR__ . '/../consent/policy.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST is required']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || ($payload['consent'] ?? false) !== true || !is_array($payload['data'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Explicit consent and a data preview are required']);
    exit;
}

$data = $payload['data'];
$consentVersion = (string) ($data['consent_version'] ?? '');
if ($consentVersion !== HOUND_CONSENT_VERSION) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'The consent notice is outdated']);
    exit;
}

$submittedLocation = is_array($data['location'] ?? null) ? $data['location'] : [];
$browser = is_array($data['browser'] ?? null) ? $data['browser'] : [];
$latitude = $submittedLocation['latitude'] ?? null;
$longitude = $submittedLocation['longitude'] ?? null;

if (($submittedLocation['available'] ?? false) && (
    !is_numeric($latitude) || !is_numeric($longitude) ||
    (float) $latitude < -90 || (float) $latitude > 90 ||
    (float) $longitude < -180 || (float) $longitude > 180
)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Location coordinates are invalid']);
    exit;
}

$location = [
    'available' => !empty($submittedLocation['available']),
    'reason' => substr((string) ($submittedLocation['reason'] ?? ''), 0, 256)
];
if ($location['available']) {
    $location['latitude'] = (float) $latitude;
    $location['longitude'] = (float) $longitude;
    foreach ([
        'accuracy_meters', 'altitude_meters', 'altitude_accuracy_meters',
        'heading_degrees', 'speed_meters_per_second'
    ] as $field) {
        if (isset($submittedLocation[$field]) && is_numeric($submittedLocation[$field])) {
            $location[$field] = (float) $submittedLocation[$field];
        }
    }
    $location['timestamp'] = substr((string) ($submittedLocation['timestamp'] ?? ''), 0, 64);
}

$sessionId = bin2hex(random_bytes(12));
$record = [
    'event_type' => 'consented_data_submission',
    'session_id' => $sessionId,
    'received_at' => date('c'),
    'consent' => true,
    'consent_version' => substr((string) ($data['consent_version'] ?? ''), 0, 32),
    'location' => $location,
    'browser' => [
        'user_agent' => substr((string) ($browser['user_agent'] ?? ''), 0, 1024),
        'language' => substr((string) ($browser['language'] ?? ''), 0, 32),
        'languages' => is_array($browser['languages'] ?? null) ? array_slice($browser['languages'], 0, 20) : [],
        'timezone' => substr((string) ($browser['timezone'] ?? ''), 0, 128),
        'platform' => substr((string) ($browser['platform'] ?? ''), 0, 128),
        'screen' => is_array($browser['screen'] ?? null) ? $browser['screen'] : [],
        'touch_points' => (int) ($browser['touch_points'] ?? 0),
        'logical_cpu_count' => isset($browser['logical_cpu_count']) ? (int) $browser['logical_cpu_count'] : null,
        'device_memory_gb' => isset($browser['device_memory_gb']) ? (float) $browser['device_memory_gb'] : null
    ],
];

if (!empty($location['available'])) {
    $record['coordinates'] = [
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude,
        'accuracy' => isset($location['accuracy_meters']) ? (float) $location['accuracy_meters'] : null
    ];
    $record['map_link'] = buildMapLink($record['coordinates']['latitude'], $record['coordinates']['longitude']);
}

hound_save_event($record);

echo json_encode(['ok' => true, 'session_id' => $sessionId]);
