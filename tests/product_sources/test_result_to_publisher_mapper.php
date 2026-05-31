<?php
declare(strict_types=1);

/**
 * Phase 9-B-17: Result to publisher mapper.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ResultToPublisherMapper.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$mapper = new ResultToPublisherMapper();

$searchUrl = 'https://rechoice-travel.agenttour.com.tw/Index.aspx';

$minimalSource = [
    'source_platform' => 'agenttour',
    'tenant_instance' => 'rechoice_agenttour',
    'product_category' => 'group_tour',
    'title' => '東京五日遊',
    'search_url' => $searchUrl,
];

// Case 1: minimal SourceSearchResult
try {
    $publisher = $mapper->map($minimalSource);
    test_assert($publisher['primary_url'] === $searchUrl, 'case1 primary_url from search_url');
    test_assert($publisher['tenant_instance'] === 'rechoice_agenttour', 'case1 tenant_instance');
    test_assert(count($publisher['actions']) === 1, 'case1 default action');
    test_assert($publisher['actions'][0]['label'] === '查看內容', 'case1 action label');
    test_assert($publisher['actions'][0]['url'] === $searchUrl, 'case1 action url');
    test_assert(true, 'case1 minimal SourceSearchResult PASS');
} catch (ResultToPublisherMapperException $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: full SourceSearchResult
$fullSource = [
    'schema_version' => 1,
    'source_platform' => 'agenttour',
    'tenant_instance' => 'rechoice_agenttour',
    'product_category' => 'group_tour',
    'title' => '東京五日遊',
    'summary' => '精選東京行程',
    'search_url' => $searchUrl,
    'detail_url' => 'https://rechoice-travel.agenttour.com.tw/Tour/Detail/123',
    'metadata' => [
        'price_from' => 28888,
        'currency' => 'TWD',
    ],
];

try {
    $publisher = $mapper->map($fullSource);
    test_assert($publisher['summary'] === '精選東京行程', 'case2 summary mapped');
    test_assert($publisher['primary_url'] === $searchUrl, 'case2 primary_url');
    test_assert(
        in_array('https://rechoice-travel.agenttour.com.tw/Tour/Detail/123', $publisher['secondary_urls'], true),
        'case2 detail_url in secondary_urls'
    );
    test_assert(true, 'case2 full SourceSearchResult PASS');
} catch (ResultToPublisherMapperException $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: group_tour metadata
try {
    $publisher = $mapper->map(array_merge($minimalSource, [
        'metadata' => ['price_from' => 28888, 'currency' => 'TWD'],
    ]));
    test_assert($publisher['metadata']['price_from'] === 28888, 'case3 price_from');
    test_assert($publisher['product_category'] === 'group_tour', 'case3 product_category');
    test_assert(true, 'case3 group_tour metadata PASS');
} catch (ResultToPublisherMapperException $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: hotel metadata
try {
    $publisher = $mapper->map([
        'source_platform' => 'ext_hotel',
        'tenant_instance' => 'travel_b_hotel',
        'product_category' => 'hotel',
        'title' => '東京五星飯店',
        'search_url' => 'https://hotels.example.test/search/tokyo',
        'metadata' => ['hotel_star' => 5],
    ]);
    test_assert($publisher['metadata']['hotel_star'] === 5, 'case4 hotel_star');
    test_assert(true, 'case4 hotel metadata PASS');
} catch (ResultToPublisherMapperException $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: ticket metadata
try {
    $publisher = $mapper->map([
        'source_platform' => 'ext_ticket',
        'tenant_instance' => 'travel_b_ticket',
        'product_category' => 'ticket',
        'title' => '台北東京來回機票',
        'search_url' => 'https://tickets.example.test/search/tpe-nrt',
        'metadata' => ['airline' => 'CI'],
    ]);
    test_assert($publisher['metadata']['airline'] === 'CI', 'case5 airline');
    test_assert(true, 'case5 ticket metadata PASS');
} catch (ResultToPublisherMapperException $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: missing search_url
try {
    $doc = $minimalSource;
    unset($doc['search_url']);
    $mapper->map($doc);
    test_assert(false, 'case6 should fail on missing search_url');
} catch (ResultToPublisherMapperException $e) {
    test_assert(
        $e->getErrorCode() === ResultToPublisherMapperException::URL_REQUIRED,
        'case6 RESULT_TO_PUBLISHER_URL_REQUIRED'
    );
}

// Case 7: metadata fully preserved
$richMetadata = [
    'price_from' => 28888,
    'currency' => 'TWD',
    'tags' => ['family', 'hot_spring'],
    'nested' => ['days' => 5, 'nights' => 4],
];

try {
    $publisher = $mapper->map(array_merge($minimalSource, [
        'metadata' => $richMetadata,
    ]));
    test_assert($publisher['metadata'] === $richMetadata, 'case7 metadata identical');
    test_assert(true, 'case7 metadata fully preserved PASS');
} catch (ResultToPublisherMapperException $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_result_to_publisher_mapper (all passed)\n");
exit(0);
