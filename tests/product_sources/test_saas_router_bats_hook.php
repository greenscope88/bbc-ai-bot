<?php
declare(strict_types=1);

/**
 * Phase 9-B-26B-4B: SaaSRouter BATS hook tests (no HTTP / LINE / Gemini).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$disabledConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [],
    'channels' => [],
];

$dryRunConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [
        'cccccccccccccccc' => ['mode' => BatsFeatureGate::MODE_DRY_RUN],
    ],
    'channels' => [],
];

$enabledConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [
        'aaaaaaaaaaaaaaaa' => ['mode' => BatsFeatureGate::MODE_ENABLED],
    ],
    'channels' => [],
];

$enabledInvalidTenantConfig = [
    'defaults' => ['mode' => BatsFeatureGate::MODE_DISABLED],
    'tenants' => [
        'not-a-valid-sno' => ['mode' => BatsFeatureGate::MODE_ENABLED],
    ],
    'channels' => [],
];

$tenantDryRun = [
    'sno' => 'cccccccccccccccc',
    'channel_id' => 'line-channel-1',
];

$tenantEnabled = [
    'sno' => 'aaaaaaaaaaaaaaaa',
    'channel_id' => 'line-channel-2',
];

$tenantInvalid = [
    'sno' => 'not-a-valid-sno',
    'channel_id' => 'line-channel-3',
];

$tenantEmpty = [
    'sno' => '',
    'channel_id' => 'line-channel-4',
];

// Case 1: default disabled → fall-through (null)
try {
    $gate = new BatsFeatureGate($disabledConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantDryRun,
        '請推薦東京五日團',
        'trace-case-1',
        'line-channel-1',
        $gate
    );
    test_assert($result === null, 'case1 null for disabled gate');
    test_assert(true, 'case1 default disabled fall-through PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: dry_run tenant → bats_dry_run
try {
    $gate = new BatsFeatureGate($dryRunConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantDryRun,
        '請推薦東京五日團',
        'trace-case-2',
        'line-channel-1',
        $gate
    );
    test_assert(is_array($result), 'case2 result array');
    test_assert(($result['message'] ?? '') === 'bats_dry_run', 'case2 bats_dry_run message');
    test_assert(($result['bats']['status'] ?? '') === 'dry_run', 'case2 orchestrator dry_run');
    test_assert(true, 'case2 dry_run tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: enabled tenant → bats_enabled_skeleton (4B skeleton, no LINE)
try {
    $gate = new BatsFeatureGate($enabledConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantEnabled,
        '請推薦大阪三日團',
        'trace-case-3',
        'line-channel-2',
        $gate
    );
    test_assert(is_array($result), 'case3 result array');
    test_assert(($result['message'] ?? '') === 'bats_enabled_skeleton', 'case3 bats_enabled_skeleton');
    test_assert(($result['bats']['status'] ?? '') === 'accepted', 'case3 orchestrator accepted');
    test_assert(true, 'case3 enabled tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: invalid tenant_sno → fall-through (BATS not activated)
try {
    $gate = new BatsFeatureGate($enabledInvalidTenantConfig);
    $result = SaaSRouter::attemptBatsWebhookHook(
        $tenantInvalid,
        '請推薦行程',
        'trace-case-4',
        'line-channel-3',
        $gate
    );
    test_assert($result === null, 'case4 null for invalid tenant');
    test_assert(true, 'case4 invalid tenant fall-through PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: hello/weather run before hook (documentation guard via source order)
try {
    $routerSource = (string) file_get_contents(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php'
    );
    $helloPos = strpos($routerSource, "if (\$userMessage === '你好')");
    $weatherPos = strpos($routerSource, "weather_query");
    $resolvePos = strpos($routerSource, 'TenantResolver::resolve');
    $hookPos = strpos($routerSource, 'attemptBatsWebhookHook');
    test_assert($helloPos !== false && $weatherPos !== false && $resolvePos !== false && $hookPos !== false, 'case5 markers exist');
    test_assert($helloPos < $resolvePos, 'case5 hello before tenant resolve');
    test_assert($weatherPos < $resolvePos, 'case5 weather before tenant resolve');
    test_assert($resolvePos < $hookPos, 'case5 hook after tenant resolve');
    test_assert(true, 'case5 hello/weather before BATS hook PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: hook path has no LINE / Gemini / HTTP calls
try {
    $routerSource = (string) file_get_contents(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php'
    );
    $hookStart = strpos($routerSource, 'function attemptBatsWebhookHook');
    $hookEnd = strpos($routerSource, 'private static function loadBatsFeatureConfig');
    test_assert($hookStart !== false && $hookEnd !== false && $hookEnd > $hookStart, 'case6 hook block found');
    $hookBlock = substr($routerSource, $hookStart, $hookEnd - $hookStart);
    test_assert(stripos($hookBlock, 'LineService') === false, 'case6 no LineService in hook');
    test_assert(stripos($hookBlock, 'callGemini') === false, 'case6 no callGemini in hook');
    test_assert(stripos($hookBlock, 'curl_') === false, 'case6 no curl in hook');
    test_assert(stripos($hookBlock, 'api.line.me') === false, 'case6 no LINE API url in hook');
    test_assert(true, 'case6 no HTTP/LINE/Gemini in hook PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: config/bats_feature.php loads
try {
    $configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bats_feature.php';
    test_assert(is_file($configPath), 'case7 config file exists');
    $loaded = require $configPath;
    test_assert(is_array($loaded), 'case7 config is array');
    test_assert(
        ($loaded['defaults']['mode'] ?? '') === BatsFeatureGate::MODE_DISABLED,
        'case7 defaults disabled'
    );
    $gate = new BatsFeatureGate($loaded);
    test_assert(
        $gate->resolveMode(['tenant_sno' => '5f99b8d665e8444d', 'channel' => '']) === BatsFeatureGate::MODE_DRY_RUN,
        'case7 pilot tenant dry_run'
    );
    test_assert(true, 'case7 feature config load PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_saas_router_bats_hook (all passed)\n");
exit(0);
