<?php
declare(strict_types=1);

/**
 * Stage 1-B-12 CLI tests for TourSearchApiClient (mock transport only).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param array<string, mixed> $result
 */
function assert_client_shape(array $result): void
{
    foreach (['success', 'http_status', 'error'] as $key) {
        test_assert(array_key_exists($key, $result), "shape: {$key}");
    }
}

$baseUrl = 'https://bonusmee.com/api/gateway/tour/search.php';
$sno = 'e1fd133c7e8e45a1';
$keyword = '富國島';

// 0. default timeout (Stage 1-B-21)
$defaultClient = new TourSearchApiClient($baseUrl);
$ref = new ReflectionClass(TourSearchApiClient::class);
$timeoutProp = $ref->getProperty('timeoutSeconds');
$timeoutProp->setAccessible(true);
test_assert($timeoutProp->getValue($defaultClient) === TourSearchApiClient::DEFAULT_TIMEOUT_SECONDS, '0: default timeout 20s');
test_assert(TourSearchApiClient::DEFAULT_TIMEOUT_SECONDS === 20, '0: DEFAULT_TIMEOUT_SECONDS constant');

// 1. URL build + urlencode
$client = new TourSearchApiClient($baseUrl);
$url = $client->buildRequestUrl($sno, $keyword, 1, 5, 'trace-abc');
test_assert(strpos($url, $baseUrl . '?') === 0, '1: base url');
test_assert(strpos($url, 'sno=' . rawurlencode($sno)) !== false, '1: sno encoded');
test_assert(strpos($url, 'keyword=' . rawurlencode($keyword)) !== false, '1: keyword encoded');
test_assert(strpos($url, 'page=1') !== false, '1: page');
test_assert(strpos($url, 'pageSize=5') !== false, '1: pageSize');
test_assert(strpos($url, 'traceId=trace-abc') !== false, '1: traceId');

// 2. missing sno / keyword
$r2a = $client->search('', $keyword);
assert_client_shape($r2a);
test_assert(($r2a['success'] ?? true) === false, '2a: missing sno');
test_assert(($r2a['error']['code'] ?? '') === 'CLIENT_MISSING_SNO', '2a: code');

$r2b = $client->search($sno, '   ');
assert_client_shape($r2b);
test_assert(($r2b['error']['code'] ?? '') === 'CLIENT_MISSING_KEYWORD', '2b: missing keyword');

// 3. mock HTTP 200 + success=true
$mock200 = new TourSearchApiClient($baseUrl, 5, static function (string $url, array $headers, int $timeout): array {
    test_assert(isset($headers['X-Trace-Id']) && $headers['X-Trace-Id'] === 'tid-200', '3: X-Trace-Id header');
    test_assert(strpos($url, 'keyword=') !== false, '3: url has keyword');

    $body = json_encode([
        'success' => true,
        'traceId' => 'tid-200',
        'sno' => 'e1fd133c7e8e45a1',
        'keyword' => '富國島',
        'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 2302],
        'items' => [
            ['title' => 'Test Tour', 'price' => 100, 'tourDate' => '2026/05/24'],
        ],
        'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?sno=x',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => $body !== false ? $body : '',
        'transport_error' => null,
    ];
});

$r3 = $mock200->search($sno, $keyword, 1, 5, 'tid-200');
assert_client_shape($r3);
test_assert(($r3['success'] ?? false) === true, '3: success');
test_assert((int) ($r3['http_status'] ?? 0) === 200, '3: http 200');
test_assert(count($r3['items'] ?? []) === 1, '3: one item');
test_assert(($r3['pagination']['total'] ?? 0) === 2302, '3: total');
test_assert($r3['error'] === null, '3: error null');

// 4. mock HTTP 502 + HOSTB_HTTP_DISABLED
$mock502 = new TourSearchApiClient($baseUrl, 5, static function (string $url, array $headers, int $timeout): array {
    $body = json_encode([
        'success' => false,
        'traceId' => 'tid-502',
        'sno' => 'e1fd133c7e8e45a1',
        'keyword' => '富國島',
        'pagination' => null,
        'items' => [],
        'search_url' => 'https://example.com/search',
        'error' => ['code' => 'HOSTB_HTTP_DISABLED', 'message' => 'Host B HTTP is disabled.'],
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 502,
        'body' => $body !== false ? $body : '',
        'transport_error' => null,
    ];
});

$r4 = $mock502->search($sno, $keyword);
assert_client_shape($r4);
test_assert(($r4['success'] ?? true) === false, '4: not success');
test_assert((int) ($r4['http_status'] ?? 0) === 502, '4: http 502');
test_assert(($r4['error']['code'] ?? '') === 'HOSTB_HTTP_DISABLED', '4: error code');

// 5. invalid JSON
$mockBadJson = new TourSearchApiClient($baseUrl, 5, static function (string $url, array $headers, int $timeout): array {
    return [
        'ok' => true,
        'http_status' => 200,
        'body' => '{not-json',
        'transport_error' => null,
    ];
});

$r5 = $mockBadJson->search($sno, $keyword);
test_assert(($r5['error']['code'] ?? '') === 'CLIENT_JSON_PARSE_FAILED', '5: json parse');

// 6. timeout / transport error
$mockTimeout = new TourSearchApiClient($baseUrl, 5, static function (string $url, array $headers, int $timeout): array {
    return [
        'ok' => false,
        'http_status' => 0,
        'body' => '',
        'transport_error' => 'Operation timed out',
    ];
});

$r6 = $mockTimeout->search($sno, $keyword);
test_assert(($r6['error']['code'] ?? '') === 'CLIENT_HTTP_TIMEOUT', '6: timeout code');

// 7. success=true but items=0
$mockEmpty = new TourSearchApiClient($baseUrl, 5, static function (string $url, array $headers, int $timeout) use ($sno, $keyword): array {
    $body = json_encode([
        'success' => true,
        'traceId' => 'tid-empty',
        'sno' => $sno,
        'keyword' => $keyword,
        'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 0],
        'items' => [],
        'search_url' => 'https://example.com/search',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => $body !== false ? $body : '',
        'transport_error' => null,
    ];
});

$r7 = $mockEmpty->search($sno, $keyword);
test_assert(($r7['success'] ?? false) === true, '7: success with zero items');
test_assert(count($r7['items'] ?? []) === 0, '7: items empty');

if ($failures === 0) {
    echo "OK: TourSearchApiClient tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
