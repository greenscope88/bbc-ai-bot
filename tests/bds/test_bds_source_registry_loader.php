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

// -----------------------------------------------------------------------
// BBC-TENANT-BUILD-V21 — storeNo -> BdsTenantContext single-Authority Gate
// -----------------------------------------------------------------------

$travelB = BdsSourceRegistryLoader::resolveByStoreNo(6180);
test_assert(is_array($travelB), 'storeNo 6180 resolves to a context');
if (is_array($travelB)) {
  test_assert($travelB['tenant_key'] === 'travel_b', 'storeNo 6180 resolves tenant_key travel_b');
  test_assert($travelB['sno'] === '5f99b8d665e8444d', 'storeNo 6180 resolves travel_b sno');
  test_assert($travelB['store_no'] === 6180, 'storeNo 6180 context echoes store_no');
  test_assert($travelB['enabled'] === true, 'storeNo 6180 context is enabled');
  test_assert(
    $travelB['gcs_prefix'] === 'tenants/5f99b8d665e8444d/',
    'storeNo 6180 gcs_prefix unchanged (existing behavior preserved)'
  );
}

$travelD = BdsSourceRegistryLoader::resolveByStoreNo(6355);
test_assert(is_array($travelD), 'storeNo 6355 resolves to a context');
if (is_array($travelD)) {
  test_assert($travelD['tenant_key'] === 'travel_d', 'storeNo 6355 resolves tenant_key travel_d');
  test_assert($travelD['sno'] === '5fecdf66e9224bee', 'storeNo 6355 resolves travel_d sno');
  test_assert($travelD['store_no'] === 6355, 'storeNo 6355 context echoes store_no');
  test_assert($travelD['enabled'] === true, 'storeNo 6355 context is enabled');
  test_assert(
    $travelD['gcs_prefix'] === 'tenants/5fecdf66e9224bee/',
    'storeNo 6355 gcs_prefix derived from travel_d sno (no travel_b borrowing)'
  );
  test_assert(
    $travelD['private_knowledge_sheet_id'] === '',
    'travel_d upload mode does not require a fabricated private_knowledge_sheet_id'
  );
}

if (is_array($travelB) && is_array($travelD)) {
  test_assert($travelB['sno'] !== $travelD['sno'], 'travel_b and travel_d do not share sno');
  test_assert($travelB['tenant_key'] !== $travelD['tenant_key'], 'travel_b and travel_d do not share tenant_key');
  test_assert($travelB['gcs_prefix'] !== $travelD['gcs_prefix'], 'travel_b and travel_d do not share gcs_prefix');
}

$unknownStoreNo = BdsSourceRegistryLoader::resolveByStoreNo(999999);
test_assert($unknownStoreNo === null, 'unknown storeNo fails closed');

$zeroStoreNo = BdsSourceRegistryLoader::resolveByStoreNo(0);
test_assert($zeroStoreNo === null, 'storeNo 0 fails closed');

$negativeStoreNo = BdsSourceRegistryLoader::resolveByStoreNo(-1);
test_assert($negativeStoreNo === null, 'negative storeNo fails closed');

// -----------------------------------------------------------------------
// Fail-closed matching logic exercised against fixture tenants only —
// the production registry file is never mutated for these scenarios.
// -----------------------------------------------------------------------

$reflection = new ReflectionMethod('BdsSourceRegistryLoader', 'resolveStoreNoFromTenants');
$reflection->setAccessible(true);

$disabledTenants = [
  'fixture_disabled' => [
    'store_no' => 7001,
    'sno' => 'fixture-disabled-sno',
    'tenant_key' => 'fixture_disabled',
    'tenant_name' => 'Fixture Disabled',
    'enabled' => false,
  ],
];
$disabledResult = $reflection->invoke(null, $disabledTenants, 7001);
test_assert($disabledResult === null, 'disabled tenant fails closed');

$incompleteTenants = [
  'fixture_incomplete' => [
    'store_no' => 7002,
    'sno' => '',
    'tenant_key' => 'fixture_incomplete',
    'tenant_name' => 'Fixture Incomplete',
    'enabled' => true,
  ],
];
$incompleteResult = $reflection->invoke(null, $incompleteTenants, 7002);
test_assert($incompleteResult === null, 'incomplete tenant (blank sno) fails closed');

$duplicateTenants = [
  'fixture_dup_a' => [
    'store_no' => 7003,
    'sno' => 'fixture-dup-a-sno',
    'tenant_key' => 'fixture_dup_a',
    'tenant_name' => 'Fixture Dup A',
    'enabled' => true,
  ],
  'fixture_dup_b' => [
    'store_no' => 7003,
    'sno' => 'fixture-dup-b-sno',
    'tenant_key' => 'fixture_dup_b',
    'tenant_name' => 'Fixture Dup B',
    'enabled' => true,
  ],
];
$duplicateResult = $reflection->invoke(null, $duplicateTenants, 7003);
test_assert($duplicateResult === null, 'duplicate store_no binding fails closed');

$mismatchTenants = [
  'fixture_mismatch_key' => [
    'store_no' => 7004,
    'sno' => 'fixture-mismatch-sno',
    'tenant_key' => 'declared_other_key',
    'tenant_name' => 'Fixture Mismatch',
    'enabled' => true,
  ],
];
$mismatchResult = $reflection->invoke(null, $mismatchTenants, 7004);
test_assert($mismatchResult === null, 'registry key vs declared tenant_key mismatch fails closed');

// -----------------------------------------------------------------------
// Future onboarding: adding a third fixture Authority record resolves
// correctly without any Resolver or upload.php modification.
// -----------------------------------------------------------------------

$thirdTenants = [
  'travel_b_like' => [
    'store_no' => 7005,
    'sno' => 'fixture-third-sno',
    'tenant_key' => 'travel_b_like',
    'tenant_name' => 'Fixture Third Tenant',
    'industry_code' => 'travel',
    'enabled' => true,
  ],
];
$thirdResult = $reflection->invoke(null, $thirdTenants, 7005);
test_assert(is_array($thirdResult), 'future tenant onboarding resolves via data-only addition');
if (is_array($thirdResult)) {
  test_assert($thirdResult['tenant_key'] === 'travel_b_like', 'future tenant resolves its own tenant_key');
  test_assert($thirdResult['store_no'] === 7005, 'future tenant context echoes its own store_no');
}

if ($failures > 0) {
  fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
  exit(1);
}

fwrite(STDOUT, "OK: test_bds_source_registry_loader (all passed)\n");
exit(0);
