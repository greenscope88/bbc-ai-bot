<?php
declare(strict_types=1);

/**
 * travel_b Stage 2 readiness (tour_prompt + hybrid_search + fixed_formatter ON).
 * No Host B, LINE, or Gemini HTTP.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant_resolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tenant_context_resolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_feature_gate.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$registryPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_registry.php';
/** @var array<string, mixed> $raw */
$raw = require $registryPath;
/** @var array<string, array<string, mixed>> $tenants */
$tenants = $raw['tenants'] ?? [];
$travelBRow = $tenants['travel_b'] ?? null;

test_assert(is_array($travelBRow), 'travel_b row exists in tenant_registry.php');
test_assert(($travelBRow['created_for'] ?? '') === 'travel_b', 'created_for=travel_b');
test_assert(is_array($travelBRow['onboarding_notes'] ?? null), 'onboarding_notes present');
test_assert(($travelBRow['onboarding_notes']['onboarding_required'] ?? false) === true, 'onboarding_required true');

$travelBChannel = (string) ($travelBRow['line_channel_id'] ?? '');
$travelBSno = (string) ($travelBRow['sno'] ?? '');

$registry = new ConfigTenantRegistry();

// 1. Registry resolve
$byChannel = $registry->resolveByChannel($travelBChannel);
$bySno = $registry->resolveBySno($travelBSno);
test_assert($byChannel !== null && $bySno !== null, 'travel_b resolves by channel and sno');
test_assert($byChannel->getTenantKey() === 'travel_b', 'tenant_key travel_b');

// 2. status=enabled (Stage 1)
test_assert($bySno->getStatus() === 'enabled', 'status=enabled');
test_assert($bySno->isEnabled() === true, 'isEnabled true when enabled');

// 3. Stage 2 features: tour_prompt + hybrid_search + fixed_formatter ON
test_assert($bySno->isFeatureEnabled('tour_prompt') === true, 'tour_prompt ON');
test_assert($bySno->isFeatureEnabled('hybrid_search') === true, 'hybrid_search ON');
test_assert($bySno->isFeatureEnabled('fixed_formatter') === true, 'fixed_formatter ON');

// 4–5. Feature gates ON for tour_prompt + hybrid_search + fixed formatter
$gateCtx = [
    'sno' => $travelBSno,
    'channelId' => $travelBChannel,
    'tenantRegistry' => $registry,
];
test_assert(TourPromptFeatureGate::isEnabled($gateCtx) === true, 'TourPromptFeatureGate ON for travel_b');
test_assert(TourPromptFeatureGate::isFixedFormatterEnabled($gateCtx, $registry) === true, 'isFixedFormatterEnabled ON for travel_b');

// 6. TenantContextResolver shape (registry bridge path)
$ctxResolver = new TenantContextResolver();
$ctxResult = $ctxResolver->resolve($travelBSno);
test_assert(($ctxResult['ok'] ?? false) === true, 'TenantContextResolver ok for travel_b sno');
$tc = $ctxResult['tenantContext'] ?? [];
foreach (['sno', 'depID', 'storeNo', 'store_uid', 'provider_id_no'] as $key) {
    test_assert(array_key_exists($key, $tc), "tenantContext key: {$key}");
}
test_assert(($tc['sno'] ?? '') === $travelBSno, 'tenantContext sno matches');

// TenantResolver tenant[] shape (registry hit before DB)
$pdo = new PDO('sqlite::memory:');
$event = ['destination' => $travelBChannel];
$tenantFromResolver = TenantResolver::resolve($pdo, $event, []);
test_assert(($tenantFromResolver['sno'] ?? '') === $travelBSno, 'TenantResolver registry hit sno');
test_assert(($tenantFromResolver['channel_id'] ?? '') === $travelBChannel, 'TenantResolver channel_id');

// 7. travel_a unchanged
$travelAChannel = 'Ufcedee37a93230a802c30b138f6228f8';
$travelASno = 'e1fd133c7e8e45a1';
$travelA = $registry->resolveByChannel($travelAChannel);
test_assert($travelA !== null && $travelA->getTenantKey() === 'travel_a', 'travel_a still resolves');
test_assert($travelA->isFeatureEnabled('tour_prompt') === true, 'travel_a tour_prompt unchanged');
test_assert($travelA->isFeatureEnabled('hybrid_search') === true, 'travel_a hybrid_search unchanged');

if ($failures === 0) {
    echo "OK: travel_b staging readiness tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
