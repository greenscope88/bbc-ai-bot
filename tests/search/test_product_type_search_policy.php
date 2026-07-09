<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ProductSearchPolicyRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'ProductRecommendationBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';

$failures = 0;
function pt_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$policy = new ProductSearchPolicyRuntime();
$builder = new ProductRecommendationBuilder($policy);
$persona = new TravelConsultantPersonaRuntime();

// Case 1: Seoul FIT exists -> recommend FIT only
$case1Items = [
    ['title' => '首爾11月自由行五日', 'primary_url' => 'https://example.test/seoul-fit'],
    ['title' => '首爾11月跟團六日', 'primary_url' => 'https://example.test/seoul-group'],
];
$case1 = SearchCondition::empty('我想去首爾自由行，11月出發')->with([
    'destination' => '首爾',
    'keyword' => null,
    'product_type' => '自由行',
    'date_from' => '2026-11-01',
    'date_to' => '2026-11-30',
]);
$case1Policy = $policy->apply($case1, $case1Items);
pt_assert(($case1Policy['policy'] ?? '') === 'product_type_strict', 'case1: strict policy');
pt_assert(count($case1Policy['primary_results']) === 1, 'case1: one primary result');
pt_assert(strpos((string) $case1Policy['primary_results'][0]['title'], '自由行') !== false, 'case1: primary is FIT');
pt_assert($case1->getKeyword() === null, 'case1: runtime keyword unchanged');
pt_assert($case1->getProductType() === '自由行', 'case1: runtime product_type unchanged');
pt_assert(
    SourceQueryMapper::buildSourceKeywordQuery('首爾', null, '自由行') === '首爾 自由行',
    'case1: source query includes product_type'
);

$case1Summary = $builder->build($case1Policy['primary_results'], [
    'destination' => '首爾',
    'product_type' => '自由行',
    'date_from' => '2026-11-01',
    'date_to' => '2026-11-30',
], '我想去首爾自由行，11月出發', $case1Policy);
pt_assert(($case1Summary['result_count'] ?? 0) === 1, 'case1: summary count');
pt_assert(strpos((string) ($case1Summary['top_products'][0]['title'] ?? ''), '自由行') !== false, 'case1: summary FIT title');

// Case 2: no FIT -> mismatch message + alternatives, no group tour in primary
$case2Items = [
    ['title' => '首爾11月跟團六日', 'primary_url' => 'https://example.test/seoul-group'],
    ['title' => '首爾11月團體七日', 'primary_url' => 'https://example.test/seoul-group-7d'],
];
$case2Policy = $policy->apply($case1, $case2Items);
pt_assert(($case2Policy['product_type_mismatch'] ?? false) === true, 'case2: mismatch flagged');
pt_assert(count($case2Policy['primary_results']) === 0, 'case2: no primary group tour');
pt_assert(count($case2Policy['alternative_results']) === 2, 'case2: alternatives kept');

$case2Summary = $builder->build([], [
    'destination' => '首爾',
    'product_type' => '自由行',
    'date_from' => '2026-11-01',
    'date_to' => '2026-11-30',
], '我想去首爾自由行，11月出發', $case2Policy);
pt_assert(($case2Summary['product_type_mismatch'] ?? false) === true, 'case2: summary mismatch');
pt_assert(($case2Summary['result_count'] ?? -1) === 0, 'case2: summary zero primary');
pt_assert(
    strpos((string) ($case2Summary['recommendation_reason'] ?? ''), '首爾自由行') !== false,
    'case2: reason mentions seoul FIT'
);
pt_assert(count($case2Summary['alternative_recommendations'] ?? []) >= 1, 'case2: alternatives present');
$case2Reply = $persona->composeProductRecommendation($case2Summary);
pt_assert(strpos($case2Reply, '替代方案') !== false, 'case2: reply offers alternatives');
pt_assert(strpos($case2Reply, '跟團') !== false, 'case2: alternative mentions group tour');
pt_assert(strpos($case2Reply, '目前沒有找到') !== false, 'case2: mismatch opening line');

// Case 3: keyword preference ranking, no strict filter
$case3Items = [
    ['title' => '京都一般觀光五日'],
    ['title' => '京都鐵道泡湯精選六日'],
    ['title' => '京都美食小團'],
];
$case3 = SearchCondition::empty('我想去京都鐵道泡湯，11月出發')->with([
    'destination' => '京都',
    'keyword' => '鐵道泡湯',
    'product_type' => null,
    'date_from' => '2026-11-01',
    'date_to' => '2026-11-30',
]);
$case3Policy = $policy->apply($case3, $case3Items);
pt_assert(($case3Policy['policy'] ?? '') === 'keyword_preference', 'case3: keyword preference policy');
pt_assert(count($case3Policy['primary_results']) === 3, 'case3: all items kept');
pt_assert(
    strpos((string) $case3Policy['primary_results'][0]['title'], '鐵道泡湯') !== false,
    'case3: keyword match ranked first'
);
pt_assert($case3->getProductType() === null, 'case3: no product_type');

// Integration: tour service applies policy on execute
$mockSearchClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 2],
        'items' => [
            ['title' => '首爾11月自由行五日', 'tourDate' => '2026-11-10', 'price' => 32800],
            ['title' => '首爾11月跟團六日', 'tourDate' => '2026-11-12', 'price' => 28800],
        ],
        'search_url' => 'https://example.test/search',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);
    return ['ok' => true, 'http_status' => 200, 'body' => $body !== false ? $body : '', 'transport_error' => null];
});
$authIntent = BatsSearchIntent::empty('我想去首爾自由行，11月出發')->with([
    'destination' => '首爾',
    'date_from' => '2026-11-01',
    'date_to' => '2026-11-30',
    'product_type' => '自由行',
]);
$service = new TourPromptContextService();
$exec = $service->buildTourContextResult([
    'userText' => '我想去首爾自由行，11月出發',
    'sno' => '5f99b8d665e8444d',
    'featureEnabled' => true,
    'referenceDate' => new DateTimeImmutable('2026-07-08', new DateTimeZone('Asia/Taipei')),
    'searchClient' => $mockSearchClient,
    'authoritativeIntent' => $authIntent,
]);
pt_assert(count($exec->getSearchResults()) === 1, 'integration: strict primary count');
pt_assert(($exec->getSearchPolicyMeta()['policy'] ?? '') === 'product_type_strict', 'integration: policy meta');
pt_assert($exec->getIntent()->getProductType() === '自由行', 'integration: runtime product_type preserved');

if ($failures === 0) {
    echo "ALL PASS test_product_type_search_policy\n";
    exit(0);
}
fwrite(STDERR, "{$failures} FAILURE(S) in test_product_type_search_policy\n");
exit(1);