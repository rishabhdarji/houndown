<?php
$data = $_GET['data'] ?? '';
if ($data === '') {
    $data = 'https://example.com';
}

$size = 200;
$encoded = rawurlencode($data);
$imageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . $encoded;

$image = @file_get_contents($imageUrl);
if ($image) {
    header('Content-Type: image/png');
    echo $image;
    exit;
}

header('Content-Type: image/svg+xml');
echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><text x="10" y="100">QR unavailable</text></svg>';
