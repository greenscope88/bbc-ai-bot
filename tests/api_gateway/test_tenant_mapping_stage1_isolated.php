<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 1 isolated test harness.
 * - No SQL
 * - No Host B calls
 * - No production entry wiring
 */

$base = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR;

require_once $base . 'TraceIdMiddleware.php';
require_once $base . 'ErrorResponseBuilder.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'TenantMappingRepository.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'TenantMappingResolver.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$resolver = new TenantMappingResolver(new TenantMappingRepository());
$ctx = [];
TraceIdMiddleware::apply($ctx, []);
$traceId = (string) ($ctx['traceId'] ?? 'missing-trace-id');

// a. valid sno resolves successfully
$valid = $resolver->resolve('e1fd133c7e8e45a1');
test_assert(($valid['ok'] ?? false) === true, 'a: valid sno should resolve successfully');
test_assert(($valid['tenantContext']['provider_id_no'] ?? null) === 1, 'a: provider_id_no should be 1');
test_assert(($valid['tenantContext']['providerIdNo'] ?? null) === 1, 'a: providerIdNo should be 1');
test_assert(($valid['tenantContext']['depID'] ?? null) === 1, 'a: depID should be 1');
test_assert(($valid['tenantContext']['store_uid'] ?? null) === 1001, 'a: store_uid should be 1001');
test_assert(($valid['tenantContext']['storeUid'] ?? null) === 1001, 'a: storeUid should be 1001');
test_assert(($valid['tenantContext']['storeNo'] ?? null) === 1001, 'a: storeNo should be 1001');
test_assert(($valid['tenantContext']['enabled'] ?? null) === true, 'a: enabled should be true');
test_assert(($valid['tenantContext']['tenant_status'] ?? null) === 'active', 'a: tenant_status should be active');

// b. missing sno
$missing = $resolver->resolve('');
test_assert(($missing['ok'] ?? true) === false, 'b: missing sno should fail');
test_assert(($missing['errorCode'] ?? '') === 'MISSING_SNO', 'b: errorCode should be MISSING_SNO');
$missingPayload = $resolver->toErrorPayload($missing, $traceId);
test_assert(is_array($missingPayload), 'b: should build error payload');
test_assert(($missingPayload['traceId'] ?? '') === $traceId, 'b: payload should carry traceId');

// c. unknown sno
$unknown = $resolver->resolve('unknownsno000001');
test_assert(($unknown['ok'] ?? true) === false, 'c: unknown sno should fail');
test_assert(($unknown['errorCode'] ?? '') === 'TENANT_NOT_FOUND', 'c: errorCode should be TENANT_NOT_FOUND');

// d. disabled tenant
$disabled = $resolver->resolve('d4isabledtenant001');
test_assert(($disabled['ok'] ?? true) === false, 'd: disabled tenant should fail');
test_assert(($disabled['errorCode'] ?? '') === 'TENANT_DISABLED', 'd: errorCode should be TENANT_DISABLED');

// e. suspended tenant
$suspended = $resolver->resolve('suspendedtenant01');
test_assert(($suspended['ok'] ?? true) === false, 'e: suspended tenant should fail');
test_assert(($suspended['errorCode'] ?? '') === 'TENANT_SUSPENDED', 'e: errorCode should be TENANT_SUSPENDED');

if ($failures === 0) {
    echo "OK: Tenant Mapping Stage 1 isolated tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
