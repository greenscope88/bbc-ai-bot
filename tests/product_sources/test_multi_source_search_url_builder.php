<?php
declare(strict_types=1);

/**
 * Phase 9-B-14: Multi-source search URL builder.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'multi_source_test_fixtures.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$fixtures = create_multi_source_fixtures();

// Case 1: keyword=東京, 2 instances
$builderCase1 = create_multi_source_builder(['rechoice_agenttour', 'dayitravel_grp']);
$resultsCase1 = $builderCase1->build([
    'keyword' => '東京',
    'product_category' => 'group_tour',
]);

test_assert(count($resultsCase1) === 2, 'case1 returns 2 URLs');
$tenantKeysCase1 = array_column($resultsCase1, 'tenant_instance');
test_assert(in_array('rechoice_agenttour', $tenantKeysCase1, true), 'case1 includes rechoice_agenttour');
test_assert(in_array('dayitravel_grp', $tenantKeysCase1, true), 'case1 includes dayitravel_grp');

$agenttourRow = null;
foreach ($resultsCase1 as $row) {
    if ($row['tenant_instance'] === 'rechoice_agenttour') {
        $agenttourRow = $row;
        break;
    }
}
test_assert($agenttourRow !== null, 'case1 agenttour row exists');
test_assert(
    $agenttourRow !== null && strpos($agenttourRow['search_url'], 'RegionCode=J') !== false,
    'case1 agenttour uses platform-scoped RegionCode J for 東京'
);
test_assert(
    $agenttourRow !== null && $agenttourRow['platform'] === 'agenttour',
    'case1 agenttour platform id'
);

// Case 2: keyword=泰國, 3 instances
$builderCase2 = create_multi_source_builder([
    'rechoice_agenttour',
    'dayitravel_grp',
    'dayitravel_bbctravel',
]);
$resultsCase2 = $builderCase2->build([
    'keyword' => '泰國',
    'product_category' => 'group_tour',
]);

test_assert(count($resultsCase2) === 3, 'case2 returns 3 URLs');
$tenantKeysCase2 = array_column($resultsCase2, 'tenant_instance');
test_assert(in_array('rechoice_agenttour', $tenantKeysCase2, true), 'case2 rechoice_agenttour');
test_assert(in_array('dayitravel_grp', $tenantKeysCase2, true), 'case2 dayitravel_grp');
test_assert(in_array('dayitravel_bbctravel', $tenantKeysCase2, true), 'case2 dayitravel_bbctravel');

foreach ($resultsCase2 as $row) {
    if ($row['tenant_instance'] === 'rechoice_agenttour') {
        test_assert(strpos($row['search_url'], 'RegionCode=C') !== false, 'case2 agenttour RegionCode=C for 泰國');
    }
}

// Case 3: empty registry
$emptySourceRegistry = new MultiSourceSearchUrlBuilderRegistry();
$builderCase3 = new MultiSourceSearchUrlBuilder($emptySourceRegistry, $fixtures['searchUrlRegistry']);
try {
    $builderCase3->build(['keyword' => '東京', 'product_category' => 'group_tour']);
    test_assert(false, 'case3 should fail on empty registry');
} catch (MultiSourceSearchUrlBuilderException $e) {
    test_assert(
        $e->getErrorCode() === MultiSourceSearchUrlBuilderException::NO_SOURCE_INSTANCE,
        'case3 MULTI_SOURCE_NO_SOURCE_INSTANCE'
    );
}

// Case 4: unknown keyword must not fail entire builder
$builderCase4 = create_multi_source_builder(['rechoice_agenttour', 'dayitravel_grp']);
try {
    $resultsCase4 = $builderCase4->build([
        'keyword' => '火星旅遊',
        'product_category' => 'group_tour',
    ]);
    test_assert(count($resultsCase4) === 2, 'case4 returns 2 URLs despite unknown agenttour keyword');
    foreach ($resultsCase4 as $row) {
        test_assert($row['search_url'] !== '', 'case4 each search_url non-empty');
    }
    test_assert(true, 'case4 unknown keyword does not abort multi-source build PASS');
} catch (Throwable $e) {
    test_assert(false, 'case4 should not throw: ' . $e->getMessage());
}

// Case 5: generic platform instances (no hardcoded platform names in builder core)
$builderCase5 = create_multi_source_builder([
    'travel_b_grp',
    'travel_b_private',
    'travel_b_gcs',
]);
$resultsCase5 = $builderCase5->build([
    'keyword' => '東京',
    'product_category' => 'group_tour',
]);

test_assert(count($resultsCase5) === 3, 'case5 returns 3 URLs');
$platformsCase5 = array_column($resultsCase5, 'platform');
test_assert(in_array('ext_search_alpha', $platformsCase5, true), 'case5 ext_search_alpha');
test_assert(in_array('ext_search_beta', $platformsCase5, true), 'case5 ext_search_beta');
test_assert(in_array('ext_search_gamma', $platformsCase5, true), 'case5 ext_search_gamma');
test_assert(!in_array('agenttour', $platformsCase5, true), 'case5 registry not limited to agenttour');

$coreRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR;
$multiSourceCore = file_get_contents($coreRoot . 'MultiSourceSearchUrlBuilder.php');
test_assert(is_string($multiSourceCore), 'case5 can read MultiSourceSearchUrlBuilder source');
test_assert(
    is_string($multiSourceCore)
    && strpos($multiSourceCore, "'agenttour'") === false
    && strpos($multiSourceCore, "'grp'") === false
    && strpos($multiSourceCore, "'bbctravel'") === false,
    'case5 builder core has no hardcoded platform names'
);

// Case 6 (9-B-14.1): travel_b full source instance set
$travelBKeys = travel_b_source_instance_keys();
$builderCase6 = create_multi_source_builder($travelBKeys);
$resultsCase6 = $builderCase6->build([
    'keyword' => '東京',
    'product_category' => 'group_tour',
]);

test_assert(count($resultsCase6) === 4, 'case6 travel_b returns 4 URLs');
$tenantKeysCase6 = array_column($resultsCase6, 'tenant_instance');
foreach ($travelBKeys as $expectedKey) {
    test_assert(in_array($expectedKey, $tenantKeysCase6, true), 'case6 includes ' . $expectedKey);
}

foreach ($resultsCase6 as $row) {
    test_assert($row['search_url'] !== '', 'case6 search_url non-empty for ' . $row['tenant_instance']);
    $instance = $fixtures['instances'][$row['tenant_instance']] ?? null;
    test_assert(
        is_array($instance) && ($instance['tenant_sno'] ?? '') === MULTI_SOURCE_TRAVEL_B_SNO,
        'case6 tenant_sno travel_b for ' . $row['tenant_instance']
    );
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "All multi-source search URL builder tests passed.\n");
exit(0);
