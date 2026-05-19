<?php
declare(strict_types=1);

/**
 * Stage 1-B-17 integration dry-run: base prompt + tour context (mock only).
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

$basePrompt = "你是測試旅行社的 LINE 客服。\n使用者問題：有沒有大阪五日遊";
$userText = '有沒有大阪五日遊';

$service = new TourPromptContextService();
$tourContext = $service->buildTourContextForPrompt([
    'userText' => $userText,
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
]);

$finalPrompt = AiPromptBuilder::appendTourContext($basePrompt, $tourContext);

echo "=== Tour Prompt Context Integration Dry Run ===\n\n";
echo "User Text:\n{$userText}\n\n";
echo "Feature Enabled: true\n";
echo "Transport: mock (no live HTTP)\n\n";
echo "Final Prompt Preview:\n";
echo $finalPrompt . "\n\n";

test_assert(strpos($finalPrompt, $basePrompt) === 0, 'preview: base prompt');
test_assert(strpos($finalPrompt, '大阪五日遊精選') !== false, 'preview: tour title');
test_assert(strpos($finalPrompt, '請嚴格遵守：') !== false, 'preview: merge rules');
test_assert(strpos($finalPrompt, 'SECRET-TRACE-DRY') === false, 'preview: no trace leak');

if ($failures === 0) {
    echo "OK: Tour prompt context integration dry-run passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
