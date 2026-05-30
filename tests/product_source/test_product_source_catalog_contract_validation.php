<?php
declare(strict_types=1);

/**
 * Phase 9-B-1b: Product source catalog contract validation
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogLoader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidationException.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogContract.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function load_json_document(string $path): array
{
    $raw = file_get_contents($path);
    test_assert(is_string($raw), 'fixture readable: ' . $path);
    $decoded = json_decode((string) $raw, true);
    test_assert(is_array($decoded), 'fixture valid json: ' . $path);

    return is_array($decoded) ? $decoded : [];
}

$root = dirname(__DIR__, 2);
$fixtures = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'fixtures';
$validCatalog = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'product_source_catalog.sample.json';

$validator = new ProductSourceCatalogValidator();

// Valid sample passes
try {
    $validator->validate(load_json_document($validCatalog));
    test_assert(true, 'valid sample catalog passes contract');
} catch (ProductSourceCatalogValidationException $e) {
    test_assert(false, 'valid sample should pass: ' . $e->getMessage());
}

// Loader integrates validation
$loader = new ProductSourceCatalogLoader();
$result = $loader->load($validCatalog);
$bbcshops = $result['sources']['bbcshops'] ?? null;
test_assert($bbcshops !== null, 'loader returns bbcshops after validation');
test_assert($bbcshops->getAdapter() === 'BbcshopsAdapter', 'bbcshops adapter');
test_assert($bbcshops->getUrlTemplateId() === 'bbcshops_search_v1', 'bbcshops url_template_id');
test_assert($bbcshops->getSourceType() === 'storefront', 'bbcshops source_type enum');

// Missing adapter field
try {
    $validator->validate(load_json_document($fixtures . DIRECTORY_SEPARATOR . 'catalog_missing_adapter.json'));
    test_assert(false, 'missing adapter should fail');
} catch (ProductSourceCatalogValidationException $e) {
    test_assert($e->getErrorCode() === 'CATALOG_CONTRACT_INVALID', 'missing adapter error code');
    test_assert(count($e->getViolations()) > 0, 'missing adapter has violations');
}

// Duplicate source_id
try {
    $validator->validate(load_json_document($fixtures . DIRECTORY_SEPARATOR . 'catalog_duplicate_source_id.json'));
    test_assert(false, 'duplicate source_id should fail');
} catch (ProductSourceCatalogValidationException $e) {
    $joined = implode(' ', $e->getViolations());
    test_assert(strpos($joined, 'duplicate source_id') !== false, 'duplicate source_id violation message');
}

// Invalid product_category enum
try {
    $validator->validate(load_json_document($fixtures . DIRECTORY_SEPARATOR . 'catalog_invalid_category.json'));
    test_assert(false, 'invalid category should fail');
} catch (ProductSourceCatalogValidationException $e) {
    $joined = implode(' ', $e->getViolations());
    test_assert(strpos($joined, 'invalid product_category') !== false, 'invalid category violation');
}

// Unknown adapter
try {
    $validator->validate(load_json_document($fixtures . DIRECTORY_SEPARATOR . 'catalog_unknown_adapter.json'));
    test_assert(false, 'unknown adapter should fail');
} catch (ProductSourceCatalogValidationException $e) {
    $joined = implode(' ', $e->getViolations());
    test_assert(strpos($joined, 'unknown adapter') !== false, 'unknown adapter violation');
}

// Contract enums smoke
test_assert(ProductSourceCatalogContract::isValidProductCategory('hotel') === true, 'hotel category valid');
test_assert(ProductSourceCatalogContract::isValidProductCategory('invalid') === false, 'invalid category rejected');
test_assert(ProductSourceCatalogContract::isValidSourceType('host_b') === true, 'host_b source_type valid');
test_assert(ProductSourceCatalogContract::isKnownAdapter('GrpAdapter') === true, 'GrpAdapter known');

// Loader rejects invalid via RuntimeException wrap
try {
    $loader->load($fixtures . DIRECTORY_SEPARATOR . 'catalog_unknown_adapter.json');
    test_assert(false, 'loader should reject invalid catalog');
} catch (\RuntimeException $e) {
    test_assert(strpos($e->getMessage(), 'contract invalid') !== false, 'loader wraps contract error');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_product_source_catalog_contract_validation (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
