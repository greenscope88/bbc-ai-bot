<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 4 isolated audit logging harness.
 * No SQL, no Host B HTTP, no production entry, no DB/file audit writes.
 */

$base = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR;

require_once $base . 'GatewayKernel.php';
require_once $base . 'TraceIdMiddleware.php';
require_once $base . 'ErrorResponseBuilder.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'TenantMappingRepository.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'TenantMappingResolver.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'ApiKeyRepository.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'ApiKeyVerifier.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'AllowedServiceResolver.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'HostBRequestBuilder.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'HostBProxyMiddleware.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'AuditLogRecordBuilder.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'AuditLogger.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function record_blob(array $record): string
{
    $j = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $j !== false ? $j : '';
}

$sink = new InMemoryAuditLogSink();
$builder = new AuditLogRecordBuilder();
$audit = new AuditLogger($sink, $builder);

$kernel = new GatewayKernel();
$kctx = $kernel->execute([]);
test_assert(isset($kctx['traceId']) && is_string($kctx['traceId']), 'setup: kernel traceId');
$traceId = (string) $kctx['traceId'];

$tenantResolver = new TenantMappingResolver(new TenantMappingRepository());
$keyVerifier = new ApiKeyVerifier(new ApiKeyRepository());
$proxy = new HostBProxyMiddleware();

$tenantOk = $tenantResolver->resolve('e1fd133c7e8e45a1');
$keyOk = $keyVerifier->verify($tenantOk, 'bbc_test_e1fdvalid01_xx', 'tour.search', '127.0.0.1');

$fixedTs = '2030-01-15T08:09:10.000Z';

// 1. Successful request audit record (chained gateway + proxy outcome)
$h1 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'POST',
    'service' => 'tour.search',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyOk,
    'tenantAllowedServices' => null,
    'jsonBody' => ['q' => 'tokyo'],
    'query' => ['page' => '1'],
]);
test_assert(($h1['ok'] ?? false) === true, '1: proxy happy path');

$r1 = $audit->log([
    'trace_id' => $traceId,
    'tenant_context' => $tenantOk['tenantContext'] ?? null,
    'authenticated_context' => $keyOk['authenticatedContext'] ?? null,
    'service' => 'tour.search',
    'request_method' => 'POST',
    'request_path' => '/api/gateway/v1/tour.search',
    'http_status' => (int) ($h1['httpStatus'] ?? 200),
    'error_code' => null,
    'client_ip' => '203.0.113.10',
    'user_agent' => 'PHPUnitCLI/0',
    'duration_ms' => 12,
    'request_body_for_summary' => ['q' => 'tokyo', 'page' => '1'],
    'response_body_for_summary' => $h1['hostbHttp']['body'] ?? [],
    'created_at' => $fixedTs,
]);
test_assert(($r1['ok'] ?? false) === true, '1: audit log ok');
$rec1 = $r1['record'] ?? [];
test_assert($rec1 !== [] && ($rec1['http_status'] ?? 0) === 200, '1: http_status 200');
test_assert(array_key_exists('error_code', $rec1) && $rec1['error_code'] === null, '1: error_code null on success');
test_assert(($rec1['trace_id'] ?? '') === $traceId, '1: trace_id');
test_assert(($rec1['sno'] ?? '') === 'e1fd133c7e8e45a1', '1: sno');
test_assert($rec1['api_key_prefix'] !== null && strpos(record_blob($rec1), 'bbc_test_e1fdvalid01_xx') === false, '1: no full key in blob');

// 2. Error response audit record (tenant failure)
$tenantBad = $tenantResolver->resolve('');
$h2 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'GET',
    'service' => 'tour.search',
    'tenantResolve' => $tenantBad,
    'keyVerify' => $keyOk,
]);
test_assert(($h2['ok'] ?? false) === false, '2: proxy fails');
$errPayload = $tenantResolver->toErrorPayload($tenantBad, $traceId);
test_assert(is_array($errPayload), '2: error payload');

$r2 = $audit->log([
    'trace_id' => $traceId,
    'tenant_context' => $tenantBad['tenantContext'] ?? null,
    'authenticated_context' => null,
    'service' => 'tour.search',
    'request_method' => 'GET',
    'request_path' => '/api/gateway/v1/tour.search',
    'http_status' => (int) ($h2['httpStatus'] ?? 400),
    'error_code' => (string) ($h2['errorCode'] ?? 'ERR'),
    'client_ip' => '198.51.100.2',
    'user_agent' => 'curl/8',
    'duration_ms' => 3,
    'request_summary' => json_encode(['reason' => 'pre-tenant'], JSON_UNESCAPED_UNICODE),
    'response_summary' => json_encode(['envelope' => $errPayload], JSON_UNESCAPED_UNICODE),
    'created_at' => $fixedTs,
]);
test_assert(($r2['ok'] ?? false) === true, '2: audit ok');
$rec2 = $r2['record'] ?? [];
test_assert((int) ($rec2['http_status'] ?? 0) === 400, '2: http_status');
test_assert(($rec2['error_code'] ?? '') === 'MISSING_SNO', '2: error_code');

// 3. Missing traceId should fail
$r3 = $audit->log([
    'trace_id' => '   ',
    'http_status' => 200,
    'duration_ms' => 1,
    'service' => 'x',
    'request_method' => 'GET',
    'request_path' => '/',
    'created_at' => $fixedTs,
]);
test_assert(($r3['ok'] ?? true) === false && ($r3['error'] ?? '') === 'MISSING_TRACE_ID', '3: missing trace');

