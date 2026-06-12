<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-2B — BdsSourceRegistryLoader Drive field resolution.
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.3
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
  global $failures;
  if (!$cond) {
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
  }
}

$entry = BdsSourceRegistryLoader::loadByTenantKey('travel_b');
test_assert(is_array($entry), 'travel_b entry resolves');
test_assert($entry !== null, 'travel_b entry is not null');

if (is_array($entry)) {
  test_assert($entry['tenant_key'] === 'travel_b', 'tenant_key is travel_b');
  test_assert($entry['sno'] === '5f99b8d665e8444d', 'sno matches pilot tenant');
  test_assert($entry['industry_code'] === 'travel', 'industry_code is travel');
  test_assert(
    $entry['private_knowledge_folder_id'] === '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi',
    'private_knowledge_folder_id matches onboarding folder'
  );
  test_assert(
    array_key_exists('drive_root_folder_id', $entry),
    'normalized entry includes drive_root_folder_id key'
  );
  test_assert(
    $entry['drive_root_folder_id'] === null,
    'drive_root_folder_id is null when not configured (pending)'
  );
  test_assert(
    $entry['private_knowledge_sheet_id'] !== '',
    'sheet pipeline field still present'
  );
  test_assert(
    $entry['gcs_prefix'] === 'tenants/5f99b8d665e8444d/',
    'gcs_prefix unchanged'
  );
}

$bySno = BdsSourceRegistryLoader::resolve('travel_b', '5f99b8d665e8444d');
test_assert(is_array($bySno), 'resolve by tenant_key + sno succeeds');
if (is_array($bySno)) {
  test_assert(
    $bySno['private_knowledge_folder_id'] === '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi',
    'resolve returns private_knowledge_folder_id'
  );
}

$mismatch = BdsSourceRegistryLoader::resolve('travel_b', '0000000000000000');
test_assert($mismatch === null, 'tenant_key/sno mismatch returns null');

if ($failures > 0) {
  fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
  exit(1);
}

fwrite(STDOUT, "OK: test_bds_source_registry_loader (all passed)\n");
exit(0);
