<?php
declare(strict_types=1);

/**
 * MVP tests for HostBOutboundHeaderBuilder (no HTTP, no full key in output).
 */

$root = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'core'
    . DIRECTORY_SEPARATOR
    . 'api_gateway'
    . DIRECTORY_SEPARATOR
    . 'production'
    . DIRECTORY_SEPARATOR;

require_once $root . 'HostBOutboundHeaderBuilder.php';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$syntheticKey = 'MvpHdr_' . bin2hex(random_bytes(8)) . '_unit_only';
$config = ['api_key' => $syntheticKey];
$trace = 'trace-hdr-mvp-001';

$headers = HostBOutboundHeaderBuilder::build($config, $trace);

t(isset($headers['X-API-Key']), 'must set X-API-Key');
t(isset($headers['X-BBC-Trace-Id']) && $headers['X-BBC-Trace-Id'] === $trace, 'must set X-BBC-Trace-Id to traceId');
t(strlen($headers['X-API-Key']) === strlen($syntheticKey), 'X-API-Key length must match synthetic (not printed)');
t(strpos($headers['X-API-Key'], 'MvpHdr_') === 0, 'X-API-Key must have expected prefix only in assertion message path');
t(!array_key_exists('X-BBC-API-Key', $headers), 'must not produce X-BBC-API-Key');
t(!array_key_exists('Authorization', $headers), 'must not produce Authorization');
t(!array_key_exists('authorization', $headers), 'must not produce lowercase authorization');

$threw = false;
try {
    HostBOutboundHeaderBuilder::build(['api_key' => ''], $trace);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
t($threw, 'empty api_key must throw InvalidArgumentException');

$threwMissing = false;
try {
    HostBOutboundHeaderBuilder::build([], $trace);
} catch (InvalidArgumentException $e) {
    $threwMissing = strpos($e->getMessage(), 'api_key') !== false;
}
t($threwMissing, 'missing api_key must throw InvalidArgumentException');

if ($failures > 0) {
    fwrite(STDERR, "test_host_b_outbound_header_builder_mvp failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "test_host_b_outbound_header_builder_mvp passed (X-API-Key len=" . (string) strlen($syntheticKey) . ", no full key printed).\n");
exit(0);
