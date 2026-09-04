<?php
require_once __DIR__ . '/../smart_engine.php';

header('Content-Type: application/json');
echo json_encode(hound_client_context(), JSON_UNESCAPED_SLASHES);
