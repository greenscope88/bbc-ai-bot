<?php
declare(strict_types=1);

/**
 * Host B native query param names from Swagger (read-only diagnosis).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';

$sno = 'e1fd133c7e8e45a1';
$base = rtrim((string) app_config_get('gateway.host_b.base_url', 'http://103.1.222.11:8080'), '/');
$apiKey = (string) app_config_get('gateway.host_b.api_key', '');

$tests = [
    'B-native' => [
        'Sno' => $sno,
        'Keyword' => '東京',
        'TourDateS' => '2026-06-21',
        'TourDateE' => '2026-06-30',
        'Page' => 1,
        'PageSize' => 10,
    ],
    'C-native' => [
        'Sno' => $sno,
        'Keyword' => '東京',
        'AmountMax' => 30000,
        'Page' => 1,
        'PageSize' => 10,
    ],
    'D-native' => [
        'Sno' => $sno,
        'Keyword' => '東京',
        'TourDateS' => '2026-06-21',
        'TourDateE' => '2026-06-30',
        'AmountMax' => 30000,
        'Page' => 1,
        'PageSize' => 10,
    ],
];

foreach ($tests as $label => $query) {
    $url = $base . '/api/tour/search?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'x-api-key: ' . $apiKey],
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    echo "=== {$label} ===\nURL: {$url}\nHTTP: {$http}\n";
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($decoded)) {
        echo "invalid json\n\n";
        continue;
    }
    $items = $decoded['items'] ?? $decoded['data'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }
    $total = (int) ($decoded['pagination']['total'] ?? $decoded['totalCount'] ?? 0);
    echo 'items=' . count($items) . " total={$total}\n";
    $n = 0;
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '  [' . $n . '] ' . ($row['couponName'] ?? '') . ' | price=' . ($row['price'] ?? '') . ' | tourDate=' . ($row['tourDate'] ?? '') . "\n";
        if (++$n >= 3) {
            break;
        }
    }
    echo "\n";
}
