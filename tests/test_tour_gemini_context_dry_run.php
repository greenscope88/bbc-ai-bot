<?php
declare(strict_types=1);

/**
 * Stage 1-B-15 CLI dry-run: Intent → TourSearchApiClient (mock) → GeminiTourContextBuilder.
 * No LINE, no Gemini Live API, no webhook, no live HTTP by default.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_query_intent_detector.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

$failures = 0;
$baseUrl = 'https://bonusmee.com/api/gateway/tour/search.php';
$stagingSno = 'e1fd133c7e8e45a1';

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function assert_no_sensitive_values(string $text, string $label): void
{
    foreach (['SECRET-TRACE-999', 'KEY-LEAK-ABC', 'dep-888-val', 'store-6290-val'] as $value) {
        test_assert(strpos($text, $value) === false, "{$label}: sensitive value leaked: {$value}");
    }
}

/**
 * @return callable(string, array<string, string>, int): array{ok: bool, http_status: int, body: string, transport_error: string|null}
 */
function mockTransportWithData(string $keyword): callable
{
    return static function (string $url, array $headers, int $timeout) use ($keyword): array {
        $body = json_encode([
            'success' => true,
            'traceId' => 'SECRET-TRACE-999',
            'sno' => 'e1fd133c7e8e45a1',
            'keyword' => $keyword,
            'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 12],
            'items' => [
                [
                    'title' => '東京迪士尼親子五日',
                    'tourDate' => '2026-07-10',
                    'stock' => 8,
                    'price' => 39900,
                    'tradeRefund' => 3000,
                    'depID' => 'dep-888-val',
                    'api_key' => 'KEY-LEAK-ABC',
                ],
                [
                    'title' => '東京富士山溫泉五日',
                    'tourDate' => '2026-07-18',
                    'stock' => 12,
                    'price' => 42900,
                    'tradeRefund' => 3500,
                ],
            ],
            'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=' . rawurlencode($keyword),
            'error' => null,
        ], JSON_UNESCAPED_UNICODE);

        return [
            'ok' => true,
            'http_status' => 200,
            'body' => $body !== false ? $body : '',
            'transport_error' => null,
        ];
    };
}

/**
 * @return callable(string, array<string, string>, int): array{ok: bool, http_status: int, body: string, transport_error: string|null}
 */
function mockTransportEmpty(): callable
{
    return static function (string $url, array $headers, int $timeout): array {
        $body = json_encode([
            'success' => true,
            'traceId' => 'SECRET-TRACE-999',
            'sno' => 'e1fd133c7e8e45a1',
            'keyword' => '東京',
            'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 0],
            'items' => [],
            'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=%E6%9D%B1%E4%BA%AC',
            'error' => null,
        ], JSON_UNESCAPED_UNICODE);

        return [
            'ok' => true,
            'http_status' => 200,
            'body' => $body !== false ? $body : '',
            'transport_error' => null,
        ];
    };
}

/**
 * @return callable(string, array<string, string>, int): array{ok: bool, http_status: int, body: string, transport_error: string|null}
 */
function mockTransportManyItems(): callable
{
    return static function (string $url, array $headers, int $timeout): array {
        $items = [];
        for ($i = 1; $i <= 7; ++$i) {
            $items[] = [
                'title' => "行程第{$i}筆",
                'tourDate' => '2026-08-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'price' => 10000 + $i,
            ];
        }

        $body = json_encode([
            'success' => true,
            'traceId' => 'SECRET-TRACE-999',
            'sno' => 'e1fd133c7e8e45a1',
            'keyword' => '東京',
            'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 7],
            'items' => $items,
            'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=%E6%9D%B1%E4%BA%AC',
            'error' => null,
        ], JSON_UNESCAPED_UNICODE);

        return [
            'ok' => true,
            'http_status' => 200,
            'body' => $body !== false ? $body : '',
            'transport_error' => null,
        ];
    };
}

/**
 * @return array{intent: array<string, mixed>, api_skipped: bool, api_result: array<string, mixed>|null, context: string|null}
 */
function runPipeline(
    string $message,
    TourQueryIntentDetector $detector,
    TourSearchApiClient $clientEmpty,
    GeminiTourContextBuilder $builder,
    string $emptyKeywordTrigger = ''
): array {
    $intent = $detector->detect($message);
    $apiSkipped = true;
    $apiResult = null;
    $context = null;

    if (($intent['is_tour_query'] ?? false) === true) {
        $keyword = is_string($intent['keyword'] ?? null) ? trim((string) $intent['keyword']) : '';
        if ($keyword === '') {
            return [
                'intent' => $intent,
                'api_skipped' => true,
                'api_result' => null,
                'context' => null,
            ];
        }

        $apiSkipped = false;
        if ($keyword === $emptyKeywordTrigger) {
            $client = $clientEmpty;
        } else {
            $client = new TourSearchApiClient($GLOBALS['baseUrl'], 5, mockTransportWithData($keyword));
        }

        $apiResult = $client->search($GLOBALS['stagingSno'], $keyword, 1, 5);
        $context = $builder->build($apiResult);
    }

    return [
        'intent' => $intent,
        'api_skipped' => $apiSkipped,
        'api_result' => $apiResult,
        'context' => $context,
    ];
}

