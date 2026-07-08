<?php
declare(strict_types=1);

/**
 * AIU v2 P1 Patch 2 / Product Type — Source Query Mapping regression.
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'BatsSearchIntentMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';

$failures = 0;

function sq_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @return array{
 *   platforms: array<string, array<string, mixed>>,
 *   instances: array<string, array<string, mixed>>,
 *   templates: array<string, array<string, mixed>>
 * }
 */
function sq_load_registry(): array
{
    $path = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'config'
        . DIRECTORY_SEPARATOR . 'product_source'
        . DIRECTORY_SEPARATOR . 'travel_b_search_registry.php';

    /** @var mixed $loaded */
    $loaded = require $path;

    if (!is_array($loaded)) {
        return ['platforms' => [], 'instances' => [], 'templates' => []];
    }

    return [
        'platforms' => isset($loaded['platforms']) && is_array($loaded['platforms']) ? $loaded['platforms'] : [],
        'instances' => isset($loaded['instances']) && is_array($loaded['instances']) ? $loaded['instances'] : [],
        'templates' => isset($loaded['templates']) && is_array($loaded['templates']) ? $loaded['templates'] : [],
    ];
}

// ============================================================================
// Prior Patch 2 cases (destination + keyword)
// ============================================================================

// Case Host B: destination + keyword sent independently.
$caseHostB = SearchCondition::empty('北海道 賞楓')->with([
    'destination' => '北海道',
    'keyword' => '賞楓',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-07',
]);
$apiParams = (new ApiQueryMapper())->toClientParams($caseHostB);
sq_assert(($apiParams['destination'] ?? '') === '北海道', 'hostb: api destination');
sq_assert(($apiParams['keyword'] ?? '') === '賞楓', 'hostb: api keyword');
sq_assert(!array_key_exists('source_keyword_query', $apiParams), 'hostb: api has no source_keyword_query');

// Keyword-only: destination + keyword (no product_type).
$caseKw = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($caseHostB);
sq_assert(($caseKw['destination'] ?? '') === '北海道', 'kw: document destination');
sq_assert(($caseKw['keyword'] ?? '') === '賞楓', 'kw: document keyword');
sq_assert(($caseKw['source_keyword_query'] ?? '') === '北海道 賞楓', 'kw: source_keyword_query');

$registryData = sq_load_registry();
$registry = new SearchUrlBuilderRegistry(
    $registryData['platforms'],
    $registryData['instances'],
    $registryData['templates']
);

// ============================================================================
// Case 1: no product_type field on platform — destination + product_type
// ============================================================================
$case1 = SearchCondition::empty('北海道自由行10月')->with([
    'destination' => '北海道',
    'keyword' => null,
    'product_type' => '自由行',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-31',
]);
$case1Doc = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($case1);
sq_assert(($case1Doc['destination'] ?? '') === '北海道', 'case1: runtime destination');
sq_assert(($case1Doc['keyword'] ?? '') === '' || ($case1Doc['keyword'] ?? null) === null, 'case1: keyword still empty');
sq_assert(($case1Doc['product_type'] ?? '') === '自由行', 'case1: product_type independent');
sq_assert(($case1Doc['source_keyword_query'] ?? '') === '北海道 自由行', 'case1: source_keyword_query');
sq_assert($case1->getKeyword() === null, 'case1: SearchCondition.keyword null');
sq_assert($case1->getProductType() === '自由行', 'case1: SearchCondition.product_type');
sq_assert($case1->getDestination() === '北海道', 'case1: SearchCondition.destination');

$grpUrl1 = (new ProductSourceSearchUrlBuilder($registry))->buildSearchUrl([
    'tenant_instance' => 'dayitravel_grp',
    'platform' => 'grp',
    'destination' => '北海道',
    'keyword' => null,
    'product_type' => '自由行',
    'source_keyword_query' => '北海道 自由行',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-31',
    'product_category' => 'group_tour',
])['search_url'];
$grpQuery1 = [];
parse_str((string) parse_url($grpUrl1, PHP_URL_QUERY), $grpQuery1);
$grpTp1 = rawurldecode((string) ($grpQuery1['tp'] ?? ''));
sq_assert(
    strpos($grpTp1, '北海道') !== false && strpos($grpTp1, '自由行') !== false,
    'case1: grp wire contains 北海道 自由行'
);

