<?php
declare(strict_types=1);

/**
 * Phase 9-B-14.1: travel_b / multi-agency source instance expansion verification.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'multi_source_test_fixtures.php';

$failures = 0;

function expansion_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param list<string> $forbiddenLiterals
 */
function assert_core_file_has_no_literals(string $relativePath, array $forbiddenLiterals): void
{
    $fullPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . $relativePath;
    $source = file_get_contents($fullPath);
    expansion_assert(is_string($source), 'can read core file ' . $relativePath);

    if (!is_string($source)) {
        return;
    }

    foreach ($forbiddenLiterals as $literal) {
        $quotedSingle = "'" . $literal . "'";
        $quotedDouble = '"' . $literal . '"';
        $hasLiteral = strpos($source, $quotedSingle) !== false || strpos($source, $quotedDouble) !== false;
        expansion_assert(!$hasLiteral, $relativePath . ' must not hardcode literal ' . $literal);
    }
}

$coreFiles = [
    'MultiSourceSearchUrlBuilder.php',
    'SearchUrlBuilder.php',
    'RegionKeywordMapper.php',
    'SearchConditionContract.php',
];

$forbiddenTenantLiterals = [
    'travel_b',
    'travel_b_grp',
    'travel_b_bbctravel',
    'travel_b_private',
    'travel_b_gcs',
    'travel_c',
    'travel_d',
    'travel_e',
    'grp',
    'bbctravel',
    'gcs',
];

foreach ($coreFiles as $coreFile) {
    assert_core_file_has_no_literals($coreFile, $forbiddenTenantLiterals);
}

// Case 6: travel_b — registry-only expansion, 4 URLs
$travelBKeys = travel_b_source_instance_keys();
$builderTravelB = create_multi_source_builder($travelBKeys);
$resultsTravelB = $builderTravelB->build([
    'keyword' => '東京',
    'product_category' => 'group_tour',
]);

expansion_assert(count($resultsTravelB) === 4, 'travel_b produces 4 search URLs');
$travelBTenants = array_column($resultsTravelB, 'tenant_instance');
foreach ($travelBKeys as $key) {
    expansion_assert(in_array($key, $travelBTenants, true), 'travel_b registry includes ' . $key);
}

// Future agencies: config-only instance merge (no core edits)
$fixtures = create_multi_source_fixtures();
$baseInstances = $fixtures['instances'];

$futureAgencies = [
    'travel_c' => 'a1b2c3d4e5f6478990travelc01',
    'travel_d' => 'b2c3d4e5f6478990traveld01',
    'travel_e' => 'c3d4e5f6478990travele001',
];

foreach ($futureAgencies as $agencyPrefix => $tenantSno) {
    $mergedInstances = merge_agency_source_instances($baseInstances, $agencyPrefix, $tenantSno);
    $instanceKey = $agencyPrefix . '_grp';
    $builderFuture = create_multi_source_builder_with_instances([$instanceKey], $mergedInstances);
    $resultsFuture = $builderFuture->build([
        'keyword' => '東京',
        'product_category' => 'group_tour',
    ]);

    expansion_assert(count($resultsFuture) === 1, $agencyPrefix . ' single instance build');
    expansion_assert(
        $resultsFuture[0]['tenant_instance'] === $instanceKey,
        $agencyPrefix . ' tenant_instance key preserved'
    );
    expansion_assert($resultsFuture[0]['search_url'] !== '', $agencyPrefix . ' search_url generated');
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} expansion verification failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: multi-source expansion verification (travel_b + future agencies, core unchanged)\n");
exit(0);
