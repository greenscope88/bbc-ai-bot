<?php
declare(strict_types=1);

/**
 * Stage 1-B-17 CLI tests for TourPromptContextService (mock transport only).
 * Gemini-authoritative path: buildTourContextResult requires authoritativeIntent.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'ai_prompt_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

$failures = 0;
$baseUrl = 'https://bonusmee.com/api/gateway/tour/search.php';
$stagingSno = 'e1fd133c7e8e45a1';
$structuredRef = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$aiuRuntime = AiIntentUnderstandingRuntime::createForTesting();
$translator = new AiuProductIntentTranslator();
$aiuCtx = [
    'conversation_id' => $stagingSno . ':line:U-tour-prompt-test',
    'tenant_sno' => $stagingSno,
    'now' => $structuredRef,
    'reference_date' => $structuredRef,
];

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
 * @return array{client: TourSearchApiClient, called: bool, lastPageSize: int|null}
 */
function mockClientWithData(string $keyword): array
{
    $called = false;
    $lastPageSize = null;
    $client = new TourSearchApiClient($GLOBALS['baseUrl'], 5, static function (string $url, array $headers, int $timeout) use ($keyword, &$called, &$lastPageSize): array {
        $called = true;
        $query = [];
        $queryString = parse_url($url, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }
        $lastPageSize = isset($query['pageSize']) ? (int) $query['pageSize'] : null;

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

    return ['client' => $client, 'called' => &$called, 'lastPageSize' => &$lastPageSize];
}

/**
 * @return array{client: TourSearchApiClient, called: bool}
 */
function mockClientEmpty(): array
{
    $called = false;
    $client = new TourSearchApiClient($GLOBALS['baseUrl'], 5, static function (string $url, array $headers, int $timeout) use (&$called): array {
        $called = true;
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
 * @return array{client: TourSearchApiClient, called: bool, lastPageSize: int|null}
 */
function mockClientManyItems(): array
{
    $called = false;
    $lastPageSize = null;
    $client = new TourSearchApiClient($GLOBALS['baseUrl'], 5, static function (string $url, array $headers, int $timeout) use (&$called, &$lastPageSize): array {
        $called = true;
        $query = [];
        $queryString = parse_url($url, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }
        $lastPageSize = isset($query['pageSize']) ? (int) $query['pageSize'] : null;

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

    return ['client' => $client, 'called' => &$called, 'lastPageSize' => &$lastPageSize];
}

$service = new TourPromptContextService();
$GLOBALS['baseUrl'] = $baseUrl;

$tokyoIntent = $translator->translate($aiuRuntime->understand('東京 7月', $aiuCtx));
$hokkaidoClarifyIntent = $translator->translate($aiuRuntime->understand('北海道', $aiuCtx));
$hokkaidoSearchIntent = $translator->translate($aiuRuntime->understand('北海道7月', $aiuCtx));

// 3. authoritative tour + mock data
$mockData = mockClientWithData('東京');
$result3 = $service->buildTourContextResult([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $mockData['client'],
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $tokyoIntent,
]);
$ctx3 = $result3->getLegacyContext();
test_assert($mockData['called'] === true, '3: search client called');
test_assert($mockData['lastPageSize'] === 30, '3: api pageSize 30');
test_assert(strpos($ctx3, '東京迪士尼親子五日') !== false, '3: tour title');
test_assert(strpos($ctx3, GeminiTourContextBuilder::SEARCH_URL_LABEL) !== false, '3: search_url label');
assert_no_sensitive_values($ctx3, '3');

// 4. empty API result
$mockEmpty = mockClientEmpty();
$result4 = $service->buildTourContextResult([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $mockEmpty['client'],
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $tokyoIntent,
]);
$ctx4 = $result4->getLegacyContext();
test_assert(strpos($ctx4, '目前沒有找到符合條件的行程') !== false, '4: empty result message');

// 5. search client throws
$throwClient = new TourSearchApiClient($baseUrl, 5, static function (): array {
    throw new RuntimeException('mock search failure');
});
$result5 = $service->buildTourContextResult([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $throwClient,
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $tokyoIntent,
]);
test_assert($result5->getLegacyContext() === '', '5: empty on exception');

// 6. sensitive values (covered in 3)
assert_no_sensitive_values($ctx3, '6');

// 7. maxItems
$mockMany = mockClientManyItems();
$result7 = $service->buildTourContextResult([
    'userText' => '我想找東京行程',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'maxItems' => 5,
    'searchClient' => $mockMany['client'],
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $tokyoIntent,
]);
$ctx7 = $result7->getLegacyContext();
test_assert($mockMany['lastPageSize'] === 30, '7: api pageSize 30');
test_assert(preg_match_all('/^\d+\.\s+/mu', $ctx7) === 5, '7: max 5 merged display rows');
test_assert(strpos($ctx7, '行程第6筆') === false, '7: sixth excluded');

// 8. appendTourContext integration
$basePrompt = '你是測試旅行社 LINE 客服。';
$merged = AiPromptBuilder::appendTourContext($basePrompt, $ctx3);
test_assert(strpos($merged, $basePrompt) === 0, '8: base preserved');
test_assert(strpos($merged, '以下為系統自動整理的旅遊商品搜尋結果資訊') !== false, '8: merge header');
test_assert(strpos($merged, '請嚴格遵守（回覆給客人時）：') !== false, '8: merge rules');

// 9. structured result — missing date requires clarification, no search
$GLOBALS['structured_api_calls'] = 0;
$structuredMockClient = new TourSearchApiClient($baseUrl, 5, static function (): array {
    ++$GLOBALS['structured_api_calls'];
    return [
        'ok' => true,
        'http_status' => 200,
        'body' => json_encode(['success' => true, 'items' => [], 'pagination' => ['total' => 0]], JSON_UNESCAPED_UNICODE),
        'transport_error' => null,
    ];
});
$structuredResultClarify = $service->buildTourContextResult([
    'userText' => '北海道',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $structuredMockClient,
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $hokkaidoClarifyIntent,
]);
test_assert($structuredResultClarify->isClarificationRequired() === true, '9: clarification_required true');
test_assert(
    $structuredResultClarify->getClarificationReason() === ClarificationPolicy::REASON_DATE_REQUIRED,
    '9: date_required reason'
);
test_assert($structuredResultClarify->getSearchCondition() === null, '9: no search_condition');
test_assert($structuredResultClarify->getSearchResults() === [], '9: no search_results');
test_assert($GLOBALS['structured_api_calls'] === 0, '9: Host B not called');
test_assert(
    strpos($structuredResultClarify->getLegacyContext(), HybridDateRequiredGate::CLARIFICATION_MARKER) !== false,
    '9: clarification legacy_context'
);
test_assert(is_string($structuredResultClarify->getLegacyContext()), '9: legacy_context is string');

// 10. structured result — dated destination searches
$structuredMockData = mockClientWithData('北海道');
$structuredResultSearch = $service->buildTourContextResult([
    'userText' => '北海道7月',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $structuredMockData['client'],
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $hokkaidoSearchIntent,
]);
test_assert($structuredResultSearch->isClarificationRequired() === false, '10: searchable');
test_assert($structuredResultSearch->getSearchCondition() !== null, '10: search_condition present');
test_assert(
    $structuredResultSearch->getSearchCondition()->getDestination() === ['北海道'],
    '10: destination mapped'
);
test_assert($structuredMockData['called'] === true, '10: Host B called');
test_assert($structuredResultSearch->getSearchResults() !== [], '10: search_results non-empty');
test_assert(is_string($structuredResultSearch->getLegacyContext()), '10: legacy_context string');
test_assert($structuredResultSearch->getLegacyContext() !== '', '10: legacy_context built');
test_assert($structuredResultSearch->getPrimarySearchDisplayLabel() === '北海道', '10: label from destination[0]');

// 11. primary_search_display_label authority — destination[0] only
$resolveLabel = static function (SearchCondition $condition) use ($service): ?string {
    $m = new ReflectionMethod(TourPromptContextService::class, 'resolvePrimarySearchDisplayLabel');
    $m->setAccessible(true);

    return $m->invoke($service, $condition);
};
$labelJapan = $resolveLabel(SearchCondition::empty('raw')->with(['destination' => ['日本'], 'keyword' => 'ignored']));
test_assert($labelJapan === '日本', '11: destination=[日本] → label=日本');
$labelEmptyDest = $resolveLabel(SearchCondition::empty('raw')->with(['destination' => [], 'keyword' => '日本']));
test_assert($labelEmptyDest === null, '11: empty destination → null even if keyword=日本');
$labelNoDest = $resolveLabel(SearchCondition::empty('日本三月'));
test_assert($labelNoDest === null, '11: does not parse raw customer text');

if ($failures === 0) {
    echo "OK: TourPromptContextService tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