$bonusmeeUrl1 = (new SearchUrlBuilder(false))->build('5f99b8d665e8444d', $case1);
$bonusmeeQuery1 = [];
parse_str((string) parse_url($bonusmeeUrl1, PHP_URL_QUERY), $bonusmeeQuery1);
$bmKw1 = rawurldecode((string) ($bonusmeeQuery1['keyword'] ?? ''));
sq_assert($bmKw1 === '北海道 自由行', 'case1: bonusmee keyword = 北海道 自由行');
sq_assert(!array_key_exists('destination', $bonusmeeQuery1), 'case1: bonusmee omits destination');

// ============================================================================
// Case 2: destination + keyword + product_type
// ============================================================================
$case2 = SearchCondition::empty('北海道賞楓自由行')->with([
    'destination' => '北海道',
    'keyword' => '賞楓',
    'product_type' => '自由行',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-31',
]);
$case2Doc = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($case2);
sq_assert(($case2Doc['source_keyword_query'] ?? '') === '北海道 賞楓 自由行', 'case2: source_keyword_query');
sq_assert(($case2Doc['keyword'] ?? '') === '賞楓', 'case2: keyword not polluted by product_type');
sq_assert(($case2Doc['product_type'] ?? '') === '自由行', 'case2: product_type retained');
sq_assert($case2->getDestination() === '北海道', 'case2: destination unchanged');
sq_assert($case2->getKeyword() === '賞楓', 'case2: keyword unchanged');

$bbcUrl2 = (new ProductSourceSearchUrlBuilder($registry))->buildSearchUrl([
    'tenant_instance' => 'dayitravel_bbctravel',
    'platform' => 'bbctravel',
    'destination' => '北海道',
    'keyword' => '賞楓',
    'product_type' => '自由行',
    'source_keyword_query' => '北海道 賞楓 自由行',
    'departure_path_code' => 'all',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-31',
    'product_category' => 'group_tour',
])['search_url'];
$bbcQuery2 = [];
parse_str((string) parse_url($bbcUrl2, PHP_URL_QUERY), $bbcQuery2);
sq_assert(
    rawurldecode((string) ($bbcQuery2['q'] ?? '')) === '北海道 賞楓 自由行',
    'case2: bbctravel q = 北海道 賞楓 自由行'
);

// ============================================================================
// Case 3: product_type must not pollute keyword
// ============================================================================
$case3 = SearchCondition::empty('北海道自由行')->with([
    'destination' => '北海道',
    'keyword' => null,
    'product_type' => '自由行',
]);
$case3Doc = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($case3);
sq_assert($case3->getKeyword() === null, 'case3: SearchCondition.keyword null');
sq_assert($case3->getProductType() === '自由行', 'case3: SearchCondition.product_type');
sq_assert(($case3Doc['source_keyword_query'] ?? '') === '北海道 自由行', 'case3: source_keyword_query');
sq_assert(
    SourceQueryMapper::buildSourceKeywordQuery('北海道', '北海道', '自由行') === '北海道 自由行',
    'case3: duplicate destination/keyword de-duped'
);
sq_assert(
    SourceQueryMapper::buildSourceKeywordQuery('北海道', null, null) === '北海道',
    'case3: empty product_type omitted'
);
sq_assert(
    SourceQueryMapper::buildSourceKeywordQuery(null, null, null) === '',
    'case3: all empty yields empty string'
);

// ============================================================================
// Case 4: LINE OA-style utterance via adapter pipeline (Runtime Contract preserved)
// ============================================================================
$utterance = 'BATS測試 我想去北海道自由行，10月出發';
$authIntent = BatsSearchIntent::empty($utterance)->with([
    'intent' => BatsSearchIntent::INTENT_TOUR_SEARCH,
    'destination' => '北海道',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-31',
    'product_type' => '自由行',
]);
sq_assert($authIntent->getDestination() === '北海道', 'case4: intent destination');
sq_assert($authIntent->getProductType() === '自由行', 'case4: intent product_type');

