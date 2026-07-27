<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/search/BatsSearchIntent.php';
require_once $root . '/core/search/BatsSearchIntentMapper.php';
require_once $root . '/core/search/SearchCondition.php';
require_once $root . '/core/search/SearchConditionCanonicalizer.php';
require_once $root . '/core/search/ApiQueryMapper.php';
require_once $root . '/core/search/SearchUrlBuilder.php';
require_once $root . '/core/search/ProductSearchVerificationTrace.php';
require_once $root . '/core/search/NoResultComposerPolicy.php';
require_once $root . '/core/product_source/SourceQueryMapper.php';
require_once $root . '/core/product_source/TravelBMultiSourceLinkBuilder.php';
require_once $root . '/core/product_source/SearchUrlBuilder.php';
require_once $root . '/core/product_source/SearchUrlBuilderRegistry.php';
require_once $root . '/core/product_source/recommendation/ProductRecommendationBuilder.php';
require_once $root . '/core/product_source/recommendation/TravelConsultantPersonaRuntime.php';
require_once $root . '/core/tour_prompt_context_service.php';
require_once $root . '/tests/support/AiuDestinationSemanticsTestFixtures.php';

$failures = 0;
function etm_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$pilotSno = '5f99b8d665e8444d';
$ref = new DateTimeImmutable('2026-07-08', new DateTimeZone('Asia/Taipei'));
$mapper = new BatsSearchIntentMapper();
$apiMapper = new ApiQueryMapper();
$urlBuilder = new SearchUrlBuilder(false);

/**
 * @return array{platforms: array<string, array<string, mixed>>, instances: array<string, array<string, mixed>>, templates: array<string, array<string, mixed>>}
 */
function etm_load_registry(): array
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

function assertThreeHallFullProjection(SearchCondition $cond, string $expectedFull, string $label): void
{
    $doc = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($cond);
    etm_assert(($doc['source_keyword_query'] ?? '') === $expectedFull, "{$label}: source_keyword_query");

    $registryData = etm_load_registry();
    $registry = new SearchUrlBuilderRegistry(
        $registryData['platforms'],
        $registryData['instances'],
        $registryData['templates']
    );
    $builder = new ProductSourceSearchUrlBuilder($registry);
    $base = [
        'destination' => $cond->getDestination(),
        'keyword' => $cond->getKeyword(),
        'source_keyword_query' => $expectedFull,
        'date_from' => $cond->getDateFrom(),
        'date_to' => $cond->getDateTo(),
        'product_category' => 'group_tour',
        'departure_path_code' => 'all',
    ];
    if ($cond->getArea() !== null && $cond->getArea() !== '') {
        $base['area'] = $cond->getArea();
    }

    $grpUrl = $builder->buildSearchUrl(array_merge($base, [
        'tenant_instance' => 'dayitravel_grp',
        'platform' => 'grp',
    ]))['search_url'];
    $grpQuery = [];
    parse_str((string) parse_url($grpUrl, PHP_URL_QUERY), $grpQuery);
    etm_assert(rawurldecode((string) ($grpQuery['tp'] ?? '')) === $expectedFull, "{$label}: grp tp");

    $btcUrl = $builder->buildSearchUrl(array_merge($base, [
        'tenant_instance' => 'dayitravel_bbctravel',
        'platform' => 'bbctravel',
    ]))['search_url'];
    $btcQuery = [];
    parse_str((string) parse_url($btcUrl, PHP_URL_QUERY), $btcQuery);
    etm_assert(rawurldecode((string) ($btcQuery['q'] ?? '')) === $expectedFull, "{$label}: bbctravel q");

    $tcUrl = $builder->buildSearchUrl(array_merge($base, [
        'tenant_instance' => 'dayitravel_tourcenter',
        'platform' => 'tourcenter',
    ]))['search_url'];
    $tcQuery = [];
    parse_str((string) parse_url($tcUrl, PHP_URL_QUERY), $tcQuery);
    etm_assert(rawurldecode((string) ($tcQuery['Keywords'] ?? '')) === $expectedFull, "{$label}: tourcenter Keywords");
}

