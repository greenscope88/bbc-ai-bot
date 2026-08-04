<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/tenant/ConfigTenantRegistry.php';
require_once dirname(__DIR__, 2) . '/core/search/Phase9C1FeatureGate.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentUnderstandingRuntimeSelector.php';
require_once dirname(__DIR__, 2) . '/core/grounding/GroundingPipelineRuntime.php';

$failures = 0;
$assert = static function (bool $c, string $m) use (&$failures): void {
    if (!$c) { ++$failures; fwrite(STDERR, "FAIL: {$m}\n"); }
};

// Production registry after V2 feature migration.
$registry = new ConfigTenantRegistry();
Phase9C1FeatureGate::setRegistryOverrideForTesting($registry);

$cases = [
    'e1fd133c7e8e45a1' => [false, false, false], // travel_a
    '5f99b8d665e8444d' => [true, true, true],    // travel_b
    '5fecdf66e9224bee' => [true, true, false],   // travel_d
    '00000000-0000-4000-8000-0000000000c1' => [false, false, false], // travel_c
];

$configOn = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    'tenant_registry' => $registry,
];
$gOn = [
    GroundingPipelineRuntime::FLAG_ENABLED => true,
    'tenant_registry' => $registry,
];

foreach ($cases as $sno => $expected) {
    [$bats, $aiu, $ground] = $expected;
    $assert(Phase9C1FeatureGate::isBatsEnabledTenant($sno) === $bats, "phase9c1 {$sno}");
    $assert(AiIntentUnderstandingRuntimeSelector::isAuthoritativeEnabled($configOn, $sno) === $aiu, "aiu {$sno}");
    $assert(GroundingPipelineRuntime::isAuthoritativeEnabled($gOn, $sno) === $ground, "grounding {$sno}");
}

Phase9C1FeatureGate::setRegistryOverrideForTesting(null);
if ($failures > 0) { fwrite(STDERR, "{$failures} failure(s)\n"); exit(1); }
fwrite(STDOUT, "OK: test_tenant_admin_activation_equivalence\n");
exit(0);
