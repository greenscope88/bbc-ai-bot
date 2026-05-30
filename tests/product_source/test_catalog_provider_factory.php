<?php
declare(strict_types=1);

/**
 * Phase 9-B-4: Catalog provider factory and GCS skeleton.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'CatalogProviderFactory.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'LocalCatalogProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'GcsCatalogProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogLoader.php';

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
$samplePath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'product_source_catalog.sample.json';

// 1. LocalCatalogProvider reads sample
$local = new LocalCatalogProvider($samplePath);
$doc = $local->fetchCatalogDocument();
test_assert($local->getProviderId() === 'local', 'local provider id');
test_assert(isset($doc['schema_version']) && (int) $doc['schema_version'] === 1, 'local document schema_version');
test_assert(is_array($doc['sources'] ?? null) && count($doc['sources']) >= 4, 'local document has sources');

// 2. Factory returns correct provider
$fromFactory = CatalogProviderFactory::create(CatalogProviderFactory::MODE_LOCAL, $samplePath);
test_assert($fromFactory->getProviderId() === 'local', 'factory local mode');
$factoryDoc = $fromFactory->fetchCatalogDocument();
test_assert(isset($factoryDoc['catalog_id']), 'factory local fetch catalog_id');

$defaultProvider = CatalogProviderFactory::createDefault();
test_assert($defaultProvider instanceof LocalCatalogProvider, 'createDefault is LocalCatalogProvider');

$gcsProvider = CatalogProviderFactory::create(CatalogProviderFactory::MODE_GCS);
test_assert($gcsProvider->getProviderId() === 'gcs', 'factory gcs mode id');
test_assert($gcsProvider->getObjectPath() === GcsCatalogProvider::DEFAULT_GCS_OBJECT_PATH, 'gcs default object path');

// 3. GcsCatalogProvider not implemented
try {
    $gcsProvider->fetchCatalogDocument();
    test_assert(false, 'gcs fetch should throw');
} catch (\RuntimeException $e) {
    test_assert(strpos($e->getMessage(), 'not implemented') !== false, 'gcs not implemented message');
}

try {
    CatalogProviderFactory::create('unknown_mode');
    test_assert(false, 'unknown factory mode should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(true, 'unknown mode throws InvalidArgumentException');
}

// Loader integration with default provider
$loader = new ProductSourceCatalogLoader();
$result = $loader->load($samplePath);
test_assert($result['provider_id'] === 'local', 'loader reports provider_id local');
test_assert(isset($result['sources']['bbcshops']), 'loader via provider has bbcshops');

$loaderGcs = new ProductSourceCatalogLoader(CatalogProviderFactory::create(CatalogProviderFactory::MODE_GCS));
try {
    $loaderGcs->load();
    test_assert(false, 'loader with gcs provider should throw on load');
} catch (\RuntimeException $e) {
    test_assert(strpos($e->getMessage(), 'not implemented') !== false, 'loader gcs not implemented');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_catalog_provider_factory (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
