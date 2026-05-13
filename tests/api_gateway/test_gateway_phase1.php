<?php
declare(strict_types=1);

/**
 * Phase 1-C manual test harness — not a production entrypoint.
 */

$base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_gateway' . DIRECTORY_SEPARATOR;
require_once $base . 'TraceIdMiddleware.php';
require_once $base . 'ErrorResponseBuilder.php';
require_once $base . 'GatewayKernel.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function is_uuid_v4(string $s): bool
{
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        $s
    );
}

function timestamp_is_utc_z(string $ts): bool
{
    if ($ts === '' || substr($ts, -1) !== 'Z') {
        return false;
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $ts, new \DateTimeZone('UTC'));
    if ($dt instanceof \DateTimeImmutable) {
        return true;
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $ts, new \DateTimeZone('UTC'));

    return $dt instanceof \DateTimeImmutable;
}

// --- 1. No X-Trace-Id: system generates traceId ---
$ctx = [];
TraceIdMiddleware::apply($ctx, []);
test_assert(isset($ctx['traceId']) && is_string($ctx['traceId']), '1: traceId missing or not string');
test_assert(is_uuid_v4($ctx['traceId']), '1: traceId should be UUID v4 when header absent');

// --- 2. Legal X-Trace-Id: preserve ---
$legal = 'legal-trace-abc-123';
$ctx = [];
TraceIdMiddleware::apply($ctx, ['HTTP_X_TRACE_ID' => $legal]);
test_assert($ctx['traceId'] === $legal, '2: legal X-Trace-Id should be preserved');

// --- 3. X-Trace-Id over 64 chars: replace with UUID ---
$tooLong = str_repeat('a', 65);
$ctx = [];
TraceIdMiddleware::apply($ctx, ['HTTP_X_TRACE_ID' => $tooLong]);
test_assert($ctx['traceId'] !== $tooLong, '3: overlong trace id should be replaced');
test_assert(is_uuid_v4($ctx['traceId']), '3: replacement should be UUID v4');

// --- 4. Newline, tab, control chars: replace with UUID ---
foreach (["newline\nid", "tab\tid", "ctl\x7Fid", "nul\x00id"] as $bad) {
    $ctx = [];
    TraceIdMiddleware::apply($ctx, ['HTTP_X_TRACE_ID' => $bad]);
    test_assert(is_uuid_v4($ctx['traceId']), '4: invalid chars should yield UUID: ' . json_encode($bad));
}

// --- 5. ErrorResponseBuilder fields ---
$trace = 'test-trace-for-json';
$json = ErrorResponseBuilder::toJson(false, 'E_TEST', 'hello', $trace, null);
$decoded = json_decode($json, true);
test_assert(is_array($decoded), '5: JSON should decode to array');
foreach (['success', 'errorCode', 'message', 'traceId', 'timestamp', 'details'] as $key) {
    test_assert(array_key_exists($key, $decoded), "5: missing key {$key}");
}

// --- 6. details=null => JSON null ---
test_assert(array_key_exists('details', $decoded) && $decoded['details'] === null, '6: details should be JSON null');

// --- 7. details=array => JSON object ---
$jsonObj = ErrorResponseBuilder::toJson(false, 'E_TEST', 'hello', $trace, ['foo' => 1, 'bar' => 'x']);
$decodedObj = json_decode($jsonObj, true);
test_assert(
    is_array($decodedObj['details'] ?? null) && ($decodedObj['details']['foo'] ?? null) === 1,
    '7: details should decode as object (associative array)'
);
test_assert(
    strpos($jsonObj, '"details":{') !== false && strpos($jsonObj, '"details":[') === false,
    '7: JSON details must be object not array'
);

$jsonEmptyObj = ErrorResponseBuilder::toJson(true, 'OK', 'ok', $trace, []);
test_assert(strpos($jsonEmptyObj, '"details":{}') !== false, '7: empty details array should serialize as {}');

// --- 8. timestamp UTC Z suffix ---
test_assert(timestamp_is_utc_z($decoded['timestamp']), '8: timestamp should be UTC ISO-8601 ending in Z');

// --- 9. GatewayKernel execute returns context with traceId ---
$serverBackup = $_SERVER;
try {
    unset($_SERVER['HTTP_X_TRACE_ID'], $_SERVER['X-Trace-Id'], $_SERVER['x-trace-id']);
    $kernel = new GatewayKernel();
    $out = $kernel->execute([]);
    test_assert(isset($out['traceId']) && is_string($out['traceId']), '9: execute should return traceId string');
    test_assert(is_uuid_v4($out['traceId']), '9: traceId should be UUID v4 without header');
} finally {
    $_SERVER = $serverBackup;
}

if ($failures === 0) {
    echo "OK: all Phase 1-C checks passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
