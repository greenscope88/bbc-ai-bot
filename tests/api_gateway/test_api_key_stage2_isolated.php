<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 2 isolated test harness (API Key).
 * - No SQL, no Host B, no production entry wiring
 */

$base = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR;

require_once $base . 'TraceIdMiddleware.php';
require_once $base . 'ErrorResponseBuilder.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'TenantMappingRepository.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'TenantMappingResolver.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'ApiKeyRepository.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'ApiKeyVerifier.php';

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
$verifier = new ApiKeyVerifier(new ApiKeyRepository());

$ctx = [];
TraceIdMiddleware::apply($ctx, []);
$traceId = (string) ($ctx['traceId'] ?? 'missing-trace-id');

$tenantOk = $tenantResolver->resolve('e1fd133c7e8e45a1');
test_assert(($tenantOk['ok'] ?? false) === true, 'setup: tenant should resolve');

// 1. Happy path: valid key + service
$ok1 = $verifier->verify($tenantOk, 'bbc_test_e1fdvalid01_xx', 'tour.search', '127.0.0.1');
test_assert(($ok1['ok'] ?? false) === true, '1: valid key should verify');
$ac = $ok1['authenticatedContext'] ?? null;
test_assert(is_array($ac), '1: authenticated context present');
test_assert(($ac['apiKeyId'] ?? null) === 101, '1: apiKeyId');
test_assert(($ac['apiKeyPrefix'] ?? '') === 'bbc_test_e1fdval', '1: apiKeyPrefix');
test_assert(($ac['providerIdNo'] ?? null) === 1, '1: providerIdNo');
test_assert(in_array('tour.search', $ac['allowedServices'] ?? [], true), '1: allowedServices');

// 2. Missing API key
$miss = $verifier->verify($tenantOk, null, 'tour.search', null);
test_assert(($miss['ok'] ?? true) === false && ($miss['errorCode'] ?? '') === 'MISSING_API_KEY', '2: missing key');

// 3. Invalid format
$badfmt = $verifier->verify($tenantOk, 'short', 'tour.search', null);
test_assert(($badfmt['errorCode'] ?? '') === 'INVALID_API_KEY_FORMAT', '3: invalid format');

// 4. Tenant failure forwarded (no key check semantics: still returns tenant error)
$tenantBad = $tenantResolver->resolve('');
$fwd = $verifier->verify($tenantBad, 'bbc_test_e1fdvalid01_xx', 'tour.search', null);
test_assert(($fwd['ok'] ?? true) === false && ($fwd['errorCode'] ?? '') === 'MISSING_SNO', '4: forward tenant failure');

// 5. Unknown key
$unk = $verifier->verify($tenantOk, 'bbc_test_unknownxx_xx', 'tour.search', null);
test_assert(($unk['errorCode'] ?? '') === 'API_KEY_NOT_FOUND', '5: not found');

// 6. Hash mismatch (same prefix as valid key, wrong secret)
$hm = $verifier->verify($tenantOk, 'bbc_test_e1fdvalid01_yy', 'tour.search', null);
test_assert(($hm['errorCode'] ?? '') === 'API_KEY_HASH_MISMATCH', '6: hash mismatch');

// 7. Tenant mismatch (key belongs to other000tenant02)
$wm = $verifier->verify($tenantOk, 'bbc_test_wrongsn01_xx', 'tour.search', null);
test_assert(($wm['errorCode'] ?? '') === 'API_KEY_TENANT_MISMATCH', '7: tenant mismatch');

// 8. Disabled key
$dis = $verifier->verify($tenantOk, 'bbc_test_disabld01_xx', 'tour.search', null);
test_assert(($dis['errorCode'] ?? '') === 'API_KEY_DISABLED', '8: disabled');

// 9. Revoked key
$rev = $verifier->verify($tenantOk, 'bbc_test_revoked01_xx', 'tour.search', null);
test_assert(($rev['errorCode'] ?? '') === 'API_KEY_REVOKED', '9: revoked');

// 10. Expired by expires_at
$exp = $verifier->verify($tenantOk, 'bbc_test_expired01_xx', 'tour.search', null);
test_assert(($exp['errorCode'] ?? '') === 'API_KEY_EXPIRED', '10: expired timestamp');

// 11. Expired by key_status
$ex2 = $verifier->verify($tenantOk, 'bbc_test_statusexp01_xx', 'tour.search', null);
test_assert(($ex2['errorCode'] ?? '') === 'API_KEY_EXPIRED', '11: expired status');

// 12. Service not allowed
$ns = $verifier->verify($tenantOk, 'bbc_test_svconly01_xx', 'tour.search', null);
test_assert(($ns['errorCode'] ?? '') === 'API_KEY_SERVICE_NOT_ALLOWED', '12: service not allowed');

// 13. IP not allowed
$ip = $verifier->verify($tenantOk, 'bbc_test_iponly01_xx', 'tour.search', '1.1.1.1');
test_assert(($ip['errorCode'] ?? '') === 'API_KEY_IP_NOT_ALLOWED', '13: ip not allowed');

// 14. IP allowed passes
$ipOk = $verifier->verify($tenantOk, 'bbc_test_iponly01_xx', 'tour.search', '203.0.113.10');
test_assert(($ipOk['ok'] ?? false) === true, '14: allowed IP');

// 15. Tenant inactive guard (forged ok=true with suspended status — isolated edge)
$forgedTenant = [
    'ok' => true,
    'httpStatus' => 200,
    'errorCode' => null,
    'message' => null,
    'tenantContext' => [
        'sno' => 'e1fd133c7e8e45a1',
        'tenantName' => 'X',
        'tenant_status' => 'suspended',
        'enabled' => true,
        'providerIdNo' => 1,
        'depID' => 1,
        'storeUid' => 1,
        'storeNo' => 1,
        'apiProfile' => 'standard',
        'rateLimitProfile' => 'standard',
    ],
];
$ti = $verifier->verify($forgedTenant, 'bbc_test_e1fdvalid01_xx', 'tour.search', null);
test_assert(($ti['errorCode'] ?? '') === 'TENANT_INACTIVE_WITH_ACTIVE_KEY', '15: tenant inactive with active key');

// 16. Error payload carries traceId
$pl = $verifier->toErrorPayload($miss, $traceId);
test_assert(is_array($pl) && ($pl['traceId'] ?? '') === $traceId, '16: error payload traceId');

if ($failures === 0) {
    echo "OK: API Key Stage 2 isolated tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
