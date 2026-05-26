<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';

$stagingSno = 'e1fd133c7e8e45a1';
$baseUrl = 'https://bonusmee.com/api/gateway/tour/search.php';
$ref = new DateTimeImmutable('2026-05-25', new DateTimeZone('Asia/Taipei'));
$tourMessage = '我想找東京行程';

/**
 * @return array{client: TourSearchApiClient, keyword: string}
 */
function hybrid_mock_legacy_client(string &$capturedKeyword): TourSearchApiClient
{
    return new TourSearchApiClient($GLOBALS['hybrid_baseUrl'], 5, static function (string $url, array $headers, int $timeout) use (&$capturedKeyword): array {
        $q = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $capturedKeyword = (string) ($q['keyword'] ?? '');
        $body = json_encode([
            'success' => true,
            'traceId' => 'trace-test',
            'sno' => 'e1fd133c7e8e45a1',
            'keyword' => $capturedKeyword,
            'pagination' => ['page' => 1, 'pageSize' => 5, 'total' => 1],
            'items' => [['title' => 'mock', 'tourDate' => '2026-06-01', 'price' => 10000]],
            'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=' . rawurlencode($capturedKeyword),
            'error' => null,
        ], JSON_UNESCAPED_UNICODE);

        return ['ok' => true, 'http_status' => 200, 'body' => $body !== false ? $body : '', 'transport_error' => null];
    });
}

$GLOBALS['hybrid_baseUrl'] = $baseUrl;
$service = new TourPromptContextService();

// flag OFF → legacy keyword only (no dateFrom in URL)
$capturedKeyword = '';
$offContext = $service->buildTourContextForPrompt([
    'userText' => $tourMessage,
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => hybrid_mock_legacy_client($capturedKeyword),
    'hybridSearchConfig' => ['enabled' => false, 'allowed_sno' => [$stagingSno], 'allowed_channels' => []],
    'referenceDate' => $ref,
]);
hybrid_test_assert($offContext !== '', 'fallback: flag OFF still returns context');
hybrid_test_assert($capturedKeyword === '東京', 'fallback: flag OFF legacy keyword 東京');
hybrid_test_assert(strpos($offContext, '【旅遊產品搜尋結果】') !== false, 'fallback: flag OFF has tour header');

// hybrid ON but allowlist blocked → legacy
$capturedKeyword = '';
$blockedContext = $service->buildTourContextForPrompt([
    'userText' => $tourMessage,
    'sno' => 'not-allowed-sno',
    'featureEnabled' => true,
    'searchClient' => hybrid_mock_legacy_client($capturedKeyword),
    'hybridSearchConfig' => ['enabled' => true, 'allowed_sno' => [$stagingSno], 'allowed_channels' => []],
    'referenceDate' => $ref,
]);
hybrid_test_assert($blockedContext !== '', 'fallback: allowlist blocked still returns context');
hybrid_test_assert($capturedKeyword === '東京', 'fallback: allowlist blocked uses legacy keyword');

// hybrid exception → legacy fallback
$capturedKeyword = '';
$throwingBuilder = new ThrowingHybridParseStub();
$exceptionContext = $service->buildTourContextForPrompt([
    'userText' => $tourMessage,
    'sno' => $stagingSno,
    'featureEnabled' => true,
    'searchClient' => hybrid_mock_legacy_client($capturedKeyword),
    'hybridConditionBuilder' => $throwingBuilder,
    'hybridSearchConfig' => ['enabled' => true, 'allowed_sno' => [$stagingSno], 'allowed_channels' => [], 'dry_run_log_enabled' => false],
    'referenceDate' => $ref,
]);
hybrid_test_assert($exceptionContext !== '', 'fallback: hybrid exception still returns context');
hybrid_test_assert($capturedKeyword === '東京', 'fallback: hybrid exception uses legacy keyword');

hybrid_test_finish('Hybrid search legacy fallback');

final class ThrowingHybridParseStub
{
    public function parse(string $message, array $context = []): SearchCondition
    {
        throw new RuntimeException('forced hybrid failure');
    }
}
