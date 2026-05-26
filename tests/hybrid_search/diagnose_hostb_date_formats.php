<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';

$sno = 'e1fd133c7e8e45a1';
$base = rtrim((string) app_config_get('gateway.host_b.base_url', 'http://103.1.222.11:8080'), '/');
$apiKey = (string) app_config_get('gateway.host_b.api_key', '');

$tests = [
    'date-only-native' => ['Sno' => $sno, 'Keyword' => '東京', 'TourDateS' => '2026-06-21', 'TourDateE' => '2026-06-30', 'Page' => 1, 'PageSize' => 5],
    'date-slash' => ['Sno' => $sno, 'Keyword' => '東京', 'TourDateS' => '2026/06/21', 'TourDateE' => '2026/06/30', 'Page' => 1, 'PageSize' => 5],
    'date-compact' => ['Sno' => $sno, 'Keyword' => '東京', 'TourDateS' => '20260621', 'TourDateE' => '20260630', 'Page' => 1, 'PageSize' => 5],
    'amount-only' => ['Sno' => $sno, 'Keyword' => '東京', 'AmountMax' => 30000, 'Page' => 1, 'PageSize' => 5],
];

foreach ($tests as $label => $query) {
    $url = $base . '/api/tour/search?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'x-api-key: ' . $apiKey],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : [];
    $items = is_array($decoded) ? ($decoded['items'] ?? []) : [];
    $total = is_array($decoded) ? (int) ($decoded['pagination']['total'] ?? 0) : 0;
    echo "=== {$label} total={$total} ===\n";
    foreach (array_slice(is_array($items) ? $items : [], 0, 3) as $i => $row) {
        echo "  [{$i}] price=" . ($row['price'] ?? '') . ' tourDate=' . ($row['tourDate'] ?? '') . "\n";
    }
    echo "\n";
}
