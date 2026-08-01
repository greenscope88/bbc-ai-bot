<?php
declare(strict_types=1);

/**
 * Phase 9-B-1a: ProductSourceRegistry
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceRegistry.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$root = dirname(__DIR__, 2);
$catalogPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'product_source_catalog.sample.json';
$tenantPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'travel_b_product_sources.sample.json';

$registry = ProductSourceRegistry::fromLocalFiles($catalogPath, $tenantPath);

test_assert($registry->getTenantSno() === '5f99b8d665e8444d', 'registry tenant sno');
test_assert($registry->getTenantKey() === 'travel_b', 'registry tenant_key');
test_assert(count($registry->getAllCatalogSources()) === 4, 'all catalog sources count');

$enabledIds = $registry->getEnabledSourceIds();
test_assert(count($enabledIds) === 3, 'enabled source count for travel_b');
test_assert($enabledIds === ['bbcshops', 'grp', 'tourcenter'], 'enabled ids order by priority');

$grp = $registry->getEnabledSource('grp');
test_assert($grp !== null && $grp->getDisplayName() !== '', 'getEnabledSource grp');
test_assert($registry->getEnabledSource('bbctravel') === null, 'bbctravel not enabled for tenant');

$byCategory = $registry->getEnabledSourcesByCategory('group_tour');
test_assert(count($byCategory) === 3, 'group_tour enabled count');

$fitOnly = $registry->getEnabledSourcesByCategory('fit');
test_assert(count($fitOnly) === 0, 'fit not enabled for travel_b tenant rows');

$bbctravelCatalog = $registry->getCatalogSource('bbctravel');
test_assert($bbctravelCatalog !== null && $bbctravelCatalog->supportsProductCategory('fit') === true, 'bbctravel in catalog supports fit');

// --- Tenant-Scoped Product-Set Registry: ProductSourceRegistry::forTenant() ---
// tenant_sno is the sole authority for canonical config selection; no fixed default,
// no tenant-specific map, no cross-tenant fallback.
$travelBCanonical = ProductSourceRegistry::forTenant('5f99b8d665e8444d');
test_assert($travelBCanonical->getTenantSno() === '5f99b8d665e8444d', 'forTenant() resolves travel_b canonical config');
test_assert($travelBCanonical->getTenantKey() === 'travel_b', 'forTenant() travel_b tenant_key');
test_assert($travelBCanonical->getEnabledSourceIds() === ['bbcshops', 'grp', 'tourcenter'], 'forTenant() travel_b enabled sources match golden config');

$travelDCanonical = ProductSourceRegistry::forTenant('5fecdf66e9224bee');
test_assert($travelDCanonical->getTenantSno() === '5fecdf66e9224bee', 'forTenant() resolves travel_d canonical config');
test_assert($travelDCanonical->getTenantKey() === 'travel_d', 'forTenant() travel_d tenant_key');
test_assert($travelDCanonical->getEnabledSourceIds() === ['bbcshops', 'grp', 'tourcenter'], 'forTenant() travel_d enabled sources mirror travel_b golden config');
test_assert(
    $travelDCanonical->getCatalogId() . ':' . $travelDCanonical->getTenantKey()
        !== $travelBCanonical->getCatalogId() . ':' . $travelBCanonical->getTenantKey(),
    'forTenant() travel_d search domain distinct from travel_b (no cross-tenant identity bleed)'
);

try {
    ProductSourceRegistry::forTenant('');
    test_assert(false, 'forTenant() with blank tenant_sno should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(true, 'forTenant() blank tenant_sno throws InvalidArgumentException');
}

try {
    ProductSourceRegistry::forTenant('tenant-with-no-canonical-config');
    test_assert(false, 'forTenant() with unregistered tenant_sno should throw (missing config file)');
} catch (\RuntimeException $e) {
    test_assert(true, 'forTenant() unregistered tenant_sno fails closed with RuntimeException');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_product_source_registry (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
