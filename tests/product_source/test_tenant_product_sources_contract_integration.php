<?php
declare(strict_types=1);

/**
 * Phase 9-B-6: Tenant product sources Local/GCS provider contract integration test.
 *
 * Validates provider document shape, loader normalization, path config, and factory error handling.
 * No real GCS, no credentials, no production flow wiring.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TenantProductSourcesProviderFactory.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'LocalTenantProductSourcesProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'GcsTenantProductSourcesProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TenantProductSourcesGcsPathConfig.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TenantProductSourceLoader.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param array<string, mixed> $doc
 */
function assert_provider_document_contract(array $doc, string $context): void
{
    test_assert(isset($doc['schema_version']) && is_int($doc['schema_version']) || is_numeric($doc['schema_version']), $context . ' schema_version');
    test_assert(isset($doc['tenant_sno']) && is_string($doc['tenant_sno']) && trim($doc['tenant_sno']) !== '', $context . ' tenant_sno');
    test_assert(isset($doc['tenant_key']) && is_string($doc['tenant_key']), $context . ' tenant_key');
    test_assert(isset($doc['default_category']) && is_string($doc['default_category']) && trim($doc['default_category']) !== '', $context . ' default_category');
    test_assert(isset($doc['enabled_sources']) && is_array($doc['enabled_sources']), $context . ' enabled_sources array');
}

/**
 * @param array<string, mixed> $result
 */
function assert_loader_result_contract(array $result, string $context): void
{
    test_assert(isset($result['tenant_sno']) && is_string($result['tenant_sno']) && trim($result['tenant_sno']) !== '', $context . ' loader tenant_sno (sno)');
    test_assert(isset($result['provider_id']) && is_string($result['provider_id']) && trim($result['provider_id']) !== '', $context . ' loader provider_id');
    test_assert(isset($result['enabled_sources']) && is_array($result['enabled_sources']), $context . ' loader enabled_sources');
    test_assert(array_key_exists('schema_version', $result), $context . ' loader schema_version');
    test_assert(array_key_exists('tenant_key', $result), $context . ' loader tenant_key');
    test_assert(array_key_exists('default_category', $result), $context . ' loader default_category');

    $sourceCount = count($result['enabled_sources']);
    test_assert($sourceCount >= 0, $context . ' loader source_count equivalent >= 0');

    foreach ($result['enabled_sources'] as $index => $row) {
        if (!is_array($row)) {
            test_assert(false, $context . ' enabled_sources row ' . $index . ' is array');
            continue;
        }
        test_assert(isset($row['source_id']) && is_string($row['source_id']) && trim($row['source_id']) !== '', $context . ' enabled_sources row source_id');
        test_assert(isset($row['priority']) && is_int($row['priority']), $context . ' enabled_sources row priority');
        test_assert(isset($row['product_categories']) && is_array($row['product_categories']), $context . ' enabled_sources row product_categories');
    }
}

/**
 * @param array<string, mixed> $result
 */
function source_count(array $result): int
{
    return count($result['enabled_sources']);
}

$root = dirname(__DIR__, 2);
$tenantPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'travel_b_product_sources.sample.json';
$travelBSno = '5f99b8d665e8444d';

// 1. LocalTenantProductSourcesProvider raw document contract
$localProvider = new LocalTenantProductSourcesProvider($tenantPath);
$localDoc = $localProvider->fetchTenantProductSourcesDocument();
test_assert($localProvider->getProviderId() === 'local', 'local provider_id is local');
assert_provider_document_contract($localDoc, 'local raw');
test_assert($localDoc['tenant_sno'] === $travelBSno, 'local raw tenant_sno matches travel_b');
test_assert($localDoc['tenant_key'] === 'travel_b', 'local raw tenant_key');
test_assert(count($localDoc['enabled_sources']) === 3, 'local raw enabled_sources count');

$firstEnabled = $localDoc['enabled_sources'][0] ?? null;
test_assert(is_array($firstEnabled) && isset($firstEnabled['source_id']), 'local raw enabled_sources row has source_id');

// 2. Local provider -> loader normalized contract
$localLoader = new TenantProductSourceLoader(
    TenantProductSourcesProviderFactory::create(
        TenantProductSourcesProviderFactory::MODE_LOCAL,
        $tenantPath
    )
);
$localResult = $localLoader->load();
assert_loader_result_contract($localResult, 'local loader');
test_assert($localResult['provider_id'] === 'local', 'local loader provider_id');
test_assert($localResult['tenant_sno'] === $travelBSno, 'local loader sno');
test_assert(source_count($localResult) === 3, 'local loader source_count equivalent');
test_assert($localResult['enabled_sources'][0]['source_id'] === 'bbcshops', 'local loader priority sort');

