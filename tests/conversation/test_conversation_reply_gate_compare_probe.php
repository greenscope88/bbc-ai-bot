<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationReplyGateCompareProbe.php';

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
    ConversationReplyGateCompareProbe::FLAG_ENABLED => true,
    ConversationReplyGateCompareProbe::FLAG_TENANTS => [$pilotSno],
];
$flagOff = [
    ConversationReplyGateCompareProbe::FLAG_ENABLED => false,
    ConversationReplyGateCompareProbe::FLAG_TENANTS => [$pilotSno],
];

$captured = [];
$logger = static function (string $step, array $context) use (&$captured): void {
    $captured[] = ['step' => $step, 'context' => $context];
};

// --- isEnabled -------------------------------------------------------------
test_assert(ConversationReplyGateCompareProbe::isEnabled($flagOn, $pilotSno) === true, 'isEnabled: on + match');
test_assert(ConversationReplyGateCompareProbe::isEnabled($flagOff, $pilotSno) === false, 'isEnabled: off');
test_assert(ConversationReplyGateCompareProbe::isEnabled($flagOn, 'other') === false, 'isEnabled: mismatch');
test_assert(ConversationReplyGateCompareProbe::isEnabled($flagOn, '') === false, 'isEnabled: empty tenant');

// --- flag OFF: not executed, no log ----------------------------------------
$captured = [];
$resOff = ConversationReplyGateCompareProbe::compare([
    'config' => $flagOff,
    'tenant_sno' => $pilotSno,
    'conversation_id' => 'conv-off',
    'gate_point' => ConversationReplyGateCompareProbe::GATE_POINT_PRODUCT_FINAL,
    'legacy_status' => 'AI_ACTIVE',
    'legacy_allowed' => true,
    'now' => $now,
], ConversationStateRuntime::createForTesting(), $logger);
test_assert($resOff['executed'] === false, 'flag off: executed=false');
test_assert($resOff['reason'] === 'compare_disabled_or_tenant_unmatched', 'flag off: reason');
test_assert($captured === [], 'flag off: no log');

// --- tenant mismatch: not executed -----------------------------------------
$captured = [];
$resMismatch = ConversationReplyGateCompareProbe::compare([
    'config' => $flagOn,
    'tenant_sno' => 'not-pilot',
    'conversation_id' => 'conv-mismatch',
    'gate_point' => ConversationReplyGateCompareProbe::GATE_POINT_PRODUCT_FINAL,
    'legacy_status' => 'AI_ACTIVE',
    'legacy_allowed' => true,
    'now' => $now,
], ConversationStateRuntime::createForTesting(), $logger);
test_assert($resMismatch['executed'] === false, 'mismatch: executed=false');
test_assert($captured === [], 'mismatch: no log');

// --- flag ON + match, default AI owner, legacy allowed => parity true -------
$captured = [];
$resAi = ConversationReplyGateCompareProbe::compare([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => 'conv-ai',
    'gate_point' => ConversationReplyGateCompareProbe::GATE_POINT_KNOWLEDGE_FINAL,
    'legacy_status' => 'AI_ACTIVE',
    'legacy_allowed' => true,
    'trace_id' => 'trace-ai',
    'now' => $now,
], ConversationStateRuntime::createForTesting(), $logger);
test_assert($resAi['executed'] === true, 'AI: executed');
test_assert($resAi['new_owner'] === ConversationOwner::AI, 'AI: new_owner AI (no state)');
test_assert($resAi['new_allowed'] === true, 'AI: new gate allows');
test_assert($resAi['parity'] === true, 'AI: parity true (both allow)');
test_assert($resAi['gate_point'] === ConversationReplyGateCompareProbe::GATE_POINT_KNOWLEDGE_FINAL, 'AI: gate_point echoed');
test_assert(count($captured) === 1, 'AI: one log');
test_assert($captured[0]['step'] === 'conversation_reply_gate_compare', 'AI: log step');
test_assert($captured[0]['context']['compare_mode'] === true, 'AI: compare_mode flagged');
test_assert($captured[0]['context']['parity'] === true, 'AI: log parity true');

// --- parity FALSE: legacy blocks but new (AI) allows -----------------------
$captured = [];
$resParityFalse = ConversationReplyGateCompareProbe::compare([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => 'conv-parity-false',
    'gate_point' => ConversationReplyGateCompareProbe::GATE_POINT_EARLY_BLOCK,
    'legacy_status' => 'HUMAN_ACTIVE',
    'legacy_allowed' => false,
    'now' => $now,
], ConversationStateRuntime::createForTesting(), $logger);
test_assert($resParityFalse['executed'] === true, 'parity-false: executed');
test_assert($resParityFalse['new_allowed'] === true, 'parity-false: new allows (default AI)');
test_assert($resParityFalse['parity'] === false, 'parity-false: parity false');

// --- HUMAN owner via injected state: new blocks; parity true vs legacy block -
$captured = [];
$humanState = ConversationStateRuntime::createForTesting();
$humanState->recordHumanAgentMessage('conv-human', $now);
$resHuman = ConversationReplyGateCompareProbe::compare([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => 'conv-human',
    'gate_point' => ConversationReplyGateCompareProbe::GATE_POINT_PRODUCT_FINAL,
    'legacy_status' => 'HUMAN_ACTIVE',
    'legacy_allowed' => false,
    'now' => $now->modify('+30 seconds'),
], $humanState, $logger);
test_assert($resHuman['executed'] === true, 'human: executed');
test_assert($resHuman['new_owner'] === ConversationOwner::HUMAN, 'human: new_owner HUMAN within hold');
test_assert($resHuman['new_allowed'] === false, 'human: new gate blocks');
test_assert($resHuman['parity'] === true, 'human: parity true (both block)');

// --- missing conversation id -----------------------------------------------
$resNoConv = ConversationReplyGateCompareProbe::compare([
    'config' => $flagOn,
    'tenant_sno' => $pilotSno,
    'conversation_id' => '',
    'gate_point' => ConversationReplyGateCompareProbe::GATE_POINT_PRODUCT_FINAL,
    'legacy_status' => 'AI_ACTIVE',
    'legacy_allowed' => true,
    'now' => $now,
], ConversationStateRuntime::createForTesting(), $logger);
test_assert($resNoConv['executed'] === false, 'missing conv id: executed=false');
test_assert($resNoConv['reason'] === 'missing_conversation_id', 'missing conv id: reason');

// --- exception safety: throwing logger must never bubble out ----------------
$throwingLogger = static function (string $step, array $context): void {
    throw new \RuntimeException('boom from logger');
};
$threw = false;
$resExc = ['executed' => null];
try {
    $resExc = ConversationReplyGateCompareProbe::compare([
        'config' => $flagOn,
        'tenant_sno' => $pilotSno,
        'conversation_id' => 'conv-exc',
        'gate_point' => ConversationReplyGateCompareProbe::GATE_POINT_PRODUCT_FINAL,
        'legacy_status' => 'AI_ACTIVE',
        'legacy_allowed' => true,
        'now' => $now,
    ], ConversationStateRuntime::createForTesting(), $throwingLogger);
} catch (\Throwable $e) {
    $threw = true;
}
test_assert($threw === false, 'exception safety: compare() never throws even if logger throws');
test_assert($resExc['executed'] === true, 'exception safety: main flow completes despite logger failure');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_conversation_reply_gate_compare_probe\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_conversation_reply_gate_compare_probe\n");
exit(1);
