<?php
declare(strict_types=1);

/**
 * Phase 2A Stage 3: Feature gates bridged to ConfigTenantRegistry features.*
 * No Host B, LINE, or Gemini.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_feature_gate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchFeatureGate.php';
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

function write_temp_registry(array $config): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tenant_registry_test_' . bin2hex(random_bytes(8)) . '.php';
    $export = var_export($config, true);
    file_put_contents($path, "<?php\ndeclare(strict_types=1);\nreturn {$export};\n");

    return $path;
}

$travelASno = 'e1fd133c7e8e45a1';
$travelAChannel = 'Ufcedee37a93230a802c30b138f6228f8';
$travelBSno = '00000000-0000-4000-8000-0000000000b1';
$travelCSno = '00000000-0000-4000-8000-0000000000c1';

$registry = new ConfigTenantRegistry();

// 1. travel_a — tour_prompt ON, hybrid_search ON, fixed_formatter ON (registry DTO)
$travelA = $registry->resolveBySno($travelASno);
test_assert($travelA !== null, 'travel_a in registry');
test_assert($travelA->isFeatureEnabled('tour_prompt') === true, 'travel_a tour_prompt ON');
test_assert($travelA->isFeatureEnabled('hybrid_search') === true, 'travel_a hybrid_search ON');
test_assert($travelA->isFeatureEnabled('fixed_formatter') === true, 'travel_a fixed_formatter ON');

test_assert(
    TourPromptFeatureGate::isEnabled(['sno' => $travelASno], $registry) === true,
    'travel_a TourPromptFeatureGate ON via registry'
);
test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $travelASno], null, $registry) === true,
    'travel_a HybridSearchFeatureGate ON via registry'
);

// 2. travel_b — OFF
$travelB = $registry->resolveBySno($travelBSno);
test_assert($travelB !== null, 'travel_b in registry');
test_assert($travelB->isFeatureEnabled('tour_prompt') === false, 'travel_b tour_prompt OFF');
test_assert($travelB->isFeatureEnabled('hybrid_search') === false, 'travel_b hybrid_search OFF');

test_assert(
    TourPromptFeatureGate::isEnabled(['sno' => $travelBSno], $registry) === false,
    'travel_b TourPromptFeatureGate OFF'
);
test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $travelBSno], null, $registry) === false,
    'travel_b HybridSearchFeatureGate OFF'
);

// 3. travel_c — disabled status + features OFF
$travelC = $registry->resolveBySno($travelCSno);
test_assert($travelC !== null && $travelC->getStatus() === 'disabled', 'travel_c disabled');
test_assert($travelC->isFeatureEnabled('hybrid_search') === false, 'travel_c hybrid OFF');

test_assert(
    TourPromptFeatureGate::isEnabled(['sno' => $travelCSno], $registry) === false,
    'travel_c TourPromptFeatureGate OFF'
);
test_assert(
    HybridSearchFeatureGate::isEnabled(['sno' => $travelCSno], null, $registry) === false,
    'travel_c HybridSearchFeatureGate OFF'
);

// 4. registry miss → legacy allowlist (empty registry file)
$emptyRegistryPath = write_temp_registry([
    'schema_version' => 1,
    'global' => [
        'hybrid_search_master_enabled' => true,
        'tour_prompt_master_enabled' => true,
    ],
    'tenants' => [],
]);
try {
    $emptyRegistry = new ConfigTenantRegistry($emptyRegistryPath);
    test_assert(
        TourPromptFeatureGate::tryRegistryFeature(['sno' => $travelASno], 'tour_prompt', $emptyRegistry) === null,
        'registry miss returns null'
    );
    test_assert(
        TourPromptFeatureGate::isEnabled(['sno' => $travelASno], $emptyRegistry) === true,
        'registry miss fallback legacy ALLOWED_SNO for travel_a'
    );
    test_assert(
        TourPromptFeatureGate::isEnabled(['sno' => 'unknown-sno-not-in-registry'], $emptyRegistry) === false,
        'registry miss unknown sno OFF'
    );

    $hybridCfg = [
        'enabled' => true,
        'allowed_sno' => [$travelASno],
        'allowed_channels' => [],
    ];
    test_assert(
        HybridSearchFeatureGate::isEnabled(['sno' => $travelASno], $hybridCfg, $emptyRegistry) === true,
        'hybrid configOverride uses legacy allowlist (skips registry)'
    );
} finally {
    @unlink($emptyRegistryPath);
}

// 5. global master OFF
$masterOffPath = write_temp_registry([
    'schema_version' => 1,
    'global' => [
        'hybrid_search_master_enabled' => false,
        'tour_prompt_master_enabled' => false,
    ],
    'tenants' => [
        'travel_a' => [
            'line_channel_id' => $travelAChannel,
            'sno' => $travelASno,
            'depID' => 888,
            'storeNo' => 6290,
            'store_uid' => 6290,
            'provider_id_no' => 102,
            'status' => 'enabled',
            'features' => [
                'tour_prompt' => true,
                'hybrid_search' => true,
                'fixed_formatter' => true,
            ],
            'profile' => [],
            'gemini_policy' => [],
            'source_policy' => [],
        ],
    ],
]);
try {
    $masterOffRegistry = new ConfigTenantRegistry($masterOffPath);
    test_assert(
        TourPromptFeatureGate::isEnabled(['sno' => $travelASno], $masterOffRegistry) === false,
        'tour_prompt global master OFF'
    );
    test_assert(
        HybridSearchFeatureGate::isEnabled(['sno' => $travelASno], null, $masterOffRegistry) === false,
        'hybrid_search global master OFF'
    );
} finally {
    @unlink($masterOffPath);
}

// 6. channel-based registry resolution
test_assert(
    TourPromptFeatureGate::isEnabled(['channelId' => $travelAChannel], $registry) === true,
    'travel_a resolved by channelId'
);

if ($failures === 0) {
    echo "OK: feature gate registry bridge tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
