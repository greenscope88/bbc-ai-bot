<?php
declare(strict_types=1);

/**
 * Phase 9-B-1a: TenantProductSourceLoader
 */

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

$loader = new TenantProductSourceLoader();
$root = dirname(__DIR__, 2);
$tenantPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'travel_b_product_sources.sample.json';

$result = $loader->load($tenantPath);

test_assert($result['tenant_sno'] === '5f99b8d665e8444d', 'travel_b sno');
test_assert($result['tenant_key'] === 'travel_b', 'travel_b tenant_key');
test_assert($result['default_category'] === 'group_tour', 'default_category group_tour');

$enabled = $result['enabled_sources'];
test_assert(count($enabled) === 3, 'travel_b has 3 enabled sources');

$ids = array_map(static function (array $row): string {
    return $row['source_id'];
}, $enabled);

test_assert(in_array('bbcshops', $ids, true), 'enabled includes bbcshops');
test_assert(in_array('grp', $ids, true), 'enabled includes grp');
test_assert(in_array('tourcenter', $ids, true), 'enabled includes tourcenter');
test_assert(!in_array('bbctravel', $ids, true), 'bbctravel not enabled for travel_b');

test_assert($enabled[0]['source_id'] === 'bbcshops', 'lowest priority first (bbcshops priority 5)');
test_assert($enabled[2]['source_id'] === 'tourcenter', 'highest priority last (tourcenter 30)');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_tenant_product_source_loader (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