// 3. GcsTenantProductSourcesProvider skeleton empty enabled_sources contract
$gcsProvider = TenantProductSourcesProviderFactory::create(
    TenantProductSourcesProviderFactory::MODE_GCS,
    null,
    $travelBSno
);
$gcsDoc = $gcsProvider->fetchTenantProductSourcesDocument();
test_assert($gcsProvider->getProviderId() === 'gcs', 'gcs provider_id is gcs');
assert_provider_document_contract($gcsDoc, 'gcs raw');
test_assert($gcsDoc['enabled_sources'] === [], 'gcs raw empty enabled_sources');
test_assert($gcsDoc['tenant_sno'] === $travelBSno, 'gcs raw tenant_sno preserved');

$gcsSafeDoc = $gcsProvider->safeFetchTenantProductSourcesDocument();
test_assert($gcsSafeDoc['enabled_sources'] === [], 'gcs safeFetch empty enabled_sources');

// 4. TenantProductSourceLoader::loadSafe() fallback contract
$gcsLoader = new TenantProductSourceLoader($gcsProvider);
try {
    $gcsLoader->load();
    test_assert(false, 'gcs loader load() should throw on empty enabled_sources');
} catch (\RuntimeException $e) {
    test_assert(strpos($e->getMessage(), 'enabled_sources') !== false, 'gcs loader load() explicit error');
}

$safeResult = $gcsLoader->loadSafe();
assert_loader_result_contract($safeResult, 'gcs loadSafe');
test_assert($safeResult['provider_id'] === 'gcs', 'gcs loadSafe provider_id');
test_assert($safeResult['tenant_sno'] === $travelBSno, 'gcs loadSafe sno preserved');
test_assert(source_count($safeResult) === 0, 'gcs loadSafe source_count equivalent is 0');
test_assert($safeResult['enabled_sources'] === [], 'gcs loadSafe empty enabled_sources');
test_assert($safeResult['default_category'] === 'group_tour', 'gcs loadSafe default_category fallback');

// 5. TenantProductSourcesGcsPathConfig path contract
$resolvedPath = TenantProductSourcesGcsPathConfig::resolveObjectPath($travelBSno);
test_assert(
    $resolvedPath === 'tenants/' . $travelBSno . '/product_sources/product_sources.json',
    'path config resolves tenants/{sno}/product_sources/product_sources.json'
);
test_assert($gcsProvider->getObjectPath() === $resolvedPath, 'gcs provider object path matches path config');

$gcsConfig = TenantProductSourcesGcsPathConfig::load();
test_assert(
    $gcsConfig['object_path_template'] === TenantProductSourcesGcsPathConfig::DEFAULT_OBJECT_PATH_TEMPLATE,
    'path config template loaded from skeleton config'
);
test_assert($gcsConfig['bucket_placeholder'] === 'bbc-travel-data-center', 'path config bucket placeholder');

try {
    TenantProductSourcesGcsPathConfig::resolveObjectPath('');
    test_assert(false, 'empty tenant_sno path resolve should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(strpos($e->getMessage(), 'tenant_sno') !== false, 'empty tenant_sno path resolve explicit error');
}

// 6. Factory invalid mode must fail with explicit InvalidArgumentException
try {
    TenantProductSourcesProviderFactory::create('invalid_mode_xyz');
    test_assert(false, 'invalid factory mode should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(strpos($e->getMessage(), 'Unknown tenant product sources provider mode') !== false, 'invalid factory mode message');
}

try {
    TenantProductSourcesProviderFactory::create(TenantProductSourcesProviderFactory::MODE_GCS);
    test_assert(false, 'gcs factory without tenant_sno should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(strpos($e->getMessage(), 'tenant_sno is required') !== false, 'gcs factory missing tenant_sno message');
}

// 7. Cross-provider integration: factory default -> loader via explicit path override
$defaultLoader = new TenantProductSourceLoader();
$overrideResult = $defaultLoader->load($tenantPath);
assert_loader_result_contract($overrideResult, 'default loader path override');
test_assert($overrideResult['provider_id'] === 'local', 'path override uses local provider_id');
test_assert(source_count($overrideResult) === 3, 'path override source_count equivalent');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_tenant_product_sources_contract_integration (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
