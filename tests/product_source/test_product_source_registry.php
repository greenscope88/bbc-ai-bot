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

$defaultSample = ProductSourceRegistry::fromLocalFiles();
test_assert($defaultSample->getTenantSno() === '5f99b8d665e8444d', 'default sample paths resolve travel_b');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_product_source_registry (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
