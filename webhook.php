<?php
require_once __DIR__ . '/smart_engine.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = ['raw_body' => $raw];
}

$record = array_merge(
    [
        'received_at' => date('c'),
        'event_type' => $payload['event_type'] ?? 'unknown',
        'session_id' => $payload['session_id'] ?? null,
        'campaign_id' => $payload['link_variant'] ?? $payload['campaign_id'] ?? null,
    ],
    $payload,
    ['server' => getServerContext()]
);

$ip = getClientIp();
$record['server']['ip_enrichment'] = enrichIp($ip);

$coords = $payload['coordinates'] ?? $payload['location'] ?? null;
if (is_array($coords) && isset($coords['latitude'], $coords['longitude'])) {
    $record['map_link'] = buildMapLink($coords['latitude'], $coords['longitude']);
} elseif (isset($payload['latitude'], $payload['longitude'])) {
    $record['map_link'] = buildMapLink($payload['latitude'], $payload['longitude']);
} elseif (!empty($record['server']['ip_enrichment']['lat']) && !empty($record['server']['ip_enrichment']['lon'])) {
    $enrichment = $record['server']['ip_enrichment'];
    $record['map_link'] = buildMapLink($enrichment['lat'], $enrichment['lon']);
    $record['ip_geolocation_fallback'] = [
        'latitude' => $enrichment['lat'],
        'longitude' => $enrichment['lon'],
        'city' => $enrichment['city'] ?? null,
        'region' => $enrichment['regionName'] ?? null,
        'country' => $enrichment['country'] ?? null,
        'isp' => $enrichment['isp'] ?? null,
        'proxy' => $enrichment['proxy'] ?? null,
        'hosting' => $enrichment['hosting'] ?? null,
    ];
}

$record = hound_save_event($record);

echo json_encode(['ok' => true, 'human_score' => $record['human_score'] ?? null, 'risk_score' => $record['risk_score'] ?? null]);
