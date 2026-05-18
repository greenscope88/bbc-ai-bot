<?php
declare(strict_types=1);

/**
 * MVP ServiceRegistry — read-only config tests (no GatewayKernel, no HTTP, no SQL).
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'core'
    . DIRECTORY_SEPARATOR
    . 'api_gateway'
    . DIRECTORY_SEPARATOR
    . 'production'
    . DIRECTORY_SEPARATOR
    . 'ServiceRegistry.php';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$row = ServiceRegistry::get('tour.search');
t($row !== null, 'tour.search must be readable');
t(isset($row['method']) && $row['method'] === 'GET', 'tour.search method must be GET');
t(isset($row['path']) && $row['path'] === '/api/tour/search', 'tour.search path must be /api/tour/search');
t(isset($row['timeout']) && (int) $row['timeout'] === 30, 'tour.search timeout must be 30');
t(ServiceRegistry::get('definitely.missing.service') === null, 'unknown service must return null');

if ($failures > 0) {
    fwrite(STDERR, "test_service_registry_mvp failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "test_service_registry_mvp passed.\n");
exit(0);
