<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TenantSourceRuntimeBridge.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';

$failures = 0;

function tsrb_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

$root = dirname(__DIR__, 2);
$travelBSno = TenantSourceRuntimeBridge::TRAVEL_B_SNO;
$legacyKeys = [
    'dayitravel_grp',
    'dayitravel_bbctravel',
    'dayitravel_tourcenter',
];

$bridge = new TenantSourceRuntimeBridge();

// 1. travel_b final config: three keys via bridge or legacy fallback
$config = $bridge->resolveMultiSourceConfig($travelBSno);
tsrb_assert($config['enabled'] === true, 'travel_b enabled');
tsrb_assert($config['tenant_sno'] === $travelBSno, 'travel_b tenant_sno');
tsrb_assert($config['resolver_id'] === TenantSourceRuntimeBridge::RESOLVER_ID, 'travel_b resolver_id');
tsrb_assert($config['source_instance_keys'] === $legacyKeys, 'travel_b source_instance_keys match legacy order');

// 2. bridge-only may be partial (bbcshops not mapped to external instances); fallback covers travel_b
$bridgeOnlyKeys = $bridge->resolveBridgeSourceInstanceKeys($travelBSno);
tsrb_assert($bridgeOnlyKeys !== [], 'travel_b bridge-only keys non-empty');
tsrb_assert(in_array('dayitravel_grp', $bridgeOnlyKeys, true), 'bridge-only includes dayitravel_grp');
tsrb_assert(in_array('dayitravel_tourcenter', $bridgeOnlyKeys, true), 'bridge-only includes dayitravel_tourcenter');

// 3. unknown tenant → enabled=false
$unknown = $bridge->resolveMultiSourceConfig('00000000-0000-4000-8000-000000009999');
tsrb_assert($unknown['enabled'] === false, 'unknown tenant enabled=false');
tsrb_assert($unknown['source_instance_keys'] === [], 'unknown tenant empty keys');

// 4. registry read failure must not fatal — invalid catalog path
$brokenBridge = new TenantSourceRuntimeBridge(
    $root . DIRECTORY_SEPARATOR . 'does-not-exist' . DIRECTORY_SEPARATOR . 'catalog.json'
);
$brokenConfig = $brokenBridge->resolveMultiSourceConfig($travelBSno);
tsrb_assert($brokenConfig['enabled'] === true, 'broken catalog travel_b still enabled via fallback');
tsrb_assert($brokenConfig['source_instance_keys'] === $legacyKeys, 'broken catalog travel_b legacy keys');

// 5. legacy fallback loader
$loadedLegacy = $bridge->loadLegacyTravelBSourceInstanceKeys();
tsrb_assert($loadedLegacy === $legacyKeys, 'legacy loader keys');

// 6. TravelBMultiSourceLinkBuilder integration — three platforms, order preserved
$multiConfig = [
    'enabled' => true,
    'tenant_sno' => $travelBSno,
    'source_instance_keys' => $legacyKeys,
];
$multiBuilder = new TravelBMultiSourceLinkBuilder($multiConfig);
$condition = GeminiDerivedSearchConditionFixtures::recentTokyoCondition();
$links = $multiBuilder->buildFromHybridCondition($condition, $travelBSno);
tsrb_assert(count($links) === 3, 'link builder returns 3 links');
$platforms = array_map(static function (array $row): string {
    return (string) ($row['platform'] ?? '');
}, $links);
tsrb_assert($platforms === ['grp', 'bbctravel', 'tourcenter'], 'link builder platform order');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_tenant_source_runtime_bridge\n");
exit(0);
