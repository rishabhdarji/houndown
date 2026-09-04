<?php
require_once __DIR__ . '/../smart_engine.php';

$sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
$path = hound_data_dir() . '/sessions/' . $sessionId . '.json';

if (!$sessionId || !file_exists($path)) {
    http_response_code(404);
    echo 'Session not found';
    exit;
}

$session = json_decode(file_get_contents($path), true);
if (!is_array($session)) {
    $session = [];
}
$coords = $session['coordinates'] ?? null;
$address = is_array($session['address'] ?? null) ? ($session['address']['display_name'] ?? null) : null;
$ip = is_array($session['server'] ?? null) ? ($session['server']['server_ip'] ?? 'n/a') : 'n/a';
$map = $session['map_link'] ?? '#';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Hound Report - <?= htmlspecialchars($sessionId) ?></title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; color: #222; }
    h1, h2 { margin-bottom: 8px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; }
    pre { background: #f7f7f7; padding: 12px; overflow: auto; }
    @media print { .no-print { display: none; } }
  </style>
</head>
<body>
  <button class="no-print" onclick="window.print()">Print / Save PDF</button>
  <h1>Hound Session Report</h1>
  <p><strong>Session:</strong> <?= htmlspecialchars($sessionId) ?></p>
  <p><strong>Updated:</strong> <?= htmlspecialchars($session['updated_at'] ?? 'n/a') ?></p>
  <div class="grid">
    <div class="card">
      <h2>Location</h2>
      <?php if ($coords): ?>
        <p>Lat: <?= htmlspecialchars($coords['latitude'] ?? '') ?></p>
        <p>Lon: <?= htmlspecialchars($coords['longitude'] ?? '') ?></p>
        <p>Accuracy: <?= htmlspecialchars($coords['accuracy'] ?? '') ?> m</p>
        <p>Address: <?= htmlspecialchars($address ?? 'n/a') ?></p>
        <p><a href="<?= htmlspecialchars($map) ?>">Open map</a></p>
      <?php else: ?>
        <p>No GPS captured</p>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Scores</h2>
      <p>Human score: <?= htmlspecialchars($session['human_score'] ?? 'n/a') ?></p>
      <p>Risk score: <?= htmlspecialchars($session['risk_score'] ?? 'n/a') ?></p>
      <p>IP: <?= htmlspecialchars($ip) ?></p>
    </div>
  </div>
  <div class="card">
    <h2>Chat log</h2>
    <pre><?= htmlspecialchars(json_encode($session['chat_log'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>
  <div class="card">
    <h2>Full session JSON</h2>
    <pre><?= htmlspecialchars(json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>
</body>
</html>
