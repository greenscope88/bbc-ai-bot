<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';

$travelBSno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;
$ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));

$GLOBALS['hybrid_date_gate_api_calls'] = 0;
$GLOBALS['hybrid_date_gate_last_url'] = '';

$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    ++$GLOBALS['hybrid_date_gate_api_calls'];
    $GLOBALS['hybrid_date_gate_last_url'] = (string) ($GLOBALS['hybrid_last_url'] ?? '');

    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [['title' => '東京團', 'tourDate' => '2026-06-21', 'price' => 28000]],
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

$hybridConfig = [
    'enabled' => true,
    'allowed_sno' => [$travelBSno],
    'allowed_channels' => [],
    'dry_run_log_enabled' => false,
];

$multiSourceConfig = [
    'enabled' => true,
    'tenant_sno' => $travelBSno,
    'source_instance_keys' => ['dayitravel_grp', 'dayitravel_bbctravel', 'dayitravel_tourcenter'],
];

$service = new TourPromptContextService();

function hybrid_gate_reset_counters(): void
{
    $GLOBALS['hybrid_date_gate_api_calls'] = 0;
    $GLOBALS['hybrid_date_gate_last_url'] = '';
}

function hybrid_gate_build_context(TourPromptContextService $service, string $userText): string
{
    global $travelBSno, $mockClient, $hybridConfig, $multiSourceConfig, $ref;

    return $service->buildTourContextForPrompt([
        'userText' => $userText,
        'sno' => $travelBSno,
        'featureEnabled' => true,
        'searchClient' => $mockClient,
        'hybridSearchConfig' => $hybridConfig,
        'travelBMultiSourceLinksConfig' => $multiSourceConfig,
        'referenceDate' => $ref,
    ]);
}

// Case 1: 東京
hybrid_gate_reset_counters();
$ctx1 = hybrid_gate_build_context($service, '東京');
hybrid_test_assert($ctx1 !== '', 'case1: context non-empty (clarification)');
hybrid_test_assert(strpos($ctx1, HybridDateRequiredGate::CLARIFICATION_MARKER) !== false, 'case1: date clarification marker');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 0, 'case1: no Host B API call');
hybrid_test_assert(strpos($ctx1, GeminiTourContextBuilder::MULTI_SOURCE_LINKS_LABEL) === false, 'case1: no multi-source URLs');
hybrid_test_assert(strpos($ctx1, GeminiTourContextBuilder::SEARCH_URL_LABEL) === false, 'case1: no legacy search URL block');

// Case 2: 東京行程
hybrid_gate_reset_counters();
$ctx2 = hybrid_gate_build_context($service, '東京行程');
hybrid_test_assert(strpos($ctx2, HybridDateRequiredGate::CLARIFICATION_MARKER) !== false, 'case2: date clarification');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 0, 'case2: no API call');

// Case 3: 大阪近期 — fuzzy dates allow search (no clarification)
$refFuzzy = new DateTimeImmutable('2026-06-06', new DateTimeZone('Asia/Taipei'));
hybrid_gate_reset_counters();
$ctx3 = $service->buildTourContextForPrompt([
    'userText' => '大阪近期',
    'sno' => $travelBSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'hybridSearchConfig' => $hybridConfig,
    'travelBMultiSourceLinksConfig' => $multiSourceConfig,
    'referenceDate' => $refFuzzy,
]);
hybrid_test_assert(strpos($ctx3, HybridDateRequiredGate::CLARIFICATION_MARKER) === false, 'case3: 大阪近期 not clarification');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 1, 'case3: 大阪近期 API called');
hybrid_test_assert(strpos($ctx3, '【旅遊產品搜尋結果】') !== false, 'case3: 大阪近期 search context');
hybrid_test_assert(
    strpos($ctx3, 'q=%E5%A4%A7%E9%98%AA') !== false || strpos($ctx3, 'q=%e5%a4%a7%e9%98%aa') !== false,
    'case3: 大阪近期 bbctravel q encoded 大阪'
);
hybrid_test_assert(strpos($ctx3, 'datefrom=2026-06-06') !== false, 'case3: 大阪近期 bbctravel datefrom');

// Case 4: 高雄東京6月底
hybrid_gate_reset_counters();
$ctx4 = hybrid_gate_build_context($service, '高雄東京6月底');
hybrid_test_assert(strpos($ctx4, HybridDateRequiredGate::CLARIFICATION_MARKER) === false, 'case4: not clarification');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 1, 'case4: API called');
hybrid_test_assert(strpos($ctx4, '東京') !== false, 'case4: search context mentions 東京');

hybrid_test_finish('HybridDateRequiredGate integration');