// Direct SearchCondition path: keyword stays null; product_type independent.
$cond4Direct = SearchCondition::empty($utterance)->with([
    'destination' => '北海道',
    'keyword' => null,
    'product_type' => '自由行',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-31',
]);
$doc4Direct = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($cond4Direct);
sq_assert($cond4Direct->getKeyword() === null, 'case4: direct SearchCondition.keyword null');
sq_assert($cond4Direct->getProductType() === '自由行', 'case4: direct product_type');
sq_assert(($doc4Direct['source_keyword_query'] ?? '') === '北海道 自由行', 'case4: direct source query');
sq_assert(($doc4Direct['keyword'] ?? '') !== '自由行', 'case4: keyword not polluted by product_type');

// Mapper path: destination may map into keyword (existing Destination/Keyword rule);
// product_type must remain independent and fold into source_keyword_query.
$mapper = new BatsSearchIntentMapper();
$cond4 = $mapper->toSearchCondition($authIntent);
sq_assert($cond4 !== null, 'case4: SearchCondition built');
sq_assert($cond4->getDestination() === '北海道', 'case4: condition destination');
sq_assert($cond4->getProductType() === '自由行', 'case4: condition product_type');
sq_assert($cond4->getKeyword() !== '自由行', 'case4: mapped keyword not product_type');

$doc4 = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($cond4);
sq_assert(($doc4['source_keyword_query'] ?? '') === '北海道 自由行', 'case4: source query 北海道 自由行');
sq_assert(($doc4['product_type'] ?? '') === '自由行', 'case4: doc product_type retained');

$mockSearchClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [
            ['title' => '北海道十月自由行', 'tourDate' => '2026-10-12', 'price' => 38800],
        ],
        'search_url' => 'https://example.test/search?keyword=%E5%8C%97%E6%B5%B7%E9%81%93%20%E8%87%AA%E7%94%B1%E8%A1%8C',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => $body !== false ? $body : '',
        'transport_error' => null,
    ];
});
$service = new TourPromptContextService();
$result4 = $service->buildTourContextResult([
    'userText' => $utterance,
    'sno' => '5f99b8d665e8444d',
    'featureEnabled' => true,
    'referenceDate' => new DateTimeImmutable('2026-07-08', new DateTimeZone('Asia/Taipei')),
    'searchClient' => $mockSearchClient,
    'authoritativeIntent' => $authIntent,
]);
sq_assert(!$result4->isClarificationRequired(), 'case4: product search executes (no date clarification)');
sq_assert($result4->getIntent()->getProductType() === '自由行', 'case4: runtime product_type retained after search');
sq_assert($result4->getIntent()->getDestination() === '北海道', 'case4: runtime destination retained');
sq_assert(count($result4->getSearchResults()) > 0, 'case4: product search returned results');

$bonusmeeUrl4 = (new SearchUrlBuilder(false))->build('5f99b8d665e8444d', $cond4Direct);
$bmQ4 = [];
parse_str((string) parse_url($bonusmeeUrl4, PHP_URL_QUERY), $bmQ4);
sq_assert(
    rawurldecode((string) ($bmQ4['keyword'] ?? '')) === '北海道 自由行',
    'case4: bonusmee wire keyword 北海道 自由行'
);

// Wire keyword resolve for all keyword-only platforms.
foreach (['grp', 'bbctravel', 'tourcenter', 'bonusmee', 'bbcshops'] as $platform) {
    $wire = SourceQueryMapper::resolveWireKeyword([
        'destination' => '北海道',
        'keyword' => null,
        'product_type' => '自由行',
        'source_keyword_query' => '北海道 自由行',
    ], $platform);
    sq_assert($wire === '北海道 自由行', "case4: {$platform} wire keyword");
}

if ($failures === 0) {
    echo "ALL PASS test_aiu_p1_source_query_mapping\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_p1_source_query_mapping\n");
exit(1);