<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MockShortUrlProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

$travelBSno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;
$ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));

$GLOBALS['hybrid_date_gate_api_calls'] = 0;

$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    ++$GLOBALS['hybrid_date_gate_api_calls'];

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

$multiSourceConfig = [
    'enabled' => true,
    'tenant_sno' => $travelBSno,
    'source_instance_keys' => ['dayitravel_grp', 'dayitravel_bbctravel', 'dayitravel_tourcenter'],
];

$service = new TourPromptContextService();
$mockShortUrlProvider = new MockShortUrlProvider();

function hybrid_gate_reset_counters(): void
{
    $GLOBALS['hybrid_date_gate_api_calls'] = 0;
}

function hybrid_gate_build_result(
    TourPromptContextService $service,
    BatsSearchIntent $intent,
    string $userText,
    DateTimeImmutable $ref,
    TourSearchApiClient $mockClient,
    array $multiSourceConfig,
    MockShortUrlProvider $mockShortUrlProvider,
    string $travelBSno
): TourPromptContextResult {
    return $service->buildTourContextResult([
        'userText' => $userText,
        'sno' => $travelBSno,
        'featureEnabled' => true,
        'searchClient' => $mockClient,
        'travelBMultiSourceLinksConfig' => $multiSourceConfig,
        'multiSourceShortUrlProvider' => $mockShortUrlProvider,
        'referenceDate' => $ref,
        'authoritativeIntent' => $intent,
    ]);
}

// Case 1: 東京
hybrid_gate_reset_counters();
$result1 = hybrid_gate_build_result(
    $service,
    GeminiDerivedSearchConditionFixtures::tokyoClarifyIntent(),
    '東京',
    $ref,
    $mockClient,
    $multiSourceConfig,
    $mockShortUrlProvider,
    $travelBSno
);
$ctx1 = $result1->getLegacyContext();
hybrid_test_assert($ctx1 !== '', 'case1: context non-empty (clarification)');
hybrid_test_assert(strpos($ctx1, HybridDateRequiredGate::CLARIFICATION_MARKER) !== false, 'case1: date clarification marker');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 0, 'case1: no Host B API call');
hybrid_test_assert(strpos($ctx1, GeminiTourContextBuilder::MULTI_SOURCE_LINKS_LABEL) === false, 'case1: no multi-source URLs');
hybrid_test_assert(strpos($ctx1, GeminiTourContextBuilder::SEARCH_URL_LABEL) === false, 'case1: no legacy search URL block');

// Case 2: 東京行程
hybrid_gate_reset_counters();
$result2 = hybrid_gate_build_result(
    $service,
    GeminiDerivedSearchConditionFixtures::tokyoTourClarifyIntent(),
    '東京行程',
    $ref,
    $mockClient,
    $multiSourceConfig,
    $mockShortUrlProvider,
    $travelBSno
);
$ctx2 = $result2->getLegacyContext();
hybrid_test_assert(strpos($ctx2, HybridDateRequiredGate::CLARIFICATION_MARKER) !== false, 'case2: date clarification');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 0, 'case2: no API call');

// Case 3: 大阪近期 — fuzzy dates allow search (no clarification)
$refFuzzy = new DateTimeImmutable('2026-06-06', new DateTimeZone('Asia/Taipei'));
hybrid_gate_reset_counters();
$result3 = hybrid_gate_build_result(
    $service,
    GeminiDerivedSearchConditionFixtures::osakaRecentIntent(),
    '大阪近期',
    $refFuzzy,
    $mockClient,
    $multiSourceConfig,
    $mockShortUrlProvider,
    $travelBSno
);
$ctx3 = $result3->getLegacyContext();
hybrid_test_assert(strpos($ctx3, HybridDateRequiredGate::CLARIFICATION_MARKER) === false, 'case3: 大阪近期 not clarification');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 1, 'case3: 大阪近期 API called');
hybrid_test_assert(strpos($ctx3, '【旅遊產品搜尋結果】') !== false, 'case3: 大阪近期 search context');
hybrid_test_assert(strpos($ctx3, 'bbctravel: https://bbcshops.com/') !== false, 'case3: 大阪近期 bbctravel short URL in context');
$osakaCondition = GeminiDerivedSearchConditionFixtures::recentOsaka();
$osakaLongUrl = '';
foreach ((new TravelBMultiSourceLinkBuilder($multiSourceConfig))->buildFromHybridCondition($osakaCondition, $travelBSno) as $row) {
    if (($row['platform'] ?? '') === 'bbctravel') {
        $osakaLongUrl = (string) ($row['search_url'] ?? '');
        break;
    }
}
hybrid_test_assert(
    strpos($osakaLongUrl, 'q=%E5%A4%A7%E9%98%AA') !== false || strpos($osakaLongUrl, 'q=%e5%a4%a7%e9%98%aa') !== false,
    'case3: builder bbctravel q encoded 大阪'
);
hybrid_test_assert(strpos($osakaLongUrl, 'datefrom=2026-06-06') !== false, 'case3: builder bbctravel datefrom');

// Case 4: 高雄東京6月底
hybrid_gate_reset_counters();
$result4 = hybrid_gate_build_result(
    $service,
    GeminiDerivedSearchConditionFixtures::kaohsiungTokyoLateJuneIntent(),
    '高雄東京6月底',
    $ref,
    $mockClient,
    $multiSourceConfig,
    $mockShortUrlProvider,
    $travelBSno
);
$ctx4 = $result4->getLegacyContext();
hybrid_test_assert(strpos($ctx4, HybridDateRequiredGate::CLARIFICATION_MARKER) === false, 'case4: not clarification');
hybrid_test_assert($GLOBALS['hybrid_date_gate_api_calls'] === 1, 'case4: API called');
hybrid_test_assert(strpos($ctx4, '東京') !== false, 'case4: search context mentions 東京');

hybrid_test_finish('HybridDateRequiredGate integration');
