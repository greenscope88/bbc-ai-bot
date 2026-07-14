<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationRuntimeShadowProbe.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new \DateTimeZone('Asia/Taipei');
$now = new \DateTimeImmutable('2026-06-27 10:00:00', $tz);
$pilotSno = '5f99b8d665e8444d';

$flagOn = [
    ConversationRuntimeShadowProbe::FLAG_ENABLED => true,
    ConversationRuntimeShadowProbe::FLAG_TENANTS => [$pilotSno],
];
$flagOff = [
    ConversationRuntimeShadowProbe::FLAG_ENABLED => false,
    ConversationRuntimeShadowProbe::FLAG_TENANTS => [$pilotSno],
];

// Capturing logger so tests never touch disk logs.
$captured = [];
$logger = static function (string $step, array $context) use (&$captured): void {
    $captured[] = ['step' => $step, 'context' => $context];
};

// --- isEnabled -------------------------------------------------------------
test_assert(
    ConversationRuntimeShadowProbe::isEnabled($flagOn, $pilotSno) === true,
    'isEnabled: flag on + tenant match => true'
);
test_assert(
    ConversationRuntimeShadowProbe::isEnabled($flagOff, $pilotSno) === false,
    'isEnabled: flag off => false'
);
test_assert(
    ConversationRuntimeShadowProbe::isEnabled($flagOn, 'other-sno') === false,
    'isEnabled: tenant mismatch => false'
);
test_assert(
    ConversationRuntimeShadowProbe::isEnabled($flagOn, '') === false,
    'isEnabled: empty tenant => false'
);

// --- flag OFF: probe does not execute, no log emitted -----------------------
$captured = [];
$resOff = ConversationRuntimeShadowProbe::run([
    'config' => $flagOff,
    'tenant_sno' => $pilotSno,
    'conversation_id' => 'conv-off',
    'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
    'legacy_allowed' => true,
    'now' => $now,
], ConversationRuntimeFacade::createForTesting(), $logger);
test_assert($resOff['executed'] === false, 'flag off: executed=false');
test_assert($resOff['reason'] === 'shadow_disabled_or_tenant_unmatched', 'flag off: reason');
test_assert($captured === [], 'flag off: no log emitted');

// --- flag ON + tenant mismatch: does not execute ---------------------------
$captured = [];
$resMismatch = ConversationRuntimeShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => 'not-pilot',
    'conversation_id' => 'conv-mismatch',
    'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
    'legacy_allowed' => true,
    'now' => $now,
], ConversationRuntimeFacade::createForTesting(), $logger);
test_assert($resMismatch['executed'] === false, 'tenant mismatch: executed=false');
test_assert($captured === [], 'tenant mismatch: no log emitted');

// --- flag ON + tenant match: executes shadow evaluation --------------------
$captured = [];
$facade = ConversationRuntimeFacade::createForTesting();
$resOn = ConversationRuntimeShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => 'conv-on',
    'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
    'legacy_conversation_status' => 'AI_ACTIVE',
    'legacy_allowed' => true,
    'trace_id' => 'trace-on',
    'now' => $now,
], $facade, $logger);
test_assert($resOn['executed'] === true, 'flag on + match: executed=true');
test_assert($resOn['owner'] === ConversationOwner::AI, 'flag on: shadow owner AI (no human takeover)');
test_assert($resOn['shadow_allowed'] === true, 'flag on: shadow gate allows AI');
test_assert($resOn['legacy_allowed'] === true, 'flag on: legacy_allowed echoed');
test_assert($resOn['parity'] === true, 'flag on: parity true (AI-active case)');
test_assert(count($captured) === 1, 'flag on: exactly one log emitted');
test_assert($captured[0]['step'] === 'conversation_runtime_shadow_probe', 'flag on: log step name');
test_assert($captured[0]['context']['shadow_mode'] === true, 'flag on: shadow_mode flagged in log');

// --- parity detection: legacy blocks but shadow allows --------------------
$captured = [];
$resParity = ConversationRuntimeShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => 'conv-parity',
    'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
    'legacy_conversation_status' => 'HUMAN_ACTIVE',
    'legacy_allowed' => false,
    'now' => $now,
], ConversationRuntimeFacade::createForTesting(), $logger);
test_assert($resParity['executed'] === true, 'parity case: executed');
test_assert($resParity['shadow_allowed'] === true, 'parity case: shadow allows (default AI)');
test_assert($resParity['parity'] === false, 'parity case: parity false when legacy blocks but shadow allows');

// --- missing conversation id: does not execute -----------------------------
$resNoConv = ConversationRuntimeShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => '',
    'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
    'legacy_allowed' => true,
    'now' => $now,
], ConversationRuntimeFacade::createForTesting(), $logger);
test_assert($resNoConv['executed'] === false, 'missing conversation id: executed=false');
test_assert($resNoConv['reason'] === 'missing_conversation_id', 'missing conversation id: reason');

// --- exception safety: a throwing logger must never bubble out of run() -----
// emit() is defensive, so a logger that throws is swallowed and the main flow
// completes normally (executed stays true). This proves run() never throws.
$throwingLogger = static function (string $step, array $context): void {
    throw new \RuntimeException('boom from logger');
};
$threw = false;
$resExc = ['executed' => null];
try {
    $resExc = ConversationRuntimeShadowProbe::run([
        'config' => $flagOn,
        'tenant_sno' => $pilotSno,
        'conversation_id' => 'conv-exc',
        'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
        'legacy_allowed' => true,
        'now' => $now,
    ], ConversationRuntimeFacade::createForTesting(), $throwingLogger);
} catch (\Throwable $e) {
    $threw = true;
}
test_assert($threw === false, 'exception safety: run() never throws even if logger throws');
test_assert($resExc['executed'] === true, 'exception safety: main flow completes despite logger failure');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_conversation_runtime_shadow_probe\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_conversation_runtime_shadow_probe\n");
exit(1);
