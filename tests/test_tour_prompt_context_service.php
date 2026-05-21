<?php
declare(strict_types=1);

/**
 * Stage 1-B-17 CLI tests for TourPromptContextService (mock transport only).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'ai_prompt_builder.php';

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
        test_assert(strpos($text, $value) === false, "{$label}: leaked {$value}");
    }
}

/**
 * @return array{client: TourSearchApiClient, called: bool}
 */
function recordLastTourSearchUrl(string $url): void
{
    $q = [];
    $query = parse_url($url, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        parse_str($query, $q);
    }
    $GLOBALS['__tour_prompt_last_query'] = $q;
}

function mockClientWithData(string $keyword): array
{
    $called = false;
    $client = new TourSearchApiClient($GLOBALS['baseUrl'], 5, static function (string $url, array $headers, int $timeout) use ($keyword, &$called): array {
        $called = true;
        recordLastTourSearchUrl($url);
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
                    'price' => 39900,
                    'depID' => 'dep-888-val',
                    'api_key' => 'KEY-LEAK-ABC',
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
    });

    return ['client' => $client, 'called' => &$called];
}

/**
 * @return array{client: TourSearchApiClient, called: bool}
 */
function mockClientEmpty(): array
{
    $called = false;
    $client = new TourSearchApiClient($GLOBALS['baseUrl'], 5, static function (string $url, array $headers, int $timeout) use (&$called): array {
        $called = true;
        recordLastTourSearchUrl($url);
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
    });

    return ['client' => $client, 'called' => &$called];
}

/**
 * @return array{client: TourSearchApiClient, called: bool}
 */
function mockClientManyItems(): array
{
    $called = false;
    $client = new TourSearchApiClient($GLOBALS['baseUrl'], 5, static function (string $url, array $headers, int $timeout) use (&$called): array {
        $called = true;
        recordLastTourSearchUrl($url);
        $items = [];
        for ($i = 1; $i <= 7; ++$i) {
            $items[] = ['title' => "行程第{$i}筆", 'tourDate' => '2026-08-01', 'price' => 10000 + $i];
        }

        $body = json_encode([
            'success' => true,
            'pagination' => ['total' => 7],
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
    });

    return ['client' => $client, 'called' => &$called];
}

$service = new TourPromptContextService();
$GLOBALS['baseUrl'] = $baseUrl;
$GLOBALS['__tour_prompt_last_query'] = [];

// 1. feature flag off
$mockOff = mockClientWithData('東京');
$ctx1 = $service->buildTourContextForPrompt([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => false,
    'searchClient' => $mockOff['client'],
]);
test_assert($ctx1 === '', '1: empty when flag off');
test_assert($mockOff['called'] === false, '1: search client not called');

// 2. non-tour query
$mockHello = mockClientWithData('東京');
$ctx2 = $service->buildTourContextForPrompt([
    'userText' => '你好',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $mockHello['client'],
]);
test_assert($ctx2 === '', '2: empty for greeting');
test_assert($mockHello['called'] === false, '2: search client not called');

// 3. tour + mock data
$mockData = mockClientWithData('東京');
$ctx3 = $service->buildTourContextForPrompt([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $mockData['client'],
]);
test_assert($mockData['called'] === true, '3: search client called');
test_assert((string) ($GLOBALS['__tour_prompt_last_query']['pageSize'] ?? '') === '30', '3: api pageSize 30');
test_assert(strpos($ctx3, '東京迪士尼親子五日') !== false, '3: tour title');
test_assert(strpos($ctx3, 'bonusmee.com/view/cloud') !== false, '3: search_url');
assert_no_sensitive_values($ctx3, '3');

// 4. empty API result
$mockEmpty = mockClientEmpty();
$ctx4 = $service->buildTourContextForPrompt([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $mockEmpty['client'],
]);
test_assert(strpos($ctx4, '目前沒有找到符合條件的行程') !== false, '4: empty result message');

// 5. search client throws (injected stub; TourSearchApiClient swallows transport errors internally)
$throwClient = new class {
    public function search(string $sno, string $keyword, int $page = 1, int $pageSize = 5, ?string $traceId = null): array
    {
        throw new RuntimeException('mock search failure');
    }
};
$ctx5 = $service->buildTourContextForPrompt([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $throwClient,
]);
test_assert($ctx5 === '', '5: empty on exception');

// 6. sensitive values (covered in 3)
assert_no_sensitive_values($ctx3, '6');

// 7. maxItems
$mockMany = mockClientManyItems();
$ctx7 = $service->buildTourContextForPrompt([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'maxItems' => 5,
    'searchClient' => $mockMany['client'],
]);
test_assert((string) ($GLOBALS['__tour_prompt_last_query']['pageSize'] ?? '') === '30', '7: api pageSize 30');
test_assert(preg_match_all('/^\d+\.\s+/mu', $ctx7) === 5, '7: max 5 merged display rows');
test_assert(strpos($ctx7, '行程第6筆') === false, '7: sixth excluded');

// 8. appendTourContext integration
$basePrompt = '你是測試旅行社 LINE 客服。';
$merged = AiPromptBuilder::appendTourContext($basePrompt, $ctx3);
test_assert(strpos($merged, $basePrompt) === 0, '8: base preserved');
test_assert(strpos($merged, '以下為系統自動整理的旅遊商品搜尋結果資訊') !== false, '8: merge header');
test_assert(strpos($merged, '請嚴格遵守（回覆給客人時）：') !== false, '8: merge rules');

if ($failures === 0) {
    echo "OK: TourPromptContextService tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
