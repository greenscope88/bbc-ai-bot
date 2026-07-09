<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/core/search/BatsSearchIntent.php';
require_once $root . '/core/search/BatsSearchIntentMapper.php';
require_once $root . '/core/search/SearchCondition.php';
require_once $root . '/core/search/ApiQueryMapper.php';
require_once $root . '/core/search/SearchUrlBuilder.php';
require_once $root . '/core/search/ProductSearchVerificationTrace.php';
require_once $root . '/core/search/NoResultComposerPolicy.php';
require_once $root . '/core/product_source/SourceQueryMapper.php';
require_once $root . '/core/product_source/recommendation/ProductRecommendationBuilder.php';
require_once $root . '/core/product_source/recommendation/TravelConsultantPersonaRuntime.php';
require_once $root . '/core/tour_prompt_context_service.php';
$failures = 0;
function etm_assert(bool $cond, string $message): void { global $failures; if (!$cond) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); } }
$pilotSno = '5f99b8d665e8444d';
$ref = new DateTimeImmutable('2026-07-08', new DateTimeZone('Asia/Taipei'));
$mapper = new BatsSearchIntentMapper();
$apiMapper = new ApiQueryMapper();
$urlBuilder = new SearchUrlBuilder(false);
function assertTokenQuery(SearchCondition $cond, string $expectedSource, string $label, ?string $expectedApiKeyword = null): void {
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
// Case 1: Live LINE OA
$case1Intent = BatsSearchIntent::empty('BATS測試 我想去首爾自由行，7月出發')->with([
    'destination' => '首爾', 'product_type' => '自由行', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
]);
$case1Cond = $mapper->toSearchCondition($case1Intent);
etm_assert($case1Cond !== null, 'case1: SearchCondition');
etm_assert($case1Cond->getDestination() === '首爾', 'case1: destination');
etm_assert($case1Cond->getProductType() === '自由行', 'case1: product_type');
assertTokenQuery($case1Cond, '首爾 自由行', 'case1', '自由行');
$mockSearchClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode(['success' => true, 'pagination' => ['total' => 2], 'items' => [['title' => '首爾7月自由行']], 'search_url' => 'https://example.test/search', 'error' => null], JSON_UNESCAPED_UNICODE);
    return ['ok' => true, 'http_status' => 200, 'body' => $body !== false ? $body : '', 'transport_error' => null];
});
$case1Exec = (new TourPromptContextService())->buildTourContextResult([
    'userText' => 'BATS測試 我想去首爾自由行，7月出發', 'sno' => $pilotSno, 'featureEnabled' => true,
    'referenceDate' => $ref, 'searchClient' => $mockSearchClient, 'authoritativeIntent' => $case1Intent,
]);
etm_assert(count($case1Exec->getSearchResults()) > 0, 'case1: product search > 0');
$trace1 = ProductSearchVerificationTrace::build($case1Exec->getIntent(), $case1Cond, $pilotSno, count($case1Exec->getSearchResults()), $case1Exec->getSearchPolicyMeta());
etm_assert(($trace1['api_keyword'] ?? '') === '自由行', 'case1: trace api_keyword');
// Case 2: No Result Composer
$builder = new ProductRecommendationBuilder();
$case2Summary = $builder->build([], ['destination' => '北海道', 'product_type' => '自由行', 'date_from' => '2026-11-01', 'date_to' => '2026-11-30'], '北海道自由行，11月出發', ['raw_count' => 0]);
$case2Reply = (new TravelConsultantPersonaRuntime())->composeProductRecommendation($case2Summary);
etm_assert(($case2Summary['no_result_composer'] ?? false) === true, 'case2: no_result_composer');
etm_assert(strpos($case2Reply, '若您願意提供') === false, 'case2: no date ask');
// Case 3: 東京蜜月
$case3 = SearchCondition::empty('東京蜜月')->with(['destination' => '東京', 'travel_style' => ['蜜月']]);
assertTokenQuery($case3, '東京 蜜月', 'case3', '蜜月');
// Case 4: 東京迷你小團
$case4 = SearchCondition::empty('東京迷你小團')->with(['destination' => '東京', 'product_type' => '迷你小團']);
assertTokenQuery($case4, '東京 迷你小團', 'case4', '迷你小團');
// Case 5: 歐洲英國賞花自由行
$case5 = SearchCondition::empty('歐洲英國賞花自由行')->with([
    'area' => '歐洲', 'destination' => '英國', 'keyword' => '賞花', 'product_type' => '自由行',
]);
assertTokenQuery($case5, '歐洲 英國 賞花 自由行', 'case5', '賞花 自由行');
if ($failures === 0) { echo "ALL PASS test_aiu_v2_entity_token_mapping_verification\n"; exit(0); }
fwrite(STDERR, "{$failures} FAILURE(S)\n"); exit(1);