<?php
declare(strict_types=1);

/**
 * Read-only Host B filter HTTP comparison (Phase 2-C.3 diagnosis).
 * CLI: php tests/hybrid_search/diagnose_hostb_filter_http.php
 * Does not modify data. Requires GATEWAY_HOSTB_API_KEY in .env.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';

$sno = 'e1fd133c7e8e45a1';
$base = rtrim((string) app_config_get('gateway.host_b.base_url', 'http://103.1.222.11:8080'), '/');
$apiKey = app_config_get('gateway.host_b.api_key', '');
if (!is_string($apiKey) || trim($apiKey) === '') {
    fwrite(STDERR, "NO-GO: GATEWAY_HOSTB_API_KEY missing in config\n");
    exit(2);
}

$tests = [
    'A' => ['keyword' => '東京', 'page' => 1, 'pageSize' => 10],
    'B' => ['keyword' => '東京', 'dateFrom' => '2026-06-21', 'dateTo' => '2026-06-30', 'page' => 1, 'pageSize' => 10],
    'C' => ['keyword' => '東京', 'priceMax' => 30000, 'page' => 1, 'pageSize' => 10],
    'D' => ['keyword' => '東京', 'dateFrom' => '2026-06-21', 'dateTo' => '2026-06-30', 'priceMax' => 30000, 'page' => 1, 'pageSize' => 10],
];

foreach ($tests as $label => $query) {
    $query['sno'] = $sno;
    $url = $base . '/api/tour/search?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'x-api-key: ' . $apiKey,
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    echo "=== Test {$label} ===\n";
    echo "URL: {$url}\n";
    echo "HTTP: {$http}\n";

    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($decoded)) {
        echo "JSON: invalid\n\n";
        continue;
    }

    $items = $decoded['items'] ?? $decoded['data'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }
    $total = 0;
    if (isset($decoded['pagination']['total'])) {
        $total = (int) $decoded['pagination']['total'];
    } elseif (isset($decoded['totalCount'])) {
        $total = (int) $decoded['totalCount'];
    }

    echo 'success=' . (($decoded['success'] ?? false) ? 'true' : 'false') . " items=" . count($items) . " total={$total}\n";

    $n = 0;
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $title = (string) ($row['couponName'] ?? $row['title'] ?? '');
        $price = $row['price'] ?? null;
        $date = (string) ($row['tourDate'] ?? '');
        echo "  [{$n}] {$title} | price={$price} | tourDate={$date}\n";
        if (++$n >= 5) {
            break;
        }
    }
    echo "\n";
}
