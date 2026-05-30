<?php
declare(strict_types=1);

/**
 * Phase 9-B-5: Tenant product sources provider factory and GCS skeleton.
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

$root = dirname(__DIR__, 2);
$tenantPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'travel_b_product_sources.sample.json';
$travelBSno = '5f99b8d665e8444d';

// 1. LocalTenantProductSourcesProvider reads sample
$local = new LocalTenantProductSourcesProvider($tenantPath);
$doc = $local->fetchTenantProductSourcesDocument();
test_assert($local->getProviderId() === 'local', 'local provider id');
test_assert($doc['tenant_sno'] === $travelBSno, 'local document tenant_sno');
test_assert(is_array($doc['enabled_sources'] ?? null) && count($doc['enabled_sources']) === 3, 'local document has enabled_sources');

// 2. Factory returns correct provider
$fromFactory = TenantProductSourcesProviderFactory::create(
    TenantProductSourcesProviderFactory::MODE_LOCAL,
    $tenantPath
);
test_assert($fromFactory->getProviderId() === 'local', 'factory local mode');
$factoryDoc = $fromFactory->fetchTenantProductSourcesDocument();
test_assert($factoryDoc['tenant_key'] === 'travel_b', 'factory local fetch tenant_key');

$defaultProvider = TenantProductSourcesProviderFactory::createDefault();
test_assert($defaultProvider instanceof LocalTenantProductSourcesProvider, 'createDefault is LocalTenantProductSourcesProvider');

$gcsProvider = TenantProductSourcesProviderFactory::create(
    TenantProductSourcesProviderFactory::MODE_GCS,
    null,
    $travelBSno
);
test_assert($gcsProvider->getProviderId() === 'gcs', 'factory gcs mode id');
$expectedPath = TenantProductSourcesGcsPathConfig::resolveObjectPath($travelBSno);
test_assert($gcsProvider->getObjectPath() === $expectedPath, 'gcs default object path uses tenants/{sno}/product_sources/...');
test_assert(
    $gcsProvider->getObjectPath() === 'tenants/' . $travelBSno . '/product_sources/product_sources.json',
    'gcs object path matches tenant product_sources layout'
);

// 3. GcsTenantProductSourcesProvider returns empty catalog (skeleton)
$emptyDoc = $gcsProvider->fetchTenantProductSourcesDocument();
test_assert($emptyDoc['tenant_sno'] === $travelBSno, 'gcs empty document tenant_sno');
test_assert($emptyDoc['enabled_sources'] === [], 'gcs fetch returns empty enabled_sources');

$safeDoc = $gcsProvider->safeFetchTenantProductSourcesDocument();
test_assert($safeDoc['enabled_sources'] === [], 'gcs safeFetch returns empty enabled_sources');

try {
    TenantProductSourcesProviderFactory::create('unknown_mode');
    test_assert(false, 'unknown factory mode should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(true, 'unknown mode throws InvalidArgumentException');
}

try {
    TenantProductSourcesProviderFactory::create(TenantProductSourcesProviderFactory::MODE_GCS);
    test_assert(false, 'gcs mode without tenant_sno should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(true, 'gcs mode requires tenant_sno');
}

// 4. GCS path config skeleton
$gcsConfig = TenantProductSourcesGcsPathConfig::load();
test_assert(
    $gcsConfig['object_path_template'] === 'tenants/{sno}/product_sources/product_sources.json',
    'gcs path config template'
);

// 5. Loader integration with default provider
$loader = new TenantProductSourceLoader();
$result = $loader->load($tenantPath);
test_assert($result['provider_id'] === 'local', 'loader reports provider_id local');
test_assert($result['tenant_key'] === 'travel_b', 'loader via provider has travel_b tenant_key');

$loaderGcs = new TenantProductSourceLoader(
    TenantProductSourcesProviderFactory::create(
        TenantProductSourcesProviderFactory::MODE_GCS,
        null,
        $travelBSno
    )
);
try {
    $loaderGcs->load();
    test_assert(false, 'loader with gcs provider should throw on empty enabled_sources');
} catch (\RuntimeException $e) {
    test_assert(strpos($e->getMessage(), 'no enabled_sources') !== false, 'loader gcs empty enabled_sources');
}

$safeResult = $loaderGcs->loadSafe();
test_assert($safeResult['provider_id'] === 'gcs', 'loader safe fallback provider_id gcs');
test_assert($safeResult['enabled_sources'] === [], 'loader safe fallback empty enabled_sources');
test_assert($safeResult['tenant_sno'] === $travelBSno, 'loader safe fallback preserves tenant_sno');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_tenant_product_sources_provider_factory (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
