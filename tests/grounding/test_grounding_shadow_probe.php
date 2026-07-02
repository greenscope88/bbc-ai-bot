<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-2a — GroundingShadowProbe tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingShadowProbe.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';

$failures = 0;
$logs = [];

function gsp_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$disabled = GroundingShadowProbe::run([
    'tenant_sno' => '1001',
    'conversation_id' => 'conv-1',
    'config' => [
        'grounding_layer_shadow_enabled' => false,
        'grounding_layer_shadow_tenant_snos' => ['1001'],
        'grounding_layer_authoritative_enabled' => false,
    ],
]);
gsp_assert($disabled['executed'] === false, 'flag off does not execute');
gsp_assert($disabled['reason'] === 'shadow_disabled_or_tenant_unmatched', 'flag off reason');

$unmatched = GroundingShadowProbe::run([
    'tenant_sno' => '9999',
    'conversation_id' => 'conv-1',
    'config' => [
        'grounding_layer_shadow_enabled' => true,
        'grounding_layer_shadow_tenant_snos' => ['1001'],
        'grounding_layer_authoritative_enabled' => false,
    ],
]);
gsp_assert($unmatched['executed'] === false, 'tenant unmatched does not execute');

$logger = static function (string $step, array $context) use (&$logs): void {
    $logs[] = ['step' => $step, 'context' => $context];
};

$enabled = GroundingShadowProbe::run([
    'path' => 'product',
    'trace_id' => 'trace-shadow',
    'tenant_sno' => '1001',
    'conversation_id' => 'conv-shadow',
    'customer_query' => '北海道 8月 五天',
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => [
        'reply_text' => 'legacy product reply',
        'grounded' => false,
        'recommendation_summary' => ['result_count' => 0],
        'product_list' => [],
    ],
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'bats_search_intent' => [
        'destination' => '北海道',
        'date_from' => '8月',
    ],
    'legacy_reply_text' => 'legacy product reply',
    'config' => [
        'grounding_layer_shadow_enabled' => true,
        'grounding_layer_shadow_tenant_snos' => ['1001'],
        'grounding_layer_authoritative_enabled' => false,
        'grounded_composer_generative_enabled' => false,
    ],
], null, $logger);

gsp_assert($enabled['executed'] === true, 'shadow executes when enabled');
gsp_assert(($enabled['destination'] ?? '') === '北海道', 'shadow logs destination');
gsp_assert(($enabled['duration'] ?? '') === '五天', 'shadow logs duration');
gsp_assert($logs !== [] && $logs[0]['step'] === 'grounding_layer_shadow_probe', 'shadow emits log');

$authoritativeBlocked = GroundingShadowProbe::run([
    'tenant_sno' => '1001',
    'conversation_id' => 'conv-1',
    'config' => [
        'grounding_layer_shadow_enabled' => true,
        'grounding_layer_shadow_tenant_snos' => ['1001'],
        'grounding_layer_authoritative_enabled' => true,
    ],
]);
gsp_assert(
    $authoritativeBlocked['executed'] === false
    && $authoritativeBlocked['reason'] === 'authoritative_flag_must_remain_off_in_f2a',
    'authoritative flag blocks shadow in f2a guard'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_grounding_shadow_probe.php\n");
exit(0);
