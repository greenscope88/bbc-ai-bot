<?php

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingShadowProbe.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';

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
$now = new \DateTimeImmutable('2026-06-30 10:00:00', $tz);
$pilotSno = '5f99b8d665e8444d';
$cid = $pilotSno . ':line:Uaiu-shadow';

$flagOn = [
    AiIntentUnderstandingShadowProbe::FLAG_ENABLED => true,
    AiIntentUnderstandingShadowProbe::FLAG_TENANTS => [$pilotSno],
];
$flagOff = [
    AiIntentUnderstandingShadowProbe::FLAG_ENABLED => false,
    AiIntentUnderstandingShadowProbe::FLAG_TENANTS => [$pilotSno],
];

// Capturing logger so tests never touch disk logs.
$captured = [];
$logger = static function (string $step, array $context) use (&$captured): void {
    $captured[] = ['step' => $step, 'context' => $context];
};

// Runtime backed by an isolated in-memory facade (read-only snapshots; disk-isolated).
$runtime = static function () {
    return AiIntentUnderstandingRuntime::createForTesting(
        null,
        AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
    );
};

// --- isEnabled -------------------------------------------------------------
test_assert(
    AiIntentUnderstandingShadowProbe::isEnabled($flagOn, $pilotSno) === true,
    'isEnabled: flag on + tenant match => true'
);
test_assert(
    AiIntentUnderstandingShadowProbe::isEnabled($flagOff, $pilotSno) === false,
    'isEnabled: flag off => false'
);
test_assert(
    AiIntentUnderstandingShadowProbe::isEnabled($flagOn, 'other-sno') === false,
    'isEnabled: tenant mismatch => false'
);
test_assert(
    AiIntentUnderstandingShadowProbe::isEnabled($flagOn, '') === false,
    'isEnabled: empty tenant => false'
);

// --- flag OFF: probe does not execute, no log emitted -----------------------
$captured = [];
$resOff = AiIntentUnderstandingShadowProbe::run([
    'config' => $flagOff,
    'tenant_sno' => $pilotSno,
    'conversation_id' => $cid,
    'message' => '我想3月去東京自由行',
    'now' => $now,
], $runtime(), $logger);
test_assert($resOff['executed'] === false, 'flag off: executed=false');
test_assert($resOff['reason'] === 'shadow_disabled_or_tenant_unmatched', 'flag off: reason');
test_assert($captured === [], 'flag off: no log emitted');

// --- flag ON + tenant mismatch: does not execute ---------------------------
$captured = [];
$resMismatch = AiIntentUnderstandingShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => 'not-pilot',
    'conversation_id' => $cid,
    'message' => '我想3月去東京自由行',
    'now' => $now,
], $runtime(), $logger);
test_assert($resMismatch['executed'] === false, 'tenant mismatch: executed=false');
test_assert($captured === [], 'tenant mismatch: no log emitted');

// --- flag ON + tenant match: AIU-only shadow understanding -------------------
$captured = [];
$resOn = AiIntentUnderstandingShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => $cid,
    'message' => '我想3月去東京自由行',
    'trace_id' => 'trace-on',
    'now' => $now,
], $runtime(), $logger);
test_assert($resOn['executed'] === true, 'flag on + match: executed=true');
test_assert($resOn['aiu_intent'] === AiIntentCategory::PRODUCT_SEARCH, 'flag on: aiu intent product_search');
test_assert(!array_key_exists('dispatch_plan', $resOn), 'flag on: no dispatch_plan');
test_assert(!array_key_exists('execution_hint', $resOn), 'flag on: no execution_hint');
test_assert($resOn['owner_snapshot'] === 'AI', 'flag on: owner_snapshot AI');
test_assert($resOn['clarification_required'] === false, 'flag on: clarification_required false (product complete)');
test_assert($resOn['message_hash'] !== '' && strlen($resOn['message_hash']) === 16, 'flag on: message_hash present (16 hex)');
test_assert(!array_key_exists('legacy_intent_type', $resOn), 'flag on: no legacy_intent_type in return');
test_assert(!array_key_exists('parity', $resOn), 'flag on: no parity in return');
test_assert(count($captured) === 1, 'flag on: exactly one log emitted');
test_assert($captured[0]['step'] === 'intent_understanding_shadow_probe', 'flag on: log step name');
test_assert($captured[0]['context']['shadow_mode'] === true, 'flag on: shadow_mode flagged in log');
test_assert(!array_key_exists('legacy_intent_type', $captured[0]['context']), 'flag on: no legacy_intent_type in log');
test_assert(!array_key_exists('parity', $captured[0]['context']), 'flag on: no parity in log');
test_assert(array_key_exists('clarification_required', $captured[0]['context']), 'flag on: clarification_required logged');
test_assert(array_key_exists('clarification_reason', $captured[0]['context']), 'flag on: clarification_reason logged');
test_assert(array_key_exists('message_hash', $captured[0]['context']), 'flag on: message_hash logged');
test_assert(!array_key_exists('dispatch_plan', $captured[0]['context']), 'flag on: log has no dispatch_plan');
test_assert(!in_array('我想3月去東京自由行', $captured[0]['context'], true), 'flag on: raw message not logged');

// --- knowledge query: AIU-only ----------------------------------------------
$resKnow = AiIntentUnderstandingShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => $cid,
    'message' => '請問你們的客服電話是多少',
    'now' => $now,
], $runtime(), $logger);
test_assert($resKnow['aiu_intent'] === AiIntentCategory::KNOWLEDGE, 'knowledge: aiu knowledge');
test_assert(!array_key_exists('dispatch_plan', $resKnow), 'knowledge: no dispatch_plan');
test_assert(!array_key_exists('legacy_intent_type', $resKnow), 'knowledge: no legacy_intent_type');
test_assert(!array_key_exists('parity', $resKnow), 'knowledge: no parity');

// --- missing conversation id: does not execute -----------------------------
$resNoConv = AiIntentUnderstandingShadowProbe::run([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => '',
    'message' => '我想3月去東京自由行',
    'now' => $now,
], $runtime(), $logger);
test_assert($resNoConv['executed'] === false, 'missing conversation id: executed=false');
test_assert($resNoConv['reason'] === 'missing_conversation_id', 'missing conversation id: reason');

// --- exception safety: a throwing logger must never bubble out of run() -----
$throwingLogger = static function (string $step, array $context): void {
    throw new \RuntimeException('boom from logger');
};
$threw = false;
$resExc = ['executed' => null];
try {
    $resExc = AiIntentUnderstandingShadowProbe::run([
        'config' => $flagOn,
        'tenant_sno' => $pilotSno,
        'conversation_id' => $cid,
        'message' => '我想3月去東京自由行',
        'now' => $now,
    ], $runtime(), $throwingLogger);
} catch (\Throwable $e) {
    $threw = true;
}
test_assert($threw === false, 'exception safety: run() never throws even if logger throws');
test_assert($resExc['executed'] === true, 'exception safety: main flow completes despite logger failure');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_ai_intent_understanding_shadow_probe\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_ai_intent_understanding_shadow_probe\n");
exit(1);
