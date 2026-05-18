<?php
declare(strict_types=1);

/**
 * Stage 1-B-1 CLI tests for TenantContextResolver.
 * No SQL, no Host B, no .env.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function assert_result_shape(array $result): void
{
    test_assert(array_key_exists('ok', $result), 'shape: ok');
    test_assert(array_key_exists('errorCode', $result), 'shape: errorCode');
    test_assert(array_key_exists('message', $result), 'shape: message');
    test_assert(array_key_exists('tenantContext', $result), 'shape: tenantContext');
}

$resolver = new TenantContextResolver();
$knownSno = 'e1fd133c7e8e45a1';

// 1. empty sno -> MISSING_SNO
$r1 = $resolver->resolve('   ');
assert_result_shape($r1);
test_assert(($r1['ok'] ?? true) === false && ($r1['errorCode'] ?? '') === 'MISSING_SNO', '1: empty sno');

// 2. unknown sno -> TENANT_NOT_FOUND
$r2 = $resolver->resolve('unknown_sno_00000001');
assert_result_shape($r2);
test_assert(($r2['errorCode'] ?? '') === 'TENANT_NOT_FOUND', '2: unknown sno');

// 3. staging sno resolves successfully (provider_id_no confirmed via Host B SQL JOIN)
$r3 = $resolver->resolve($knownSno);
assert_result_shape($r3);
test_assert(($r3['ok'] ?? false) === true, '3: ok');
test_assert($r3['errorCode'] === null, '3: errorCode null');
test_assert(is_array($r3['tenantContext']), '3: tenantContext not null');
$tc3 = $r3['tenantContext'];
test_assert(($tc3['sno'] ?? '') === $knownSno, '3: sno');
test_assert(($tc3['provider_id_no'] ?? null) === 102, '3: provider_id_no');
test_assert(($tc3['depID'] ?? null) === 888, '3: depID');
test_assert(($tc3['storeNo'] ?? null) === 6290, '3: storeNo');
test_assert(($tc3['store_uid'] ?? null) === 6290, '3: store_uid');

// 4. depID / storeNo / store_uid exist in staging config
$map = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_context_map.php';
$row = $map[$knownSno] ?? [];
test_assert(($row['depID'] ?? null) === 888, '4: config depID');
test_assert(($row['storeNo'] ?? null) === 6290, '4: config storeNo');
test_assert(($row['store_uid'] ?? null) === 6290, '4: config store_uid');

// 5. will not return mock provider_id_no=1
$r5 = (new TenantContextResolver([
    'test_sno_provider1' => [
        'depID' => 888,
        'storeNo' => 6290,
        'store_uid' => 6290,
        'provider_id_no' => 1,
    ],
]))->resolve('test_sno_provider1');
test_assert(($r5['ok'] ?? true) === false, '5: provider 1 not success');
test_assert(($r5['errorCode'] ?? '') === 'TENANT_MAPPING_INCOMPLETE_PROVIDER', '5: provider 1 rejected');
test_assert(($r5['tenantContext']['provider_id_no'] ?? null) !== 1, '5: no tenantContext with provider 1');

// 6. success path shape (injected complete map, not mock 1)
$r6 = (new TenantContextResolver([
    'complete_sno_01' => [
        'depID' => 888,
        'storeNo' => 6290,
        'store_uid' => 6290,
        'provider_id_no' => 42,
    ],
]))->resolve('complete_sno_01');
assert_result_shape($r6);
test_assert(($r6['ok'] ?? false) === true, '6: success ok');
test_assert($r6['errorCode'] === null, '6: errorCode null');
test_assert(is_array($r6['tenantContext']), '6: tenantContext array');
$tc = $r6['tenantContext'];
test_assert(($tc['sno'] ?? '') === 'complete_sno_01', '6: sno');
test_assert(($tc['provider_id_no'] ?? null) === 42, '6: provider_id_no');
test_assert(($tc['provider_id_no'] ?? null) !== 1, '6: not mock 1');

// extra: incomplete depID via override
$rDep = (new TenantContextResolver([
    'incomplete_dep' => [
        'depID' => null,
        'storeNo' => 6290,
        'store_uid' => 6290,
        'provider_id_no' => 42,
    ],
]))->resolve('incomplete_dep');
test_assert(($rDep['errorCode'] ?? '') === 'TENANT_MAPPING_INCOMPLETE_DEPID', 'extra: incomplete depID');

if ($failures === 0) {
    echo "OK: TenantContextResolver tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