function assertTokenQuery(SearchCondition $cond, string $expectedSource, string $label, ?string $expectedApiKeyword = null): void
{
    global $apiMapper, $urlBuilder, $pilotSno;
    $api = $apiMapper->toClientParams($cond, ['include_sno' => $pilotSno]);
    $url = $urlBuilder->build($pilotSno, $cond);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $apiKw = (string) ($api['keyword'] ?? '');
    $urlKw = rawurldecode((string) ($q['keyword'] ?? ''));
    $src = SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($cond);
    $apiExpected = $expectedApiKeyword ?? $expectedSource;
    etm_assert($src === $expectedSource, "$label: source_keyword_query");
    etm_assert($apiKw === $apiExpected, "$label: api keyword");
    etm_assert($urlKw === $expectedSource, "$label: url keyword");
}

/**
 * @param list<string> $destination
 * @param list<string> $tokens
 */
function buildAuthoritativeIntent(
    array $destination,
    array $tokens,
    string $projectedKeyword,
    ?string $travelArea = null,
    string $dateFrom = '2026-04-01',
    string $dateTo = '2026-04-30',
    ?string $productType = null
): BatsSearchIntent {
    $destPatch = AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch($destination, 'single');

    return new BatsSearchIntent(
        '',
        BatsSearchIntent::INTENT_TOUR_SEARCH,
        $destPatch['destination'],
        [],
        null,
        $dateFrom,
        $dateTo,
        null,
        null,
        null,
        null,
        null,
        $productType,
        null,
        [],
        [],
        false,
        null,
        0.95,
        $destPatch['destination_relation'],
        $destPatch['destination_semantics'],
        $tokens,
        $projectedKeyword,
        $travelArea
    );
}

function assertHostBAndKeywordOnly(
    SearchCondition $cond,
    string $expectedHostBKeyword,
    string $expectedFullKeyword,
    string $expectedDestination,
    string $label
): void {
    global $apiMapper, $pilotSno;

    $api = $apiMapper->toClientParams($cond, ['include_sno' => $pilotSno]);
    $hostB = (string) ($api['keyword'] ?? '');
    $dest = (string) ($api['destination'] ?? '');
    $city = (string) ($api['city'] ?? '');
    $src = SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($cond);
    $obs = $apiMapper->providerWireObservability($cond);

    etm_assert($dest === $expectedDestination, "{$label}: hostB destination");
    etm_assert($city === $expectedDestination, "{$label}: hostB city");
    etm_assert($hostB === $expectedHostBKeyword, "{$label}: hostB keyword");
    etm_assert($src === $expectedFullKeyword, "{$label}: keyword-only full projection");
    etm_assert(
        ($obs['provider_wire_keyword_mode'] ?? '') === SourceQueryMapper::PROVIDER_WIRE_MODE_HOSTB_DESTINATION_EXCLUDE,
        "{$label}: hostb observability mode"
    );
    etm_assert(
        ($obs['provider_wire_keyword_length'] ?? -1) === mb_strlen($hostB, 'UTF-8'),
        "{$label}: hostb observability length"
    );
    etm_assert(!array_key_exists('provider_wire_keyword_mode', $api), "{$label}: obs not in provider params");
}

// --- SSOT core cases ---
$case1aIntent = buildAuthoritativeIntent(['日本'], ['日本', '賞花'], '日本 賞花');
$case1aCond = $mapper->toSearchCondition($case1aIntent);
etm_assert($case1aCond !== null, 'ssot case1A: SearchCondition');
etm_assert($case1aCond->getKeyword() === '日本 賞花', 'ssot case1A: canonical keyword');
etm_assert($case1aCond->getSearchKeywordTokens() === ['日本', '賞花'], 'ssot case1A: tokens');
assertHostBAndKeywordOnly($case1aCond, '賞花', '日本 賞花', '日本', 'ssot case1A');
assertThreeHallFullProjection($case1aCond, '日本 賞花', 'ssot case1A');

$case1Intent = buildAuthoritativeIntent(['日本'], ['日本', '花季'], '日本 花季');
$case1Cond = $mapper->toSearchCondition($case1Intent);
etm_assert($case1Cond !== null, 'ssot case1B: SearchCondition');
etm_assert($case1Cond->getKeyword() === '日本 花季', 'ssot case1B: canonical keyword');
etm_assert($case1Cond->getSearchKeywordTokens() === ['日本', '花季'], 'ssot case1B: tokens');
etm_assert($case1aCond->getKeyword() !== $case1Cond->getKeyword(), 'ssot case1A/1B not interchangeable');
assertHostBAndKeywordOnly($case1Cond, '花季', '日本 花季', '日本', 'ssot case1B');
assertThreeHallFullProjection($case1Cond, '日本 花季', 'ssot case1B');

