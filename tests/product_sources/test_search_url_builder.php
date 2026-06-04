<?php
declare(strict_types=1);

/**
 * Phase 9-B-11: SearchUrlBuilder core.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$travelBSno = '5f99b8d665e8444d';

$agentTourPlatform = [
    'platform_id' => 'agenttour',
    'platform_name' => 'AgentTour',
    'platform_domain' => 'agenttour.com.tw',
    'platform_type' => 'external_search',
    'supported_categories' => ['group_tour', 'fit'],
    'identifier_type' => 'subdomain',
    'adapter' => 'StubProductSourceAdapter',
    'url_template_id' => 'agenttour_group_tour_entry_v1',
];

$agentTourInstance = [
    'source_instance_id' => 'travel_b_agenttour_rechoice',
    'tenant_instance_key' => 'rechoice_agenttour',
    'tenant_sno' => $travelBSno,
    'platform_id' => 'agenttour',
    'identifier_type' => 'subdomain',
    'url_template_id' => 'agenttour_group_tour_entry_v1',
    'identifier_values' => [
        'tenant_subdomain' => 'rechoice-travel',
        'product_category' => 'group_tour',
        'region_code' => 'C',
        'region_name' => '泰國',
        'link_params' => '0000107929248116',
        'agency_source_id' => '0000107929248116',
    ],
    'enabled' => true,
    'priority' => 10,
];

$agentTourUrlTemplate = [
    'template_id' => 'agenttour_group_tour_entry_v1',
    'identifier_type' => 'subdomain',
    'scheme' => 'https',
    'host_pattern' => '{tenant_subdomain}.{platform_domain}',
    'path' => '/Index.aspx',
    'query_template' => [
        'WebArea' => 'D10T2',
        'BizType' => 'Tour',
        'RegionCode' => '{region_code}',
        'LinkParams' => '{link_params}',
    ],
    'required_identifier_keys' => [
        'tenant_subdomain',
        'region_code',
        'link_params',
    ],
];

$registry = new SearchUrlBuilderRegistry(
    ['agenttour' => $agentTourPlatform],
    ['rechoice_agenttour' => $agentTourInstance],
    ['agenttour_group_tour_entry_v1' => $agentTourUrlTemplate]
);

$searchBuilder = new ProductSourceSearchUrlBuilder($registry);

// Case 1: agenttour
$expectedUrl = 'https://rechoice-travel.agenttour.com.tw/Index.aspx?WebArea=D10T2&BizType=Tour&RegionCode=C&LinkParams=0000107929248116';

$result = $searchBuilder->buildSearchUrl([
    'product_category' => 'group_tour',
    'platform' => 'agenttour',
    'tenant_instance' => 'rechoice_agenttour',
    'keyword' => '東京',
    'region_code' => 'C',
]);

test_assert($result['search_url'] === $expectedUrl, 'case1 search_url exact match');
test_assert($result['platform'] === 'agenttour', 'case1 platform');
test_assert($result['tenant_instance'] === 'rechoice_agenttour', 'case1 tenant_instance');
test_assert($result['product_category'] === 'group_tour', 'case1 product_category');

// Case 2: missing tenant_instance
try {
    $searchBuilder->buildSearchUrl([
        'product_category' => 'group_tour',
        'platform' => 'agenttour',
        'region_code' => 'C',
    ]);
    test_assert(false, 'case2 should throw on missing tenant_instance');
} catch (SearchUrlBuilderException $e) {
    test_assert($e->getErrorCode() === 'SEARCH_URL_BUILDER_INVALID_INPUT', 'case2 error code');
}

// Case 3: unknown tenant_instance
try {
    $searchBuilder->buildSearchUrl([
        'tenant_instance' => 'unknown_instance_xyz',
        'platform' => 'agenttour',
        'region_code' => 'C',
    ]);
    test_assert(false, 'case3 should throw on unknown tenant_instance');
} catch (SearchUrlBuilderException $e) {
    test_assert($e->getErrorCode() === 'SEARCH_URL_BUILDER_UNKNOWN_INSTANCE', 'case3 error code');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_search_url_builder (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
