<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 5 isolated rate limiting harness.
 * No SQL, no Redis, no Host B HTTP, no production entry wiring.
 */

$base = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR;

require_once $base . 'GatewayKernel.php';
require_once $base . 'TraceIdMiddleware.php';
require_once $base . 'ErrorResponseBuilder.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'RateLimitPolicyRepository.php';
require_once $base . 'isolated' . DIRECTORY_SEPARATOR . 'RateLimiter.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$kernel = new GatewayKernel();
$kctx = $kernel->execute([]);
test_assert(isset($kctx['traceId']) && is_string($kctx['traceId']), 'setup: traceId');
$traceId = (string) $kctx['traceId'];

$tenantCtx = [
    'sno' => 'e1fd133c7e8e45a1',
];
$authCtx = [
    'apiKeyId' => 101,
];

$tFixed = 1700000065.0;

// 1. Valid request under limit
$rl1 = new RateLimiter();
$c1 = $rl1->consume([
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => 'tour.search',
    'clientIp' => '127.0.0.1',
    'now' => $tFixed,
]);
test_assert(($c1['ok'] ?? false) === true, '1: under limit ok');

// 2. Burst limit exceeded (burst=3 on tour.search, same second)
$rl2 = new RateLimiter();
$g2 = [
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => 'tour.search',
    'clientIp' => '10.0.0.1',
    'now' => $tFixed,
];
test_assert(($rl2->consume($g2)['ok'] ?? false) === true, '2a: burst 1');
test_assert(($rl2->consume($g2)['ok'] ?? false) === true, '2b: burst 2');
test_assert(($rl2->consume($g2)['ok'] ?? false) === true, '2c: burst 3');
$b2 = $rl2->consume($g2);
test_assert(($b2['ok'] ?? true) === false && ($b2['errorCode'] ?? '') === 'RATE_LIMIT_EXCEEDED', '2d: burst exceeded');
test_assert(($b2['hitWindow'] ?? '') === 'burst', '2e: hit burst window');

// 3. Per-minute limit exceeded (limit_per_minute=2 on svc.minute)
$rl3 = new RateLimiter();
$g3 = [
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => 'svc.minute',
    'clientIp' => '10.0.0.2',
    'now' => $tFixed,
];
test_assert(($rl3->consume($g3)['ok'] ?? false) === true, '3a: minute 1');
test_assert(($rl3->consume($g3)['ok'] ?? false) === true, '3b: minute 2');
$m3 = $rl3->consume($g3);
test_assert(($m3['ok'] ?? true) === false && ($m3['errorCode'] ?? '') === 'RATE_LIMIT_EXCEEDED', '3c: minute exceeded');
test_assert(($m3['hitWindow'] ?? '') === '1m', '3d: hit minute window');

// 4. Disabled policy
$rl4 = new RateLimiter();
$d4 = $rl4->consume([
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => 'svc.disabled',
    'clientIp' => '10.0.0.3',
    'now' => $tFixed,
]);
test_assert(($d4['ok'] ?? true) === false && ($d4['errorCode'] ?? '') === 'RATE_LIMIT_POLICY_DISABLED', '4: disabled');
test_assert((int) ($d4['httpStatus'] ?? 0) === 403, '4b: http 403');

// 5. Missing tenant context
$rl5 = new RateLimiter();
$e5 = $rl5->consume([
    'traceId' => $traceId,
    'tenantContext' => null,
    'authenticatedContext' => $authCtx,
    'service' => 'tour.search',
    'clientIp' => '127.0.0.1',
    'now' => $tFixed,
]);
test_assert(($e5['ok'] ?? true) === false && ($e5['errorCode'] ?? '') === 'MISSING_TENANT_CONTEXT', '5: no tenant');

// 6. Missing authenticated context
$rl6 = new RateLimiter();
$e6 = $rl6->consume([
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => null,
    'service' => 'tour.search',
    'clientIp' => '127.0.0.1',
    'now' => $tFixed,
]);
test_assert(($e6['ok'] ?? true) === false && ($e6['errorCode'] ?? '') === 'MISSING_AUTHENTICATED_CONTEXT', '6: no auth');

// 7. Missing service
$rl7 = new RateLimiter();
$e7 = $rl7->consume([
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => '   ',
    'clientIp' => '127.0.0.1',
    'now' => $tFixed,
]);
test_assert(($e7['ok'] ?? true) === false && ($e7['errorCode'] ?? '') === 'MISSING_SERVICE', '7: no service');

// 8. Different client_ip → separate counter (minute=1 each)
$rl8 = new RateLimiter();
$g8a = [
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => 'svc.ipcounter',
    'clientIp' => '198.51.100.1',
    'now' => $tFixed,
];
$g8b = $g8a;
$g8b['clientIp'] = '198.51.100.2';
test_assert(($rl8->consume($g8a)['ok'] ?? false) === true, '8a: ip1 first');
$e8a2 = $rl8->consume($g8a);
test_assert(($e8a2['ok'] ?? true) === false, '8b: ip1 second blocked');
test_assert(($rl8->consume($g8b)['ok'] ?? false) === true, '8c: ip2 still allowed');

// 9. Different service → separate counter
$rl9 = new RateLimiter();
$g9a = [
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => 'svc.svcA',
    'clientIp' => '203.0.113.50',
    'now' => $tFixed,
];
$g9b = $g9a;
$g9b['service'] = 'svc.svcB';
test_assert(($rl9->consume($g9a)['ok'] ?? false) === true, '9a: svcA first');
$e9a2 = $rl9->consume($g9a);
test_assert(($e9a2['ok'] ?? true) === false, '9b: svcA second blocked');
test_assert(($rl9->consume($g9b)['ok'] ?? false) === true, '9c: svcB allowed');

// 10. Error payload includes traceId (ErrorResponseBuilder)
$rl10 = new RateLimiter();
$g10 = [
    'traceId' => $traceId,
    'tenantContext' => $tenantCtx,
    'authenticatedContext' => $authCtx,
    'service' => 'tour.search',
    'clientIp' => '10.0.0.99',
    'now' => $tFixed,
];
test_assert(($rl10->consume($g10)['ok'] ?? false) === true, '10a: burst slot 1');
test_assert(($rl10->consume($g10)['ok'] ?? false) === true, '10b: burst slot 2');
test_assert(($rl10->consume($g10)['ok'] ?? false) === true, '10c: burst slot 3');
$failBurst = $rl10->consume($g10);
test_assert(($failBurst['ok'] ?? true) === false, '10d: burst exceeded');
$pl = $rl10->toErrorPayload($failBurst, $traceId);
test_assert(is_array($pl) && (($pl['traceId'] ?? '') === $traceId), '10: payload traceId');
test_assert(isset($pl['success']) && $pl['success'] === false, '10e: success false');

if ($failures === 0) {
    echo "OK: Rate limiting Stage 5 isolated tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