$case2Intent = buildAuthoritativeIntent(['北海道'], ['北海道', '母親節', '溫泉'], '北海道 母親節 溫泉');
$case2Cond = $mapper->toSearchCondition($case2Intent);
etm_assert($case2Cond !== null, 'ssot case2: SearchCondition');
assertHostBAndKeywordOnly($case2Cond, '母親節 溫泉', '北海道 母親節 溫泉', '北海道', 'ssot case2');
assertThreeHallFullProjection($case2Cond, '北海道 母親節 溫泉', 'ssot case2');

$case3Intent = buildAuthoritativeIntent(
    ['荷蘭'],
    ['歐洲', '荷蘭', '賞花', '鬱金香'],
    '歐洲 荷蘭 賞花 鬱金香',
    '歐洲'
);
$case3Cond = $mapper->toSearchCondition($case3Intent);
etm_assert($case3Cond !== null, 'ssot case3: SearchCondition');
etm_assert($case3Cond->getArea() === '歐洲', 'ssot case3: area');
assertHostBAndKeywordOnly($case3Cond, '歐洲 賞花 鬱金香', '歐洲 荷蘭 賞花 鬱金香', '荷蘭', 'ssot case3');
assertThreeHallFullProjection($case3Cond, '歐洲 荷蘭 賞花 鬱金香', 'ssot case3');

// --- Multi-word boundary cases ---
$caseA = buildAuthoritativeIntent(['New York'], ['美國', 'New York', '百老匯'], '美國 New York 百老匯');
$condA = $mapper->toSearchCondition($caseA);
etm_assert($condA !== null, 'caseA: SearchCondition');
assertHostBAndKeywordOnly($condA, '美國 百老匯', '美國 New York 百老匯', 'New York', 'caseA');

$caseB = buildAuthoritativeIntent(['日本'], ['日本', '親子 同遊', '溫泉'], '日本 親子 同遊 溫泉');
$condB = $mapper->toSearchCondition($caseB);
etm_assert($condB !== null, 'caseB: SearchCondition');
assertHostBAndKeywordOnly($condB, '親子 同遊 溫泉', '日本 親子 同遊 溫泉', '日本', 'caseB');

$caseC = SearchCondition::empty('')->with([
    'destination' => ['日本'],
    'keyword' => '日本 溫泉',
    'search_keyword_tokens' => ['日本', '花季'],
])->flag('bats_intent_mapped');
$threwC = false;
try {
    SearchConditionCanonicalizer::canonicalizeAuthoritative($caseC);
} catch (InvalidArgumentException $e) {
    $threwC = true;
}
etm_assert($threwC, 'caseC: divergence fail closed');

$caseD = SearchCondition::empty('')->with([
    'destination' => ['日本'],
    'keyword' => '日本 花季',
    'search_keyword_tokens' => [],
])->flag('bats_intent_mapped');
$threwD = false;
try {
    SourceQueryMapper::buildHostBKeywordFromSearchCondition($caseD);
} catch (InvalidArgumentException $e) {
    $threwD = true;
}
etm_assert($threwD, 'caseD: missing tokens fail closed');

