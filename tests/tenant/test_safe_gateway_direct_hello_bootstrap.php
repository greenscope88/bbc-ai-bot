<?php
declare(strict_types=1);

/**
 * Ensures safe_gateway hello shortcut loads app config
 * before credential resolution path.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'safe_gateway.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'LineCredentialResolver.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$registry = new ConfigTenantRegistry();
$travelBTenant = null;
foreach ($registry->getAllTenants() as $tenant) {
    if ($tenant->getTenantKey() === 'travel_b') {
        $travelBTenant = $tenant;
        break;
    }
}

test_assert($travelBTenant !== null, 'travel_b exists in registry');
$travelBChannel = $travelBTenant !== null ? trim($travelBTenant->getLineChannelId()) : '';
test_assert($travelBChannel !== '', 'travel_b channel id exists');

// Simulate "not preloaded getenv" for tenant-scoped keys in current process.
putenv('LINE_CHANNEL_SECRET__travel_b');
putenv('LINE_CHANNEL_ACCESS_TOKEN__travel_b');

// The hello shortcut should force app_config() load path.
safeGatewayEnsureAppConfigLoaded();

$resolved = LineCredentialResolver::resolveByChannelId($travelBChannel);

test_assert(($resolved['registry_hit'] ?? false) === true, 'registry_hit=true after bootstrap load');
test_assert(($resolved['tenant_key'] ?? '') === 'travel_b', 'tenant_key=travel_b after bootstrap load');
test_assert(($resolved['errorCode'] ?? '') !== 'TENANT_NOT_FOUND', 'must not regress to TENANT_NOT_FOUND');

if (($resolved['ok'] ?? false) === false) {
    test_assert(($resolved['errorCode'] ?? '') === 'MISSING_CREDENTIALS', 'if not ok, fail closed by MISSING_CREDENTIALS');
}

if ($failures === 0) {
    echo "OK: safe_gateway hello bootstrap test passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
