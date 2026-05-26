<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';

$sno = 'e1fd133c7e8e45a1';
$base = rtrim((string) app_config_get('gateway.host_b.base_url', 'http://103.1.222.11:8080'), '/');
$key = (string) app_config_get('gateway.host_b.api_key', '');

$tests = [
    'A' => ['sno' => $sno, 'keyword' => '東京', 'page' => 1, 'pageSize' => 10],
    'B' => ['sno' => $sno, 'keyword' => '東京', 'AmountMax' => 30000, 'page' => 1, 'pageSize' => 10],
    'C' => ['sno' => $sno, 'keyword' => '東京', 'TourDateS' => '2026-06-21', 'TourDateE' => '2026-06-30', 'page' => 1, 'pageSize' => 10],
    'D' => ['sno' => $sno, 'keyword' => '東京', 'AmountMax' => 30000, 'TourDateS' => '2026-06-21', 'TourDateE' => '2026-06-30', 'page' => 1, 'pageSize' => 10],
    'E' => ['sno' => $sno, 'keyword' => '東京', 'Departure' => '高雄', 'page' => 1, 'pageSize' => 10],
];

foreach ($tests as $label => $q) {
    $url = $base . '/api/tour/search?' . http_build_query($q);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $key],
    ]);
    $d = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    $total = (int) ($d['pagination']['total'] ?? 0);
    $row = is_array($d['items'][0] ?? null) ? $d['items'][0] : [];
    echo "=== {$label} total={$total} ===\n";
    echo '  first: ' . ($row['tourDate'] ?? '') . ' | ' . ($row['price'] ?? '') . ' | ' . mb_substr((string) ($row['couponName'] ?? ''), 0, 45) . "\n\n";
}
