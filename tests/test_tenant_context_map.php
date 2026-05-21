<?php
declare(strict_types=1);

/**
 * Verify LINE destination → tenant map in config/tenant_context_map.php.
 */

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_context_map.php';
test_assert(is_file($path), 'tenant_context_map.php exists');

/** @var array<string, mixed> $map */
$map = require $path;
test_assert(is_array($map), 'map returns array');

$destination = 'Ufcedee37a93230a802c30b138f6228f8';
test_assert(isset($map[$destination]) && is_array($map[$destination]), 'destination key exists');

$row = $map[$destination];
test_assert(($row['sno'] ?? '') === 'e1fd133c7e8e45a1', 'sno');
test_assert((int) ($row['depID'] ?? 0) === 888, 'depID');
test_assert((int) ($row['storeNo'] ?? 0) === 6290, 'storeNo');
test_assert((int) ($row['provider_id_no'] ?? 0) === 102, 'provider_id_no');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant_resolver.php';

$event = ['destination' => $destination];
$pdo = new PDO('sqlite::memory:');
$config = [];
$tenant = TenantResolver::resolve($pdo, $event, $config);
test_assert(($tenant['sno'] ?? '') === 'e1fd133c7e8e45a1', 'TenantResolver: sno from map');
test_assert(($tenant['channel_id'] ?? '') === $destination, 'TenantResolver: channel_id');

if ($failures === 0) {
    echo "OK: tenant_context_map LINE channel mapping tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