// --- Restored pre-01D-3 cases ---
$restored1Intent = BatsSearchIntent::empty('BATS測試 我想去首爾自由行，7月出發')->with([
    'destination' => '首爾',
    'product_type' => '自由行',
    'date_from' => '2026-07-01',
    'date_to' => '2026-07-31',
    'search_keyword_tokens' => ['首爾', '自由行'],
    'projected_keyword' => '首爾 自由行',
    'destination_relation' => 'single',
    'destination_semantics' => [AiuDestinationSemanticsTestFixtures::candidate('首爾')],
]);
$restored1Cond = $mapper->toSearchCondition($restored1Intent);
etm_assert($restored1Cond !== null, 'restored case1: SearchCondition');
etm_assert($restored1Cond->getDestination() === ['首爾'], 'restored case1: destination');
etm_assert($restored1Cond->getProductType() === '自由行', 'restored case1: product_type');
assertTokenQuery($restored1Cond, '首爾 自由行', 'restored case1', '自由行');
$mockSearchClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode(['success' => true, 'pagination' => ['total' => 2], 'items' => [['title' => '首爾7月自由行']], 'search_url' => 'https://example.test/search', 'error' => null], JSON_UNESCAPED_UNICODE);
    return ['ok' => true, 'http_status' => 200, 'body' => $body !== false ? $body : '', 'transport_error' => null];
});
$case1Exec = (new TourPromptContextService())->buildTourContextResult([
    'userText' => 'BATS測試 我想去首爾自由行，7月出發',
    'sno' => $pilotSno,
    'featureEnabled' => true,
    'referenceDate' => $ref,
    'searchClient' => $mockSearchClient,
    'authoritativeIntent' => $restored1Intent,
]);
etm_assert(count($case1Exec->getSearchResults()) > 0, 'restored case1: product search > 0');
$trace1 = ProductSearchVerificationTrace::build($case1Exec->getIntent(), $restored1Cond, $pilotSno, count($case1Exec->getSearchResults()), $case1Exec->getSearchPolicyMeta());
etm_assert(($trace1['api_keyword'] ?? '') === '自由行', 'restored case1: trace api_keyword');

$builder = new ProductRecommendationBuilder();
$case2Summary = $builder->build([], ['destination' => '北海道', 'product_type' => '自由行', 'date_from' => '2026-11-01', 'date_to' => '2026-11-30'], '北海道自由行，11月出發', ['raw_count' => 0]);
$case2Reply = (new TravelConsultantPersonaRuntime())->composeProductRecommendation($case2Summary);
etm_assert(($case2Summary['no_result_composer'] ?? false) === true, 'restored case2: no_result_composer');
etm_assert(strpos($case2Reply, '若您願意提供') === false, 'restored case2: no date ask');

$case3 = SearchCondition::empty('東京蜜月')->with(['destination' => '東京', 'travel_style' => ['蜜月']]);
assertTokenQuery($case3, '東京 蜜月', 'restored case3', '蜜月');

$case4 = SearchCondition::empty('東京迷你小團')->with(['destination' => '東京', 'product_type' => '迷你小團']);
assertTokenQuery($case4, '東京 迷你小團', 'restored case4', '迷你小團');

$case5 = SearchCondition::empty('歐洲英國賞花自由行')->with([
    'area' => '歐洲',
    'destination' => '英國',
    'keyword' => '賞花',
    'product_type' => '自由行',
]);
assertTokenQuery($case5, '歐洲 英國 賞花 自由行', 'restored case5', '賞花 自由行');

$noProjection = buildAuthoritativeIntent(['東京'], [], '');
$noCond = $mapper->toSearchCondition($noProjection);
etm_assert($noCond === null, 'no projection no SearchCondition');

$kwOnlyObs = $apiMapper->providerWireObservability($case1Cond);
etm_assert(
    ($kwOnlyObs['provider_wire_keyword_mode'] ?? '') === SourceQueryMapper::PROVIDER_WIRE_MODE_HOSTB_DESTINATION_EXCLUDE,
    'observability hostb mode on authoritative with destination'
);

$kwOnlyCond = SearchCondition::empty('')->with([
    'keyword' => '京都 自由行',
    'search_keyword_tokens' => ['京都', '自由行'],
])->flag('bats_intent_mapped');
$kwOnlyObs2 = $apiMapper->providerWireObservability($kwOnlyCond);
etm_assert(
    ($kwOnlyObs2['provider_wire_keyword_mode'] ?? '') === SourceQueryMapper::PROVIDER_WIRE_MODE_KEYWORD_ONLY_FULL,
    'observability keyword_only_full mode'
);
etm_assert(
    ($kwOnlyObs2['provider_wire_keyword_length'] ?? -1) === mb_strlen('京都 自由行', 'UTF-8'),
    'observability keyword_only_full length'
);
$kwOnlyApi = $apiMapper->toClientParams($kwOnlyCond, ['include_sno' => $pilotSno]);
etm_assert(!array_key_exists('provider_wire_keyword_mode', $kwOnlyApi), 'keyword_only obs not in provider params');

if ($failures === 0) {
    echo "ALL PASS test_aiu_v2_entity_token_mapping_verification\n";
    exit(0);
}
fwrite(STDERR, "{$failures} FAILURE(S)\n");
exit(1);
