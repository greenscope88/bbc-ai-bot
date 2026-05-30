<?php
declare(strict_types=1);

/**
 * Phase 9-B-7: Multi product source category contract.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidationException.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$validCategories = [
    'group_tour',
    'fit',
    'flight',
    'hotel',
    'mini_group',
    'car_rental',
    'cruise',
    'visa',
    'ticket',
    'other',
];

foreach ($validCategories as $category) {
    test_assert(ProductCategoryContract::isValidProductCategory($category), 'valid product_category: ' . $category);
    test_assert(ProductSourceCatalogContract::isValidProductCategory($category), 'catalog contract accepts: ' . $category);

    try {
        ProductCategoryContract::assertValidProductCategory($category);
        test_assert(true, 'assertValidProductCategory passes: ' . $category);
    } catch (ProductCategoryContractException $e) {
        test_assert(false, 'assertValidProductCategory should pass: ' . $category);
    }

    $sourceCategory = ProductCategoryContract::resolveSourceCategory($category);
    test_assert(ProductCategoryContract::isValidSourceCategory($sourceCategory), 'resolved source_category for: ' . $category);

    $adapter = ProductCategoryContract::getDefaultAdapter($category);
    test_assert(is_string($adapter) && $adapter !== '', 'default adapter hint for: ' . $category);
}

// Backward compatibility: private_group alias -> mini_group
test_assert(ProductCategoryContract::isValidProductCategory('private_group'), 'legacy private_group alias valid');
test_assert(ProductCategoryContract::normalizeProductCategory('private_group') === 'mini_group', 'private_group normalizes to mini_group');
test_assert(ProductSourceCatalogContract::isValidProductCategory('private_group'), 'catalog contract accepts private_group');

// Unknown category must fail
test_assert(!ProductCategoryContract::isValidProductCategory('cruise_tour'), 'unknown cruise_tour invalid');
test_assert(!ProductSourceCatalogContract::isValidProductCategory('unknown_category_xyz'), 'catalog contract rejects unknown');

try {
    ProductCategoryContract::assertValidProductCategory('invalid_category_xyz');
    test_assert(false, 'assertValidProductCategory should throw for unknown');
} catch (ProductCategoryContractException $e) {
    test_assert($e->getErrorCode() === 'INVALID_PRODUCT_CATEGORY', 'unknown category error code');
    test_assert(strpos($e->getMessage(), 'invalid_category_xyz') !== false, 'unknown category error message');
}

try {
    ProductCategoryContract::resolveSourceCategory('invalid_category_xyz');
    test_assert(false, 'resolveSourceCategory should throw for unknown');
} catch (ProductCategoryContractException $e) {
    test_assert($e->getErrorCode() === 'INVALID_PRODUCT_CATEGORY', 'resolveSourceCategory error code');
}

// Source category enum coverage
foreach (ProductCategoryContract::SOURCE_CATEGORIES as $sourceCategory) {
    test_assert(ProductCategoryContract::isValidSourceCategory($sourceCategory), 'valid source_category: ' . $sourceCategory);
}
test_assert(!ProductCategoryContract::isValidSourceCategory('invalid_source_category'), 'invalid source_category rejected');

// Catalog validator integrates expanded category enum (fixture still invalid)
$root = dirname(__DIR__, 2);
$fixtures = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'fixtures';
$invalidCategoryFixture = $fixtures . DIRECTORY_SEPARATOR . 'catalog_invalid_category.json';
$validator = new ProductSourceCatalogValidator();

try {
    $raw = file_get_contents($invalidCategoryFixture);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $validator->validate($decoded);
    }
    test_assert(false, 'catalog validator still rejects invalid fixture category');
} catch (ProductSourceCatalogValidationException $e) {
    test_assert($e->getErrorCode() === 'CATALOG_CONTRACT_INVALID', 'catalog validator invalid category error code');
}

// cruise is now valid at contract level (future catalog entries)
test_assert(ProductCategoryContract::resolveSourceCategory('cruise') === 'transport', 'cruise maps to transport source_category');
test_assert(ProductCategoryContract::getDefaultAdapter('flight') === 'StubProductSourceAdapter', 'flight default adapter hint');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_product_category_contract (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
