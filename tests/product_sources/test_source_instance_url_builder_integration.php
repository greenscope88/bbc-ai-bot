<?php
declare(strict_types=1);

/**
 * Phase 9-B-10: Source instance URL builder end-to-end integration (agenttour reference case).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourcePlatformContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TenantSourceInstanceContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceInstanceUrlTemplateBuilder.php';

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
$expectedAgentTourUrl = 'https://rechoice-travel.agenttour.com.tw/Index.aspx?WebArea=D10T2&BizType=Tour&RegionCode=C&LinkParams=0000107929248116';

// --- Layer 1: Product Category Contract ---
$productCategory = 'group_tour';
try {
    ProductCategoryContract::assertValidProductCategory($productCategory);
    test_assert(true, 'product category contract accepts group_tour');
} catch (ProductCategoryContractException $e) {
    test_assert(false, 'product category contract should accept group_tour');
}

// --- Layer 2: Source Platform Contract (config-driven fixture, not builder hardcode) ---
$agentTourPlatform = [
    'platform_id' => 'agenttour',
    'platform_code' => 'agenttour',
    'platform_name' => 'AgentTour',
    'platform_domain' => 'agenttour.com.tw',
    'source_platform_domain' => 'agenttour.com.tw',
    'platform_type' => 'external_search',
    'supported_categories' => ['group_tour', 'fit'],
    'identifier_type' => 'subdomain',
    'adapter' => 'StubProductSourceAdapter',
    'url_template_id' => 'agenttour_group_tour_entry_v1',
];

try {
    SourcePlatformContract::validatePlatform($agentTourPlatform);
    test_assert(true, 'source platform contract validates agenttour fixture');
} catch (ProductSourceContractException $e) {
    test_assert(false, 'source platform contract should validate agenttour fixture');
}

// --- Layer 3: Tenant Source Instance Contract ---
$agentTourInstance = [
    'source_instance_id' => 'travel_b_agenttour_rechoice_thailand',
    'tenant_sno' => $travelBSno,
    'platform_id' => 'agenttour',
    'identifier_type' => 'subdomain',
    'identifier_values' => [
        'tenant_subdomain' => 'rechoice-travel',
        'product_category' => $productCategory,
        'region_code' => 'C',
        'region_name' => '泰國',
        'link_params' => '0000107929248116',
        'agency_source_id' => '0000107929248116',
    ],
    'enabled' => true,
    'priority' => 10,
];

try {
    TenantSourceInstanceContract::validateInstance($agentTourInstance, $agentTourPlatform);
    test_assert(true, 'tenant source instance contract validates agenttour fixture');
} catch (ProductSourceContractException $e) {
    test_assert(false, 'tenant source instance contract should validate agenttour fixture');
}

// --- Layer 4: URL Template (template-driven) ---
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

// --- Layer 5: Source Instance URL Template Builder ---
$builder = new SourceInstanceUrlTemplateBuilder();
$result = $builder->build($agentTourPlatform, $agentTourInstance, $agentTourUrlTemplate);

test_assert($result['entry_url'] === $expectedAgentTourUrl, 'agenttour integration entry_url exact match');
test_assert($result['platform_id'] === 'agenttour', 'result platform_id');
test_assert($result['identifier_type'] === 'subdomain', 'result identifier_type');
test_assert($result['source_instance_id'] === 'travel_b_agenttour_rechoice_thailand', 'result source_instance_id');

// --- Missing parameter tests (must not emit broken URLs) ---
$missingCases = [
    ['tenant_subdomain', 'tenant_subdomain'],
    ['region_code', 'region_code'],
    ['link_params', 'link_params'],
];

foreach ($missingCases as [$label, $missingKey]) {
    $brokenInstance = $agentTourInstance;
    $brokenValues = $brokenInstance['identifier_values'];
    unset($brokenValues[$missingKey]);
    $brokenInstance['identifier_values'] = $brokenValues;

    $expectedErrorCode = $missingKey === 'tenant_subdomain'
        ? 'TENANT_SOURCE_INSTANCE_CONTRACT_INVALID'
        : 'URL_TEMPLATE_MISSING_IDENTIFIER';

    try {
        $builder->build($agentTourPlatform, $brokenInstance, $agentTourUrlTemplate);
        test_assert(false, 'missing ' . $label . ' should throw');
    } catch (ProductSourceContractException $e) {
        test_assert($e->getErrorCode() === $expectedErrorCode, 'missing ' . $label . ' error code');
        test_assert(strpos($e->getMessage(), $missingKey) !== false, 'missing ' . $label . ' error mentions key');
    }
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_source_instance_url_builder_integration (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
