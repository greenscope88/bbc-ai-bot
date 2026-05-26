<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchDryRunLogger.php';

$stagingSno = 'e1fd133c7e8e45a1';
$baseUrl = 'https://bonusmee.com/api/gateway/tour/search.php';
$ref = new DateTimeImmutable('2026-05-25', new DateTimeZone('Asia/Taipei'));
$logPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hybrid_search_dry_run_test_' . bin2hex(random_bytes(4)) . '.log';
@unlink($logPath);

$GLOBALS['hybrid_last_url'] = '';
$GLOBALS['hybrid_baseUrl'] = $baseUrl;

$hybridClient = new TourSearchApiClient($baseUrl, 5, static function (string $url, array $headers, int $timeout): array {
    $GLOBALS['hybrid_last_url'] = $url;
    $q = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $keyword = (string) ($q['keyword'] ?? '');
    $body = json_encode([
        'success' => true,
        'traceId' => 'trace-hybrid',
        'sno' => 'e1fd133c7e8e45a1',
        'keyword' => $keyword,
        'pagination' => ['page' => 1, 'pageSize' => 30, 'total' => 2],
        'items' => [['title' => '東京團', 'tourDate' => '2026-06-21', 'price' => 28000]],
        'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=' . rawurlencode($keyword),
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return ['ok' => true, 'http_status' => 200, 'body' => $body !== false ? $body : '', 'transport_error' => null];
});

$service = new TourPromptContextService();
$context = $service->buildTourContextForPrompt([
    'userText' => '六月底東京團',
    'sno' => $stagingSno,
    'channelId' => 'Ustaging',
    'traceId' => 'trace-integration-001',
    'featureEnabled' => true,
    'searchClient' => $hybridClient,
    'searchUrlBuilder' => new SearchUrlBuilder(false),
    'hybridSearchConfig' => [
        'enabled' => true,
        'allowed_sno' => [$stagingSno],
        'allowed_channels' => [],
        'dry_run_log_enabled' => true,
    ],
    'referenceDate' => $ref,
]);

hybrid_test_assert($context !== '', 'integration: hybrid context built');
hybrid_test_assert(strpos($context, '東京') !== false, 'integration: context mentions 東京');

$url = (string) $GLOBALS['hybrid_last_url'];
hybrid_test_assert(strpos($url, 'dateFrom=2026-06-21') !== false, 'integration: API URL has dateFrom');
hybrid_test_assert(strpos($url, 'dateTo=2026-06-30') !== false, 'integration: API URL has dateTo');
hybrid_test_assert(strpos($url, 'keyword=') !== false, 'integration: API URL has keyword');

HybridSearchDryRunLogger::log([
    'trace_id' => 'trace-integration-001',
    'sno' => $stagingSno,
    'channel' => 'Ustaging',
    'message' => '六月底東京三萬以下',
    'flow' => 'hybrid',
    'api_params' => ['keyword' => '東京', 'dateFrom' => '2026-06-21', 'dateTo' => '2026-06-30'],
    'userId' => 'must-not-appear',
    'api_key' => 'secret',
], $logPath);

hybrid_test_assert(is_file($logPath), 'integration: dry-run log file written');
$logBody = (string) file_get_contents($logPath);
hybrid_test_assert(strpos($logBody, 'trace-integration-001') !== false, 'integration: log has trace_id');
hybrid_test_assert(strpos($logBody, 'must-not-appear') === false, 'integration: log strips userId');
hybrid_test_assert(strpos($logBody, 'secret') === false, 'integration: log strips api_key');
@unlink($logPath);

hybrid_test_finish('Hybrid search integration dry-run');
