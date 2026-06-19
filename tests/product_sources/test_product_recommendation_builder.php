<?php
declare(strict_types=1);

/**
 * Phase 9-C-2A: ProductRecommendationBuilder tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntentBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'ProductRecommendationBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaRuntime.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function buildIntentFromQuery(string $query): array
{
    $ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
    $builder = new BatsSearchIntentBuilder(
        new HybridSearchConditionBuilder(new DateParser($ref))
    );

    return $builder->parse($query, [
        'reference_date' => $ref,
        'merge_legacy_keyword' => true,
    ])->toArray();
}

$builder = new ProductRecommendationBuilder();
$persona = new TravelConsultantPersonaRuntime();

$searchResults = [
    [
        'title' => '北海道五日',
        'summary' => '夏季經典行程',
        'primary_url' => 'https://example.test/hokkaido-5d',
    ],
    [
        'title' => '北海道溫泉六日',
        'search_url' => 'https://example.test/hokkaido-onsen-6d',
    ],
    [
        'title' => '北海道花季五日',
        'primary_url' => 'https://example.test/hokkaido-flower-5d',
    ],
];

// Case 1: search_results > 0
$intentHokkaido = buildIntentFromQuery('北海道7月');
$summaryWithResults = $builder->build($searchResults, $intentHokkaido, '北海道7月');
test_assert(($summaryWithResults['result_count'] ?? 0) === 3, 'case1 result_count=3');
test_assert(count($summaryWithResults['top_products'] ?? []) === 3, 'case1 top_products count');
test_assert(trim((string) ($summaryWithResults['primary_url'] ?? '')) !== '', 'case1 primary_url exists');
test_assert(trim((string) ($summaryWithResults['recommendation_reason'] ?? '')) !== '', 'case1 recommendation_reason exists');
test_assert(mb_strpos((string) $summaryWithResults['recommendation_reason'], '北海道') !== false, 'case1 reason mentions destination');

$replyWithResults = $persona->composeProductRecommendation($summaryWithResults);
test_assert(mb_strpos($replyWithResults, '北海道五日') !== false, 'case1 reply includes product title');
test_assert(mb_strpos($replyWithResults, 'https://example.test/hokkaido-5d') !== false, 'case1 reply includes primary_url');
test_assert(mb_strpos($replyWithResults, '超出') === false, 'case1 reply does not contain out-of-scope wording');

// Case 2: search_results = 0
$summaryEmpty = $builder->build([], $intentHokkaido, '北海道7月');
test_assert(($summaryEmpty['result_count'] ?? -1) === 0, 'case2 result_count=0');
test_assert(($summaryEmpty['top_products'] ?? null) === [], 'case2 top_products empty');
test_assert(trim((string) ($summaryEmpty['primary_url'] ?? 'x')) === '', 'case2 primary_url empty');

$replyNoResults = $persona->composeNoResultsMessage();
test_assert(mb_strpos($replyNoResults, '目前尚未找到符合條件的商品') !== false, 'case2 no-results message');
test_assert(mb_strpos($replyNoResults, '日期') !== false, 'case2 asks for date');
test_assert(mb_strpos($replyNoResults, '預算') !== false, 'case2 asks for budget');
test_assert(mb_strpos($replyNoResults, '出發地') !== false, 'case2 asks for departure');

// Case 3: primary_url resolved from search_url fallback
$summaryUrlFallback = $builder->build([
    ['title' => '東京近期團', 'search_url' => 'https://example.test/tokyo-recent'],
], buildIntentFromQuery('近期東京'), '近期東京');
test_assert(
    ($summaryUrlFallback['primary_url'] ?? '') === 'https://example.test/tokyo-recent',
    'case3 primary_url from search_url'
);

// Case 4: recommendation_summary structure generated
foreach (['result_count', 'top_products', 'primary_url', 'recommendation_reason'] as $key) {
    test_assert(array_key_exists($key, $summaryWithResults), 'case4 has ' . $key);
}
test_assert(is_array($summaryWithResults['top_products']), 'case4 top_products is array');
test_assert(isset($summaryWithResults['top_products'][0]['title']), 'case4 top product has title');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_product_recommendation_builder (all passed)\n");
exit(0);
