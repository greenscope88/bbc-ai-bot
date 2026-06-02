<?php
declare(strict_types=1);

/**
 * Phase 9-B-26B-1: BATS webhook orchestrator skeleton tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . 'BatsFeatureGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . 'BatsWebhookOrchestrator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param mixed $value
 */
function containsSensitiveKey($value): bool
{
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $k => $v) {
        $key = is_string($k) ? strtolower($k) : '';
        if (
            $key === 'replytoken'
            || $key === 'accesstoken'
            || $key === 'apikey'
            || $key === 'secret'
            || $key === 'channel_secret'
        ) {
            return true;
        }
        if (is_array($v) && containsSensitiveKey($v)) {
            return true;
        }
    }

    return false;
}

function makeOrchestrator(array $config): BatsWebhookOrchestrator
{
    return new BatsWebhookOrchestrator(new BatsFeatureGate($config));
}

$testConfig = [
    'defaults' => [
        'mode' => BatsFeatureGate::MODE_DISABLED,
    ],
    'tenants' => [
        'aaaaaaaaaaaaaaaa' => [
            'mode' => BatsFeatureGate::MODE_ENABLED,
        ],
        'bbbbbbbbbbbbbbbb' => [
            'mode' => BatsFeatureGate::MODE_DISABLED,
        ],
        'cccccccccccccccc' => [
            'mode' => BatsFeatureGate::MODE_DRY_RUN,
        ],
    ],
    'channels' => [
        'line-channel-dry-run' => [
            'mode' => BatsFeatureGate::MODE_DRY_RUN,
        ],
    ],
];

$orchestrator = makeOrchestrator($testConfig);

// Case 1: enabled tenant
try {
    $result = $orchestrator->handle([
        'tenant_sno' => 'aaaaaaaaaaaaaaaa',
        'customer_message' => '請推薦東京五日團',
    ]);
    test_assert($result['status'] === 'accepted', 'case1 status accepted');
    test_assert($result['bats_mode'] === BatsFeatureGate::MODE_ENABLED, 'case1 enabled mode');
    test_assert($result['tenant_sno'] === 'aaaaaaaaaaaaaaaa', 'case1 tenant_sno');
    test_assert(isset($result['decision_snapshot']) && is_array($result['decision_snapshot']), 'case1 snapshot exists');
    test_assert(($result['decision_snapshot']['fallthrough_to_legacy'] ?? false) === true, 'case1 snapshot fallthrough true');
    test_assert(true, 'case1 enabled tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: disabled tenant
try {
    $result = $orchestrator->handle([
        'tenant_sno' => 'bbbbbbbbbbbbbbbb',
        'customer_message' => '請推薦東京五日團',
    ]);
    test_assert($result['status'] === 'disabled', 'case2 status disabled');
    test_assert($result['bats_mode'] === BatsFeatureGate::MODE_DISABLED, 'case2 disabled mode');
    test_assert(isset($result['decision_snapshot']) && is_array($result['decision_snapshot']), 'case2 snapshot exists');
    test_assert(true, 'case2 disabled tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: dry_run tenant
try {
    $result = $orchestrator->handle([
        'tenant_sno' => 'cccccccccccccccc',
        'customer_message' => '請推薦大阪三日團',
    ]);
    test_assert($result['status'] === 'dry_run', 'case3 status dry_run');
    test_assert($result['bats_mode'] === BatsFeatureGate::MODE_DRY_RUN, 'case3 dry_run mode');
    test_assert(isset($result['decision_snapshot']) && is_array($result['decision_snapshot']), 'case3 snapshot exists');
    test_assert(($result['decision_snapshot']['snapshot_version'] ?? 0) === 1, 'case3 snapshot version');
    test_assert(($result['decision_snapshot']['fallthrough_to_legacy'] ?? false) === true, 'case3 fallthrough true');
    test_assert(($result['decision_snapshot']['reason_code'] ?? '') === 'DRY_RUN_SNAPSHOT_ONLY', 'case3 reason code');
    test_assert(($result['decision_snapshot']['query']['raw'] ?? '') === '請推薦大阪三日團', 'case3 raw query');
    test_assert(($result['decision_snapshot']['search_condition']['available'] ?? true) === false, 'case3 search_condition unavailable');
    test_assert(($result['decision_snapshot']['candidate_sources']['available'] ?? true) === false, 'case3 candidate_sources unavailable');
    test_assert(($result['decision_snapshot']['gemini_context']['available'] ?? true) === false, 'case3 gemini_context unavailable');
    test_assert(!containsSensitiveKey($result['decision_snapshot']), 'case3 snapshot has no sensitive keys');
    test_assert(true, 'case3 dry_run tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: trace id
try {
    $result = $orchestrator->handle([
        'tenant_sno' => 'aaaaaaaaaaaaaaaa',
        'customer_message' => '請推薦京都行程',
        'trace_id' => 'trace-case-4',
    ]);
    test_assert($result['trace_id'] === 'trace-case-4', 'case4 custom trace_id preserved');
    test_assert(($result['decision_snapshot']['trace_id'] ?? '') === 'trace-case-4', 'case4 snapshot trace id');
    test_assert(true, 'case4 trace id PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: empty message
try {
    $result = $orchestrator->handle([
        'tenant_sno' => 'aaaaaaaaaaaaaaaa',
        'customer_message' => '',
    ]);
    test_assert($result['status'] === 'rejected', 'case5 rejected empty message');
    test_assert($result['message'] === 'customer_message is required', 'case5 empty message message');
    test_assert(isset($result['decision_snapshot']) && is_array($result['decision_snapshot']), 'case5 snapshot exists');
    test_assert(true, 'case5 empty message PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: invalid tenant
try {
    $result = $orchestrator->handle([
        'tenant_sno' => 'not-a-valid-tenant',
        'customer_message' => 'hello',
    ]);
    test_assert($result['status'] === 'rejected', 'case6 rejected invalid tenant');
    test_assert($result['message'] === 'invalid tenant_sno', 'case6 invalid tenant message');
    test_assert(isset($result['decision_snapshot']) && is_array($result['decision_snapshot']), 'case6 snapshot exists');
    test_assert(true, 'case6 invalid tenant PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: round trip
try {
    $result = $orchestrator->handle([
        'tenant_sno' => 'cccccccccccccccc',
        'customer_message' => '請推薦北海道行程',
        'trace_id' => 'trace-case-7',
    ]);
    $roundTrip = $orchestrator->normalizeResult($result);
    test_assert($roundTrip === $result, 'case7 normalize round-trip');
    test_assert(!array_key_exists('http', $roundTrip), 'case7 no http key');
    test_assert(!array_key_exists('curl', $roundTrip), 'case7 no curl key');
    test_assert(!array_key_exists('replyToken', $roundTrip), 'case7 no replyToken');
    test_assert(!containsSensitiveKey($roundTrip), 'case7 no sensitive keys in result');
    test_assert(true, 'case7 round trip PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bats_webhook_orchestrator (all passed)\n");
exit(0);
