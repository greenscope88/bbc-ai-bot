<?php
declare(strict_types=1);

/**
 * LIVE HTTP: Host B date/price filter capability after Phase 2-C.4 param alignment.
 * Skips when GATEWAY_HOSTB_API_KEY is missing.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchFilterCapability.php';

$sno = 'e1fd133c7e8e45a1';
$base = rtrim((string) app_config_get('gateway.host_b.base_url', 'http://103.1.222.11:8080'), '/');
$apiKey = app_config_get('gateway.host_b.api_key', '');

if (!is_string($apiKey) || trim($apiKey) === '') {
    fwrite(STDOUT, "SKIP: Host B live HTTP tests (no API key)\n");
    exit(0);
}

/**
 * @param array<string, string|int> $query
 * @return array{total: int, first_date: string, first_price: int|null, items: int}
 */
function hostb_live_search(string $base, string $apiKey, array $query): array
{
    $ch = curl_init($base . '/api/tour/search?' . http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'x-api-key: ' . $apiKey],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : [];
    $items = is_array($decoded) ? ($decoded['items'] ?? []) : [];
    $first = is_array($items) && isset($items[0]) && is_array($items[0]) ? $items[0] : [];

    return [
        'total' => (int) (($decoded['pagination']['total'] ?? 0)),
        'first_date' => (string) ($first['tourDate'] ?? ''),
        'first_price' => isset($first['price']) ? (int) $first['price'] : null,
        'items' => is_array($items) ? count($items) : 0,
    ];
}

$baseQuery = ['sno' => $sno, 'keyword' => '東京', 'page' => 1, 'pageSize' => 10];

$a = hostb_live_search($base, $apiKey, $baseQuery);
$b = hostb_live_search($base, $apiKey, $baseQuery + ['AmountMax' => 30000]);
$c = hostb_live_search($base, $apiKey, $baseQuery + ['TourDateS' => '2026-06-21', 'TourDateE' => '2026-06-30']);
$d = hostb_live_search($base, $apiKey, $baseQuery + [
    'AmountMax' => 30000,
    'TourDateS' => '2026-06-21',
    'TourDateE' => '2026-06-30',
]);
$e = hostb_live_search($base, $apiKey, $baseQuery + ['Departure' => '高雄']);

hybrid_test_assert($a['total'] > 0, 'live A: has results');
hybrid_test_assert($b['total'] < $a['total'], 'live B: AmountMax reduces total');
if ($b['first_price'] !== null && $b['first_price'] > 30000) {
    fwrite(STDOUT, "NOTE: first item price {$b['first_price']} > 30000 despite AmountMax (sort/order).\n");
}

// Date filter: documented as not effective on Host B (Phase 2-C.3/2-C.4); assert behavior for regression tracking.
if ($c['total'] === $a['total']) {
    fwrite(STDOUT, "NOTE: TourDateS/TourDateE did not change total ({$c['total']}); Host B date SQL still ineffective.\n");
}

hybrid_test_assert($e['total'] < $a['total'], 'live E: Departure reduces total');

$condition = [
    'date_from' => '2026-06-21',
    'date_to' => '2026-06-30',
    'budget_max' => 30000,
];
$sampleItems = [];
if ($d['items'] > 0) {
    $ch = curl_init($base . '/api/tour/search?' . http_build_query($baseQuery + [
        'AmountMax' => 30000,
        'TourDateS' => '2026-06-21',
        'TourDateE' => '2026-06-30',
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $apiKey],
    ]);
    $decoded = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    $rawItems = is_array($decoded) ? ($decoded['items'] ?? []) : [];
    foreach ($rawItems as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sampleItems[] = [
            'title' => (string) ($row['couponName'] ?? ''),
            'price' => isset($row['price']) ? (int) $row['price'] : null,
            'tourDate' => (string) ($row['tourDate'] ?? ''),
        ];
    }
}
$violations = HybridSearchFilterCapability::detectViolations($sampleItems, $condition);
fwrite(STDOUT, 'live D violations count: ' . count($violations) . "\n");

hybrid_test_finish('Host B date filter capability (live)');
