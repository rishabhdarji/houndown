<?php

function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return trim(explode(',', $_SERVER['HTTP_CLIENT_IP'])[0]);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function getServerHeaders() {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

function getServerContext() {
    $ip = getClientIp();
    $context = [
        'server_ip' => $ip,
        'server_ipv6' => $_SERVER['REMOTE_ADDR'] ?? null,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'request_time' => date('c', $_SERVER['REQUEST_TIME'] ?? time()),
        'server_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? null,
        'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
        'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null,
        'referer' => $_SERVER['HTTP_REFERER'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'client_hints' => [
            'sec_ch_ua' => $_SERVER['HTTP_SEC_CH_UA'] ?? null,
            'sec_ch_ua_mobile' => $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? null,
            'sec_ch_ua_platform' => $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? null,
            'sec_ch_ua_full_version_list' => $_SERVER['HTTP_SEC_CH_UA_FULL_VERSION_LIST'] ?? null,
        ],
        'headers' => getServerHeaders(),
        'reverse_dns' => @gethostbyaddr($ip) ?: null,
    ];

    if ($context['reverse_dns'] === $ip) {
        $context['reverse_dns'] = null;
    }

    return $context;
}

function enrichIp($ip) {
    if (!$ip || $ip === 'unknown' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,message,continent,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org,as,asname,mobile,proxy,hosting,query';
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);
    return ($data && ($data['status'] ?? '') === 'success') ? $data : null;
}

function buildMapLink($lat, $lon) {
    if ($lat === null || $lon === null) {
        return null;
    }
    return 'https://www.openstreetmap.org/?mlat=' . urlencode($lat) . '&mlon=' . urlencode($lon) . '#map=16/' . urlencode($lat) . '/' . urlencode($lon);
}
