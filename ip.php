<?php
require_once __DIR__ . '/capture_helpers.php';

$ip = getClientIp();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$context = getServerContext();
$context['ip_enrichment'] = enrichIp($ip);

$file = 'ip.txt';
$fp = fopen($file, 'a');
if ($fp) {
    fwrite($fp, 'IP: ' . $ip . ' User-Agent: ' . $userAgent . "\r\n");
    fwrite($fp, 'EXTRA: ' . json_encode($context, JSON_UNESCAPED_SLASHES) . "\r\n");
    fclose($fp);
}
