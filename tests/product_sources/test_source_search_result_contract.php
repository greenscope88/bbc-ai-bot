<?php
declare(strict_types=1);

/**
 * Phase 9-B-15: Source search result contract validation.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceSearchResultContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceSearchResultValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$validator = new SourceSearchResultValidator();

$minimalResult = [
    'source_platform' => 'agenttour',
    'tenant_instance' => 'rechoice_agenttour',
    'product_category' => 'group_tour',
    'title' => '東京五日遊',
    'search_url' => 'https://rechoice-travel.agenttour.com.tw/Index.aspx',
];

// Case 1: minimal result
try {
    $normalized = $validator->validate($minimalResult);
    test_assert($normalized['title'] === '東京五日遊', 'case1 title');
    test_assert($normalized['metadata'] === [], 'case1 empty metadata default');
    test_assert(true, 'case1 minimal result PASS');
} catch (SourceSearchResultContractException $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: full result
$fullResult = [
    'schema_version' => 1,
    'source_platform' => 'agenttour',
    'tenant_instance' => 'rechoice_agenttour',
    'product_category' => 'group_tour',
    'title' => '東京五日遊',
    'summary' => '精選東京行程',
    'search_url' => 'https://rechoice-travel.agenttour.com.tw/Index.aspx',
    'detail_url' => 'https://rechoice-travel.agenttour.com.tw/Tour/Detail/123',
    'metadata' => [
        'price_from' => 28888,
        'currency' => 'TWD',
    ],
];

try {
    $normalized = $validator->validate($fullResult);
    test_assert($normalized['summary'] === '精選東京行程', 'case2 summary');
    test_assert($normalized['metadata']['price_from'] === 28888, 'case2 metadata price_from');
    test_assert(true, 'case2 full result PASS');
} catch (SourceSearchResultContractException $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: missing title
try {
    $doc = $minimalResult;
    unset($doc['title']);
    $validator->validate($doc);
    test_assert(false, 'case3 should fail on missing title');
} catch (SourceSearchResultContractException $e) {
    test_assert(
        $e->getErrorCode() === SourceSearchResultContractException::TITLE_REQUIRED,
        'case3 SOURCE_RESULT_TITLE_REQUIRED'
    );
}

// Case 4: missing search_url
try {
    $doc = $minimalResult;
    unset($doc['search_url']);
    $validator->validate($doc);
    test_assert(false, 'case4 should fail on missing search_url');
} catch (SourceSearchResultContractException $e) {
    test_assert(
        $e->getErrorCode() === SourceSearchResultContractException::URL_REQUIRED,
        'case4 SOURCE_RESULT_URL_REQUIRED'
    );
}

// Case 5: missing tenant_instance
try {
    $doc = $minimalResult;
    unset($doc['tenant_instance']);
    $validator->validate($doc);
    test_assert(false, 'case5 should fail on missing tenant_instance');
} catch (SourceSearchResultContractException $e) {
    test_assert(
        $e->getErrorCode() === SourceSearchResultContractException::TENANT_INSTANCE_REQUIRED,
        'case5 SOURCE_RESULT_TENANT_INSTANCE_REQUIRED'
    );
}

// Case 6: hotel metadata
$hotelResult = [
    'source_platform' => 'ext_hotel_platform',
    'tenant_instance' => 'travel_b_hotel',
    'product_category' => 'hotel',
    'title' => '東京五星飯店',
    'search_url' => 'https://hotels.example.test/search/tokyo',
    'metadata' => [
        'hotel_star' => 5,
    ],
];

try {
    $normalized = $validator->validate($hotelResult);
    test_assert($normalized['product_category'] === 'hotel', 'case6 product_category hotel');
    test_assert($normalized['metadata']['hotel_star'] === 5, 'case6 hotel_star metadata');
    test_assert(true, 'case6 hotel metadata PASS');
} catch (SourceSearchResultContractException $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: airline metadata
$airlineResult = [
    'source_platform' => 'ext_ticket_platform',
    'tenant_instance' => 'travel_b_ticket',
    'product_category' => 'ticket',
    'title' => '台北東京來回機票',
    'search_url' => 'https://tickets.example.test/search/tpe-nrt',
    'metadata' => [
        'airline' => 'CI',
    ],
];

try {
    $normalized = $validator->validate($airlineResult);
    test_assert($normalized['product_category'] === 'ticket', 'case7 product_category ticket');
    test_assert($normalized['metadata']['airline'] === 'CI', 'case7 airline metadata');
    test_assert(true, 'case7 airline metadata PASS');
} catch (SourceSearchResultContractException $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_source_search_result_contract (all passed)\n");
exit(0);