// 4. Sensitive fields must be redacted in summaries
$r4 = $audit->log([
    'trace_id' => $traceId,
    'tenant_context' => $tenantOk['tenantContext'] ?? null,
    'authenticated_context' => $keyOk['authenticatedContext'] ?? null,
    'service' => 'order.query',
    'request_method' => 'POST',
    'request_path' => '/api/gateway/v1/order.query',
    'http_status' => 200,
    'error_code' => null,
    'client_ip' => '127.0.0.1',
    'user_agent' => 'test',
    'duration_ms' => 7,
    'request_body_for_summary' => [
        'orderId' => 'A1',
        'password' => 'must-not-leak',
        'nested' => ['access_token' => 'tok-xyz'],
    ],
    'response_body_for_summary' => ['ok' => true],
    'created_at' => $fixedTs,
]);
test_assert(($r4['ok'] ?? false) === true, '4: audit ok');
$sum4 = (string) (($r4['record'] ?? [])['request_summary'] ?? '');
test_assert(strpos($sum4, 'must-not-leak') === false, '4: password redacted');
test_assert(strpos($sum4, 'tok-xyz') === false, '4: token redacted');
test_assert(strpos($sum4, '[REDACTED]') !== false, '4: marker present');

// 5. Full API key must not appear in persisted record (path scrub)
$r5 = $audit->log([
    'trace_id' => $traceId,
    'http_status' => 500,
    'duration_ms' => 1,
    'service' => 'tour.search',
    'request_method' => 'POST',
    'request_path' => '/x?debug=bbc_test_e1fdvalid01_xx',
    'request_summary' => '{}',
    'response_summary' => '{}',
    'created_at' => $fixedTs,
]);
test_assert(($r5['ok'] ?? false) === true, '5: scrubbed path accepted');
$rp = (string) (($r5['record'] ?? [])['request_path'] ?? '');
test_assert(strpos($rp, 'bbc_test_e1fdvalid01_xx') === false, '5: full key not in path');
test_assert(strpos($rp, '[REDACTED_API_KEY]') !== false, '5: placeholder in path');
test_assert(strpos(record_blob($r5['record'] ?? []), 'bbc_test_e1fdvalid01_xx') === false, '5: full key not in record blob');

// 6. duration_ms must be numeric (stored as int)
$r6 = $audit->log([
    'trace_id' => $traceId,
    'http_status' => 204,
    'duration_ms' => 9.7,
    'service' => 'ping',
    'request_method' => 'HEAD',
    'request_path' => '/ping',
    'created_at' => $fixedTs,
]);
test_assert(($r6['ok'] ?? false) === true, '6: ok');
test_assert(array_key_exists('duration_ms', $r6['record'] ?? []) && is_int(($r6['record'] ?? [])['duration_ms']), '6: int duration');
test_assert((($r6['record'] ?? [])['duration_ms']) === 10, '6: rounded');

// 7. created_at must be UTC ISO-8601 (milliseconds + Z)
$r7 = $audit->log([
    'trace_id' => $traceId,
    'http_status' => 200,
    'duration_ms' => 0,
    'service' => 'tour.search',
    'request_method' => 'GET',
    'request_path' => '/p',
    'created_at' => 'not-a-date',
]);
test_assert(($r7['ok'] ?? true) === false, '7: bad created_at fails validation');

$r7b = $audit->log([
    'trace_id' => $traceId,
    'http_status' => 200,
    'duration_ms' => 0,
    'service' => 'tour.search',
    'request_method' => 'GET',
    'request_path' => '/p',
    'created_at' => '2030-06-01T00:00:00.123Z',
]);
test_assert(($r7b['ok'] ?? false) === true && AuditLogRecordBuilder::isUtcIso8601MillisZ((string) (($r7b['record'] ?? [])['created_at'] ?? '')), '7b: iso8601');

// 8. In-memory sink stores records without DB/file
$before = $sink->count();
test_assert($before >= 5, '8: prior records in sink');
$sink->clear();
test_assert($sink->count() === 0, '8: cleared');
$log8 = $audit->log([
    'trace_id' => $traceId,
    'tenant_context' => $tenantOk['tenantContext'] ?? null,
    'authenticated_context' => $keyOk['authenticatedContext'] ?? null,
    'service' => 'tour.search',
    'request_method' => 'POST',
    'request_path' => '/z',
    'http_status' => 200,
    'duration_ms' => 1,
    'created_at' => $fixedTs,
]);
test_assert($log8['ok'] === true, '8: logged');
test_assert($sink->count() === 1 && $sink->getRecords()[0]['trace_id'] === $traceId, '8: in-memory only');

// 9. TraceIdMiddleware alignment (external id preserved on context)
$ctx = ['traceId' => 'aabbccdd-1122-3344-5566-77889900aabb'];
TraceIdMiddleware::apply($ctx);
$tid9 = (string) ($ctx['traceId'] ?? '');
$r9 = $audit->log([
    'trace_id' => $tid9,
    'http_status' => 200,
    'duration_ms' => 2,
    'service' => 'tour.search',
    'request_method' => 'GET',
    'request_path' => '/',
    'created_at' => $fixedTs,
]);
test_assert(($r9['record']['trace_id'] ?? '') === $tid9, '9: middleware trace');

if ($failures === 0) {
    echo "OK: Audit logging Stage 4 isolated tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
