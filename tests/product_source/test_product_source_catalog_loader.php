<?php
declare(strict_types=1);

/**
 * Phase 9-B-1a: ProductSourceCatalogLoader
 */

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

$loader = new ProductSourceCatalogLoader();
$root = dirname(__DIR__, 2);
$catalogPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'product_source_catalog.sample.json';

$result = $loader->load($catalogPath);

test_assert($result['schema_version'] === 1, 'schema_version is 1');
test_assert($result['catalog_id'] !== '', 'catalog_id present');

$sources = $result['sources'];
test_assert(isset($sources['bbcshops']), 'catalog has bbcshops');
test_assert(isset($sources['grp']), 'catalog has grp');
test_assert(isset($sources['bbctravel']), 'catalog has bbctravel');
test_assert(isset($sources['tourcenter']), 'catalog has tourcenter');
test_assert(count($sources) === 4, 'catalog has exactly 4 sources');

$bbcshops = $sources['bbcshops'];
test_assert($bbcshops->getSourceId() === 'bbcshops', 'bbcshops source_id');
test_assert($bbcshops->getSourceType() === 'storefront', 'bbcshops source_type');
test_assert($bbcshops->getAdapter() === 'BbcshopsAdapter', 'bbcshops adapter');
test_assert($bbcshops->getUrlTemplateId() === 'bbcshops_search_v1', 'bbcshops url_template_id');
test_assert($bbcshops->getCatalogPriority() === 5, 'bbcshops catalog priority');
test_assert($bbcshops->supportsSearch() === true, 'bbcshops supports_search');
test_assert($bbcshops->isShortUrlEnabled() === true, 'bbcshops short_url_enabled');
test_assert($bbcshops->getShortUrlDomain() === 'bbcshops.com', 'bbcshops short_url_domain');
test_assert($bbcshops->getDomainNamespace() === 'bbcshops', 'bbcshops domain_namespace');
test_assert($bbcshops->supportsGoogleSheetRegistration() === true, 'bbcshops supports_google_sheet_registration');

$grp = $sources['grp'];
test_assert($grp->supportsGoogleSheetRegistration() === false, 'grp no google sheet registration');
test_assert($grp->supportsProductCategory('group_tour') === true, 'grp group_tour category');

try {
    $loader->load($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'missing_catalog.json');
    test_assert(false, 'missing catalog should throw');
} catch (\RuntimeException $e) {
    test_assert(true, 'missing catalog throws RuntimeException');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_product_source_catalog_loader (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