function printDryRun(string $message, array $pipeline): void
{
    echo "=== Tour Gemini Context Dry Run ===\n\n";
    echo "User Message:\n{$message}\n\n";

    $intent = $pipeline['intent'];
    echo "Intent:\n";
    echo 'is_tour_query = ' . (($intent['is_tour_query'] ?? false) ? 'true' : 'false') . "\n";
    echo 'keyword = ' . ($intent['keyword'] ?? 'null') . "\n";
    echo 'confidence = ' . ($intent['confidence'] ?? 'n/a') . "\n";
    echo 'reason = ' . ($intent['reason'] ?? 'n/a') . "\n\n";

    if ($pipeline['api_skipped']) {
        echo "TourSearchApiClient: skipped (not a tour query or no keyword)\n\n";
        echo "Gemini Context Preview:\n(n/a)\n\n";
        return;
    }

    echo "TourSearchApiClient: mock transport (no live HTTP)\n\n";
    echo "Gemini Context Preview:\n";
    echo ($pipeline['context'] ?? '') . "\n\n";
}

// --- Interactive-style dry-run samples ---
$detector = new TourQueryIntentDetector();
$builder = new GeminiTourContextBuilder();
$clientEmpty = new TourSearchApiClient($baseUrl, 5, mockTransportEmpty());
$clientMany = new TourSearchApiClient($baseUrl, 5, mockTransportManyItems());

$GLOBALS['baseUrl'] = $baseUrl;
$GLOBALS['stagingSno'] = $stagingSno;

$demoMessages = [
    '我想找東京行程',
    '有沒有大阪五日遊',
    '請推薦歐洲旅遊',
    '你好',
];

foreach ($demoMessages as $demoMessage) {
    $pipeline = runPipeline($demoMessage, $detector, $clientEmpty, $builder);
    printDryRun($demoMessage, $pipeline);
}

// --- Automated assertions (cases 1–6) ---

// 1. 東京旅遊查詢 → Gemini context
$p1 = runPipeline('我想找東京行程', $detector, $clientEmpty, $builder);
test_assert(($p1['intent']['is_tour_query'] ?? false) === true, '1: is_tour_query');
test_assert(($p1['intent']['keyword'] ?? '') === '東京', '1: keyword 東京');
test_assert($p1['api_skipped'] === false, '1: api not skipped');
test_assert(is_string($p1['context']) && strpos($p1['context'], '【旅遊產品搜尋結果】') !== false, '1: context header');
test_assert(strpos((string) $p1['context'], '東京迪士尼親子五日') !== false, '1: tour title');
assert_no_sensitive_values((string) $p1['context'], '1');

// 2. 非旅遊查詢 → skip API
$p2 = runPipeline('你好', $detector, $clientEmpty, $builder);
test_assert(($p2['intent']['is_tour_query'] ?? true) === false, '2: not tour');
test_assert($p2['api_skipped'] === true, '2: api skipped');
test_assert($p2['context'] === null, '2: no context');

// 3. mock 有資料 → total + 行程名稱 + search_url
$ctx3 = (string) ($p1['context'] ?? '');
test_assert(strpos($ctx3, '共找到 12 筆') !== false, '3: total');
test_assert(strpos($ctx3, '東京迪士尼親子五日') !== false, '3: title');
test_assert(strpos($ctx3, 'bonusmee.com/view/cloud') !== false, '3: search_url');

// 4. mock 無資料
$p4 = runPipeline('我想找東京行程', $detector, $clientEmpty, $builder, '東京');
test_assert(strpos((string) $p4['context'], '目前沒有找到符合條件的行程') !== false, '4: empty message');

// 5. 敏感欄位值不得出現在 context（mock 內嵌 traceId / api_key / depID 等）
assert_no_sensitive_values($ctx3, '5');

// 6. maxItems 預設 5 筆
$api6 = $clientMany->search($stagingSno, '東京', 1, 5);
$ctx6 = $builder->build($api6);
test_assert(substr_count($ctx6, '行程名稱：') === 5, '6: at most 5 items listed');
test_assert(strpos($ctx6, '行程第6筆') === false, '6: sixth item excluded');
assert_no_sensitive_values($ctx6, '6');

if ($failures === 0) {
    echo "OK: Tour Gemini Context dry-run passed ({$failures} failures).\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
