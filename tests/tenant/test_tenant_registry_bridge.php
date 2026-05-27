<?php
declare(strict_types=1);

/**
 * Phase 2A Stage 2: TenantResolver / TenantContextResolver registry bridge.
 * No Host B, LINE, or Gemini.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant_resolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function assert_tenant_shape(array $tenant): void
{
    test_assert(array_key_exists('sno', $tenant), 'tenant shape: sno');
    test_assert(array_key_exists('company_name', $tenant), 'tenant shape: company_name');
    test_assert(array_key_exists('ai_tone', $tenant), 'tenant shape: ai_tone');
    test_assert(array_key_exists('travel_specialties', $tenant), 'tenant shape: travel_specialties');
    test_assert(array_key_exists('price_catalog_json', $tenant), 'tenant shape: price_catalog_json');
    test_assert(array_key_exists('channel_id', $tenant), 'tenant shape: channel_id');
}

$stagingChannel = 'Ufcedee37a93230a802c30b138f6228f8';
$stagingSno = 'e1fd133c7e8e45a1';

// 1. travel_a channel → registry (via TenantResolver with mock PDO — DB not reached)
$event = ['destination' => $stagingChannel];
$pdo = new PDO('sqlite::memory:');
$config = [];
$tenant = TenantResolver::resolve($pdo, $event, $config);
assert_tenant_shape($tenant);
test_assert($tenant['sno'] === $stagingSno, 'travel_a sno via resolver');
test_assert($tenant['channel_id'] === $stagingChannel, 'travel_a channel_id');
test_assert($tenant['company_name'] === '旅行社客服', 'travel_a company_name from registry profile');

// Compare with legacy map-only shape for same channel
$legacyMap = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_context_map.php';
$legacySno = $legacyMap[$stagingChannel]['sno'] ?? '';
test_assert($tenant['sno'] === $legacySno, 'registry sno matches legacy map sno');

// 2. unknown channel → empty sno (legacy empty / no DB in sqlite without tables)
$unknownEvent = ['destination' => 'U_UNKNOWN_CHANNEL_BRIDGE_TEST'];
$unknownTenant = TenantResolver::resolve($pdo, $unknownEvent, $config);
assert_tenant_shape($unknownTenant);
test_assert($unknownTenant['sno'] === '', 'unknown channel → empty sno fallback shape');
test_assert($unknownTenant['channel_id'] === 'U_UNKNOWN_CHANNEL_BRIDGE_TEST', 'unknown channel_id preserved');

// 3. resolveBySno depID/storeNo via TenantContextResolver
$resolver = new TenantContextResolver();
$ctxResult = $resolver->resolve($stagingSno);
test_assert(($ctxResult['ok'] ?? false) === true, 'staging sno context ok');
$tc = $ctxResult['tenantContext'] ?? [];
test_assert(is_array($tc), 'tenantContext is array');
test_assert(($tc['sno'] ?? '') === $stagingSno, 'context sno');
test_assert(($tc['depID'] ?? null) === 888, 'context depID');
test_assert(($tc['storeNo'] ?? null) === 6290, 'context storeNo');
test_assert(($tc['store_uid'] ?? null) === 6290, 'context store_uid');
test_assert(($tc['provider_id_no'] ?? null) === 102, 'context provider_id_no');

// 4. runtime context shape keys unchanged
foreach (['sno', 'depID', 'storeNo', 'store_uid', 'provider_id_no'] as $key) {
    test_assert(array_key_exists($key, $tc), 'tenantContext key: ' . $key);
}

// 5. registry miss on sno falls back to legacy map
$unknownCtx = $resolver->resolve('unknown-sno-bridge-test-0001');
test_assert(($unknownCtx['ok'] ?? true) === false, 'unknown sno not ok');
test_assert(($unknownCtx['errorCode'] ?? '') === 'TENANT_NOT_FOUND', 'unknown sno TENANT_NOT_FOUND');

// 6. registry-only sno (travel_b placeholder) — shape contract preserved
$registry = new ConfigTenantRegistry();
$resolverInjected = new TenantContextResolver(null, null, $registry);
$bSno = '00000000-0000-4000-8000-0000000000b1';
$bCtx = $resolverInjected->resolve($bSno);
test_assert(($bCtx['ok'] ?? false) === true, 'travel_b registry context ok');
test_assert(($bCtx['tenantContext']['sno'] ?? '') === $bSno, 'travel_b sno');
test_assert(array_key_exists('depID', $bCtx['tenantContext'] ?? []), 'travel_b depID key present');

if ($failures === 0) {
    echo "OK: tenant registry bridge tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
