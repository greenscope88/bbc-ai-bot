<?php
declare(strict_types=1);

/**
 * Stage 1-B-17 integration dry-run: base prompt + Gemini-authoritative tour context (mock only).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'ai_prompt_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';

$failures = 0;
$baseUrl = 'https://bonusmee.com/api/gateway/tour/search.php';
$stagingSno = 'e1fd133c7e8e45a1';
$structuredRef = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$aiuRuntime = AiIntentUnderstandingRuntime::createForTesting();
$translator = new AiuProductIntentTranslator();
$aiuCtx = [
    'conversation_id' => $stagingSno . ':line:U-integration-dry-run',
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

$mockClient = new TourSearchApiClient($baseUrl, 5, static function (string $url, array $headers, int $timeout): array {
    $body = json_encode([
        'success' => true,
        'traceId' => 'SECRET-TRACE-DRY',
        'pagination' => ['total' => 3],
        'items' => [
            ['title' => '大阪五日遊精選', 'tourDate' => '2026-09-01', 'price' => 28900],
        ],
        'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=%E5%A4%A7%E9%98%AA',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => $body !== false ? $body : '',
        'transport_error' => null,
    ];
});

$userText = '大阪 7月五日遊';
$basePrompt = "你是測試旅行社的 LINE 客服。\n使用者問題：{$userText}";
$osakaIntent = $translator->translate($aiuRuntime->understand($userText, $aiuCtx));

$service = new TourPromptContextService();
$searchResult = $service->buildTourContextResult([
    'userText' => $userText,
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $osakaIntent,
]);
$tourContext = $searchResult->getLegacyContext();
$finalPrompt = AiPromptBuilder::appendTourContext($basePrompt, $tourContext);

echo "=== Tour Prompt Context Integration Dry Run ===\n\n";
echo "User Text:\n{$userText}\n\n";
echo "Feature Enabled: true\n";
echo "Transport: mock (no live HTTP)\n\n";
echo "Final Prompt Preview:\n";
echo $finalPrompt . "\n\n";

test_assert(strpos($finalPrompt, $basePrompt) === 0, 'preview: base prompt');
test_assert(strpos($finalPrompt, '大阪五日遊精選') !== false, 'preview: tour title');
test_assert(strpos($finalPrompt, '請嚴格遵守（回覆給客人時）：') !== false, 'preview: merge rules');
test_assert(strpos($finalPrompt, 'SECRET-TRACE-DRY') === false, 'preview: no trace leak');

$GLOBALS['structured_dry_run_api_calls'] = 0;
$structuredDryRunClient = new TourSearchApiClient($baseUrl, 5, static function (): array {
    ++$GLOBALS['structured_dry_run_api_calls'];
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [['title' => '大阪五日遊精選', 'tourDate' => '2026-09-01', 'price' => 28900]],
        'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=%E5%A4%A7%E9%98%AA',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => $body !== false ? $body : '',
        'transport_error' => null,
    ];
});

$clarifyIntent = $translator->translate($aiuRuntime->understand('大阪', $aiuCtx));
$clarifyResult = $service->buildTourContextResult([
    'userText' => '大阪',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $structuredDryRunClient,
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $clarifyIntent,
]);
test_assert($clarifyResult->isClarificationRequired() === true, 'structured: clarification block');
test_assert($GLOBALS['structured_dry_run_api_calls'] === 0, 'structured: no API on clarification');

$GLOBALS['structured_dry_run_api_calls'] = 0;
$osakaJulyIntent = $translator->translate($aiuRuntime->understand('大阪7月', $aiuCtx));
$searchableResult = $service->buildTourContextResult([
    'userText' => '大阪7月',
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $structuredDryRunClient,
    'referenceDate' => $structuredRef,
    'authoritativeIntent' => $osakaJulyIntent,
]);
test_assert($searchableResult->isClarificationRequired() === false, 'structured: searchable path');
test_assert($GLOBALS['structured_dry_run_api_calls'] === 1, 'structured: API called on searchable');
test_assert($searchableResult->getSearchCondition() !== null, 'structured: search_condition');
test_assert(strpos($searchableResult->getLegacyContext(), '大阪五日遊精選') !== false, 'structured: legacy_context content');

if ($failures === 0) {
    echo "OK: Tour prompt context integration dry-run passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
