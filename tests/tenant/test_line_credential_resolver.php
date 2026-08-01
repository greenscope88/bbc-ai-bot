<?php
declare(strict_types=1);

/**
 * Phase 2A Stage 5-3: LineCredentialResolver tests.
 * No Host B / LINE / Gemini HTTP.
 *
 * NOTE: We do not read or print any real secrets/tokens.
 */

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

$travelAChannel = 'Ufcedee37a93230a802c30b138f6228f8';

$registry = new ConfigTenantRegistry();
$travelBTenant = null;
foreach ($registry->getAllTenants() as $tenant) {
    if ($tenant->getTenantKey() === 'travel_b') {
        $travelBTenant = $tenant;
        break;
    }
}
test_assert($travelBTenant !== null, 'travel_b present in ConfigTenantRegistry');
$travelBChannel = $travelBTenant !== null ? trim($travelBTenant->getLineChannelId()) : '';
test_assert($travelBChannel !== '', 'travel_b line_channel_id from registry');
test_assert($registry->resolveByChannel($travelBChannel) !== null, 'travel_b channel resolves in registry');

// Ensure clean env for these keys in this process.
putenv('LINE_CHANNEL_SECRET__travel_a');
putenv('LINE_CHANNEL_ACCESS_TOKEN__travel_a');
putenv('LINE_CHANNEL_SECRET__travel_b');
putenv('LINE_CHANNEL_ACCESS_TOKEN__travel_b');
putenv('LINE_CHANNEL_SECRET__travel_d');
putenv('LINE_CHANNEL_ACCESS_TOKEN__travel_d');

// 1) travel_a resolve OK (using dummy env vars)
putenv('LINE_CHANNEL_SECRET__travel_a=dummy_secret_a');
putenv('LINE_CHANNEL_ACCESS_TOKEN__travel_a=dummy_token_a');
$a = LineCredentialResolver::resolveByChannelId($travelAChannel);
test_assert(($a['ok'] ?? false) === true, 'travel_a ok');
test_assert(($a['tenant_key'] ?? '') === 'travel_a', 'travel_a tenant_key');
test_assert(($a['registry_hit'] ?? false) === true, 'travel_a registry_hit');
test_assert(($a['errorCode'] ?? null) === null, 'travel_a errorCode null');
test_assert(is_string($a['channel_secret'] ?? null) && ($a['channel_secret'] ?? '') !== '', 'travel_a secret present (value hidden)');
test_assert(is_string($a['channel_access_token'] ?? null) && ($a['channel_access_token'] ?? '') !== '', 'travel_a token present (value hidden)');

// 2) travel_b staging resolve fail-closed when env missing
putenv('LINE_CHANNEL_SECRET__travel_b'); // unset
putenv('LINE_CHANNEL_ACCESS_TOKEN__travel_b'); // unset
$b = LineCredentialResolver::resolveByChannelId($travelBChannel);
test_assert(($b['ok'] ?? true) === false, 'travel_b ok=false when missing');
test_assert(($b['tenant_key'] ?? '') === 'travel_b', 'travel_b tenant_key');
test_assert(($b['registry_hit'] ?? false) === true, 'travel_b registry_hit');
test_assert(($b['errorCode'] ?? '') === 'MISSING_CREDENTIALS', 'travel_b errorCode MISSING_CREDENTIALS');
test_assert(is_array($b['missing_keys'] ?? null) && count($b['missing_keys']) >= 1, 'travel_b missing_keys populated');
test_assert(($b['channel_secret'] ?? null) === null, 'travel_b secret not returned');
test_assert(($b['channel_access_token'] ?? null) === null, 'travel_b token not returned');

// 3) unknown channel fail-closed (no registry hit)
$u = LineCredentialResolver::resolveByChannelId('U_UNKNOWN_CHANNEL_LINE_CRED_TEST');
test_assert(($u['ok'] ?? true) === false, 'unknown ok=false');
test_assert(($u['registry_hit'] ?? true) === false, 'unknown registry_hit=false');
test_assert(($u['errorCode'] ?? '') === 'TENANT_NOT_FOUND', 'unknown errorCode TENANT_NOT_FOUND');

// 4) must not cross-tenant fallback: travel_b must not return travel_a values
test_assert(($b['channel_secret'] ?? '') !== 'dummy_secret_a', 'no cross-tenant secret fallback');
test_assert(($b['channel_access_token'] ?? '') !== 'dummy_token_a', 'no cross-tenant token fallback');

// 5) env key naming rule: missing_keys must include the prefixed key names for travel_b
$missing = $b['missing_keys'] ?? [];
if (is_array($missing)) {
    $joined = implode(',', $missing);
    test_assert(strpos($joined, 'LINE_CHANNEL_SECRET__travel_b') !== false || strpos($joined, 'LINE_CHANNEL_ACCESS_TOKEN__travel_b') !== false, 'missing_keys uses __{prefix} naming');
}

// 6) travel_d fail-closed when credentials missing; no cross-tenant fallback
$travelDChannel = 'Uf76e61279fd9fd5f8e0274bbd59f7f7c';
test_assert($registry->resolveByChannel($travelDChannel) !== null, 'travel_d channel resolves in registry');
putenv('LINE_CHANNEL_SECRET__travel_d');
putenv('LINE_CHANNEL_ACCESS_TOKEN__travel_d');
$d = LineCredentialResolver::resolveByChannelId($travelDChannel);
test_assert(($d['ok'] ?? true) === false, 'travel_d ok=false when missing');
test_assert(($d['tenant_key'] ?? '') === 'travel_d', 'travel_d tenant_key');
test_assert(($d['registry_hit'] ?? false) === true, 'travel_d registry_hit');
test_assert(($d['errorCode'] ?? '') === 'MISSING_CREDENTIALS', 'travel_d errorCode MISSING_CREDENTIALS');
test_assert(($d['channel_secret'] ?? null) === null, 'travel_d secret not returned');
test_assert(($d['channel_access_token'] ?? null) === null, 'travel_d token not returned');
test_assert(($d['channel_secret'] ?? '') !== 'dummy_secret_a', 'travel_d no cross-tenant secret fallback');
test_assert(($d['channel_access_token'] ?? '') !== 'dummy_token_a', 'travel_d no cross-tenant token fallback');
$dMissing = $d['missing_keys'] ?? [];
if (is_array($dMissing)) {
    $dJoined = implode(',', $dMissing);
    test_assert(
        strpos($dJoined, 'LINE_CHANNEL_SECRET__travel_d') !== false
        || strpos($dJoined, 'LINE_CHANNEL_ACCESS_TOKEN__travel_d') !== false,
        'travel_d missing_keys uses __travel_d naming'
    );
}

if ($failures === 0) {
    echo "OK: LineCredentialResolver tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);

