<?php
declare(strict_types=1);

/**
 * Phase 2A Stage 1: ConfigTenantRegistry + ResolvedTenant (no Host B / LINE / Gemini).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';

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

// 1–2. resolveByChannel / resolveBySno
$stagingChannel = 'Ufcedee37a93230a802c30b138f6228f8';
$stagingSno = 'e1fd133c7e8e45a1';

$byChannel = $registry->resolveByChannel($stagingChannel);
$bySno = $registry->resolveBySno($stagingSno);

test_assert($byChannel !== null, 'resolveByChannel: travel_a channel resolves');
test_assert($bySno !== null, 'resolveBySno: travel_a sno resolves');
test_assert($byChannel !== null && $bySno !== null && $byChannel->getTenantKey() === $bySno->getTenantKey(), 'channel and sno resolve same tenant');

// 3. travel_a
test_assert($byChannel !== null && $byChannel->getTenantKey() === 'travel_a', 'travel_a tenant key');
test_assert($byChannel !== null && $byChannel->getSno() === $stagingSno, 'travel_a sno matches staging');
test_assert($byChannel !== null && $byChannel->getDepId() === 888, 'travel_a depID');
test_assert($byChannel !== null && $byChannel->getStoreNo() === 6290, 'travel_a storeNo');
test_assert($byChannel !== null && $byChannel->isEnabled() === true, 'travel_a status enabled');
test_assert($byChannel !== null && $byChannel->isFeatureEnabled('tour_prompt') === true, 'travel_a tour_prompt on');
test_assert($byChannel !== null && $byChannel->isFeatureEnabled('hybrid_search') === true, 'travel_a hybrid_search on');
test_assert($byChannel !== null && $byChannel->isFeatureEnabled('fixed_formatter') === true, 'travel_a fixed_formatter on');

// 4. travel_b Stage 2 (channel must come from registry, no hardcoded value)
$travelBTenant = null;
foreach ($registry->getAllTenants() as $tenant) {
    if ($tenant->getTenantKey() === 'travel_b') {
        $travelBTenant = $tenant;
        break;
    }
}
test_assert($travelBTenant !== null, 'travel_b present in registry');
$travelBChannel = $travelBTenant !== null ? trim($travelBTenant->getLineChannelId()) : '';
test_assert($travelBChannel !== '', 'travel_b line_channel_id from registry');

$travelB = $registry->resolveByChannel($travelBChannel);
test_assert($travelB !== null && $travelB->getTenantKey() === 'travel_b', 'travel_b resolves by channel');
test_assert($travelB !== null && $travelB->getStatus() === 'enabled', 'travel_b status enabled');
test_assert($travelB !== null && $travelB->isFeatureEnabled('tour_prompt') === true, 'travel_b tour_prompt on');
test_assert($travelB !== null && $travelB->isFeatureEnabled('hybrid_search') === true, 'travel_b hybrid_search on');
test_assert($travelB !== null && $travelB->isFeatureEnabled('fixed_formatter') === true, 'travel_b fixed_formatter on');

// 5. travel_d third tenant golden clone
$travelDChannel = 'Uf76e61279fd9fd5f8e0274bbd59f7f7c';
$travelDSno = '5fecdf66e9224bee';
$travelDByChannel = $registry->resolveByChannel($travelDChannel);
$travelDBySno = $registry->resolveBySno($travelDSno);
test_assert($travelDByChannel !== null && $travelDByChannel->getTenantKey() === 'travel_d', 'travel_d resolves by channel');
test_assert($travelDBySno !== null && $travelDBySno->getTenantKey() === 'travel_d', 'travel_d resolves by sno');
test_assert($travelDByChannel !== null && $travelDBySno !== null && $travelDByChannel->getTenantKey() === $travelDBySno->getTenantKey(), 'travel_d channel and sno same tenant');
test_assert($travelDByChannel !== null && $travelDByChannel->getSno() === $travelDSno, 'travel_d sno');
test_assert($travelDByChannel !== null && $travelDByChannel->getDepId() === 888, 'travel_d depID');
test_assert($travelDByChannel !== null && $travelDByChannel->getStoreNo() === 6355, 'travel_d storeNo');
test_assert($travelDByChannel !== null && $travelDByChannel->getStoreUid() === 6355, 'travel_d store_uid');
test_assert($travelDByChannel !== null && $travelDByChannel->getProviderIdNo() === 0, 'travel_d provider_id_no');
test_assert($travelDByChannel !== null && $travelDByChannel->getCredentialEnvPrefix() === 'travel_d', 'travel_d credential_env_prefix');
test_assert($travelDByChannel !== null && $travelDByChannel->getStatus() === 'enabled', 'travel_d status enabled');
test_assert($travelDByChannel !== null && $travelDByChannel->isFeatureEnabled('tour_prompt') === true, 'travel_d tour_prompt on');
test_assert($travelDByChannel !== null && $travelDByChannel->isFeatureEnabled('hybrid_search') === true, 'travel_d hybrid_search on');
test_assert($travelDByChannel !== null && $travelDByChannel->isFeatureEnabled('fixed_formatter') === true, 'travel_d fixed_formatter on');

// 6. travel_c disabled
$travelC = $registry->resolveBySno('00000000-0000-4000-8000-0000000000c1');
test_assert($travelC !== null && $travelC->getTenantKey() === 'travel_c', 'travel_c resolves by sno');
test_assert($travelC !== null && $travelC->getStatus() === 'disabled', 'travel_c status disabled');
test_assert($travelC !== null && $travelC->isEnabled() === false, 'travel_c isEnabled false');

// 7–8. unknown
test_assert($registry->resolveByChannel('U_UNKNOWN_CHANNEL_XYZ') === null, 'unknown channel → null');
test_assert($registry->resolveBySno('unknown-sno-not-in-registry') === null, 'unknown sno → null');

// getAllTenants
$all = $registry->getAllTenants();
test_assert(count($all) === 4, 'getAllTenants returns 4 tenants');

// toArray smoke
test_assert($byChannel !== null && isset($byChannel->toArray()['tenant_key']), 'toArray has tenant_key');

if ($failures === 0) {
    echo "OK: ConfigTenantRegistry tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
