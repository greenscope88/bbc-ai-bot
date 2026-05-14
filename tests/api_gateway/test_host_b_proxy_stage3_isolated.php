<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 3 isolated test harness (Host B proxy path).
 * No SQL, no real Host B HTTP, no production entry wiring.
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

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tenantResolver = new TenantMappingResolver(new TenantMappingRepository());
$keyVerifier = new ApiKeyVerifier(new ApiKeyRepository());
$resolver = new AllowedServiceResolver();
$builder = new HostBRequestBuilder();

$kernel = new GatewayKernel();
$kctx = $kernel->execute([]);
test_assert(isset($kctx['traceId']) && is_string($kctx['traceId']), 'setup: GatewayKernel sets traceId');
$traceId = (string) $kctx['traceId'];

$tenantOk = $tenantResolver->resolve('e1fd133c7e8e45a1');
$keyOk = $keyVerifier->verify($tenantOk, 'bbc_test_e1fdvalid01_xx', 'tour.search', '127.0.0.1');

$proxy = new HostBProxyMiddleware();

// 1. Happy path + GatewayKernel trace
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
test_assert(($h1['ok'] ?? false) === true, '1: happy path ok');
$hr = $h1['hostbRequest'] ?? null;
test_assert(is_array($hr), '1: hostbRequest present');
test_assert(($hr['path'] ?? '') !== '' && strpos((string) ($hr['path'] ?? ''), 'http') === false, '1: path is relative (no http)');
$hb = $h1['hostbHttp'] ?? null;
test_assert(is_array($hb) && (int) ($hb['httpStatus'] ?? 0) === 200, '1: mock http 200');
$mb = $hb['body'] ?? null;
test_assert(is_array($mb) && ($mb['success'] ?? false) === true && ($mb['mock'] ?? false) === true, '1: mock body flags');
test_assert(($mb['traceId'] ?? '') === $traceId, '1: mock echoes traceId');

// 2. Missing trace id
$h2 = $proxy->handle([
    'traceId' => '   ',
    'httpMethod' => 'POST',
    'service' => 'tour.search',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyOk,
]);
test_assert(($h2['errorCode'] ?? '') === 'MISSING_TRACE_ID', '2: missing trace');

// 3. Tenant failure forwarded
$tenantBad = $tenantResolver->resolve('');
$h3 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'POST',
    'service' => 'tour.search',
    'tenantResolve' => $tenantBad,
    'keyVerify' => $keyOk,
]);
test_assert(($h3['errorCode'] ?? '') === 'MISSING_SNO', '3: tenant forward');

// 4. API key failure forwarded
$h4 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'POST',
    'service' => 'tour.search',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyVerifier->verify($tenantOk, null, 'tour.search', null),
]);
test_assert(($h4['errorCode'] ?? '') === 'MISSING_API_KEY', '4: key forward');

// 5. Missing service (middleware)
$h5 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'POST',
    'service' => '',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyOk,
    'tenantAllowedServices' => [],
]);
test_assert(($h5['errorCode'] ?? '') === 'MISSING_SERVICE', '5: missing service');

// 6. Invalid service format
$h6 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'POST',
    'service' => 'bad service!',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyOk,
    'tenantAllowedServices' => [],
]);
test_assert(($h6['errorCode'] ?? '') === 'INVALID_SERVICE_FORMAT', '6: invalid service');

// 7. Service not allowed by key (key allows only order.query; request tour.search)
$keySvcOnly = $keyVerifier->verify($tenantOk, 'bbc_test_svconly01_xx', 'order.query', null);
test_assert(($keySvcOnly['ok'] ?? false) === true, 'setup: svconly key ok with order.query');
$h7 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'POST',
    'service' => 'tour.search',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keySvcOnly,
    'tenantAllowedServices' => [],
]);
test_assert(($h7['errorCode'] ?? '') === 'SERVICE_NOT_ALLOWED', '7: not on api key');

// 8. Tenant intersection denies
$h8 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'POST',
    'service' => 'order.query',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyOk,
    'tenantAllowedServices' => ['tour.search'],
]);
test_assert(($h8['errorCode'] ?? '') === 'SERVICE_NOT_ALLOWED_BY_TENANT', '8: tenant denies');

// 9. Case-insensitive service id
$h9 = $proxy->handle([
    'traceId' => $traceId,
    'httpMethod' => 'GET',
    'service' => 'Tour.Search',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyOk,
    'tenantAllowedServices' => [],
]);
test_assert(($h9['ok'] ?? false) === true, '9: case-insensitive service');
$rb = $h9['hostbRequest']['body'] ?? [];
test_assert(is_array($rb) && ($rb['service'] ?? '') === 'tour.search', '9: normalized in request body');

// 10. AllowedServiceResolver standalone + error payload
$ac = $keyOk['authenticatedContext'] ?? [];
$r10 = $resolver->resolve('', $ac, []);
test_assert(($r10['errorCode'] ?? '') === 'MISSING_SERVICE', '10a: resolver missing');
$pl = $resolver->toErrorPayload($r10, $traceId);
test_assert(is_array($pl) && ($pl['traceId'] ?? '') === $traceId, '10b: resolver error payload traceId');

// 11. HostBRequestBuilder invalid method
$b11 = $builder->build($traceId, 'tour.search', 'TRACE', $ac, null, null);
test_assert(($b11['errorCode'] ?? '') === 'INVALID_HTTP_METHOD', '11: bad method');

// 12. Custom mock responder (no network)
$custom = new HostBProxyMiddleware(null, null, static function (array $req): array {
    return [
        'httpStatus' => 201,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => ['success' => true, 'custom' => true, 'path' => $req['path'] ?? null],
    ];
});
$h12 = $custom->handle([
    'traceId' => $traceId,
    'httpMethod' => 'PUT',
    'service' => 'order.query',
    'tenantResolve' => $tenantOk,
    'keyVerify' => $keyVerifier->verify($tenantOk, 'bbc_test_e1fdvalid01_xx', 'order.query', '127.0.0.1'),
    'tenantAllowedServices' => [],
]);
test_assert(($h12['ok'] ?? false) === true, '12: custom mock');
test_assert((int) (($h12['hostbHttp'] ?? [])['httpStatus'] ?? 0) === 201, '12: custom status');
test_assert((($h12['hostbHttp']['body'] ?? [])['custom'] ?? false) === true, '12: custom body');

// 13. HostBProxyMiddleware toErrorPayload
$ep = $proxy->toErrorPayload($h5, $traceId);
test_assert(is_array($ep) && ($ep['success'] ?? true) === false, '13: middleware error payload');

if ($failures === 0) {
    echo "OK: Host B proxy Stage 3 isolated tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
