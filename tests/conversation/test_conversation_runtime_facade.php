<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
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

// ============================================================================
// ConversationReplyGate — Owner-based gating
// ============================================================================
test_assert(
    ConversationReplyGate::mayAiReply(ConversationOwner::AI) === true,
    'gate: Owner=AI allows AI reply'
);
test_assert(
    ConversationReplyGate::mayAiReply(ConversationOwner::HUMAN) === false,
    'gate: Owner=HUMAN blocks AI reply'
);

$gateAi = ConversationReplyGate::evaluate(ConversationOwner::AI);
test_assert(
    $gateAi['allowed'] === true && $gateAi['owner'] === ConversationOwner::AI && $gateAi['block_reason'] === '',
    'gate: evaluate(AI) -> allowed, no block reason'
);

$gateHuman = ConversationReplyGate::evaluate(ConversationOwner::HUMAN);
test_assert(
    $gateHuman['allowed'] === false
        && $gateHuman['owner'] === ConversationOwner::HUMAN
        && $gateHuman['block_reason'] === ConversationReplyGate::BLOCK_REASON_HUMAN_OWNER,
    'gate: evaluate(HUMAN) -> blocked with human_owner reason'
);

$gateThrew = false;
try {
    ConversationReplyGate::mayAiReply('BOGUS');
} catch (\InvalidArgumentException $e) {
    $gateThrew = true;
}
test_assert($gateThrew, 'gate: invalid owner throws');

// ============================================================================
// ConversationRuntimeFacade — single integration entry
// ============================================================================

// --- Default new conversation: Owner = AI, gate allows -----------------------
$facade = ConversationRuntimeFacade::createForTesting();
$now = new \DateTimeImmutable('2026-06-27 10:00:00', $tz);

$decision = $facade->handleCustomerMessage('conv-ai', [], $now);
test_assert($decision['owner'] === ConversationOwner::AI, 'facade: new conversation Owner=AI');
test_assert($decision['reply_gate']['allowed'] === true, 'facade: new conversation gate allows AI');
test_assert($decision['ai_resume_applied'] === false, 'facade: new conversation no resume');

// Facade wires Memory: a memory card is created/persisted for the conversation.
$card = $facade->memory()->get('conv-ai');
test_assert($card !== null, 'facade: memory card persisted after handleCustomerMessage');
test_assert(
    $card !== null && $card->getHumanHandoffStatus() === ConversationOwner::AI,
    'facade: memory owner mirror = AI'
);

// --- Memory changes are passed through to Memory Runtime ----------------------
$facade->handleCustomerMessage('conv-ai', [
    'memory_changes' => ['current_requirement' => '東京七月行程'],
], $now);
$card2 = $facade->memory()->get('conv-ai');
test_assert(
    $card2 !== null && $card2->getCurrentRequirement() === '東京七月行程',
    'facade: memory_changes forwarded to Memory Runtime'
);

// ============================================================================
// Human Takeover -> AI blocked
// ============================================================================
$facade2 = ConversationRuntimeFacade::createForTesting();
$t0 = new \DateTimeImmutable('2026-06-27 11:00:00', $tz);

$facade2->handleCustomerMessage('conv-takeover', [], $t0);

// Human agent sends a message -> Owner becomes HUMAN, gate blocks AI.
$human = $facade2->handleHumanAgentMessage('conv-takeover', $t0->modify('+10 seconds'));
test_assert($human['owner'] === ConversationOwner::HUMAN, 'facade: human message -> Owner=HUMAN');
test_assert($human['reply_gate']['allowed'] === false, 'facade: Owner=HUMAN gate blocks AI');

// State Runtime is the single owner source; mayAiReply reflects it.
test_assert(
    $facade2->mayAiReply('conv-takeover', $t0->modify('+20 seconds')) === false,
    'facade: mayAiReply false during human hold'
);

// Customer replies within 3 min -> still HUMAN (no resume).
$within = $facade2->handleCustomerMessage('conv-takeover', [], $t0->modify('+1 minute'));
test_assert($within['owner'] === ConversationOwner::HUMAN, 'facade: within 3min still HUMAN');
test_assert($within['ai_resume_applied'] === false, 'facade: within 3min no AI resume');
test_assert($within['reply_gate']['allowed'] === false, 'facade: within 3min gate still blocks');

// ============================================================================
// AI Resume — Owner=HUMAN + customer new message + >3min since last human msg
// ============================================================================
$facade3 = ConversationRuntimeFacade::createForTesting();
$h0 = new \DateTimeImmutable('2026-06-27 12:00:00', $tz);
$facade3->handleCustomerMessage('conv-resume', [], $h0);
$facade3->handleHumanAgentMessage('conv-resume', $h0->modify('+5 seconds'));

// Customer sends a new message after the 3-minute hold expires.
$resumed = $facade3->handleCustomerMessage('conv-resume', [], $h0->modify('+4 minutes'));
test_assert($resumed['ai_resume_applied'] === true, 'facade: AI resume applied after 3min + new customer msg');
test_assert($resumed['owner'] === ConversationOwner::AI, 'facade: Owner back to AI after resume');
test_assert($resumed['reply_gate']['allowed'] === true, 'facade: gate allows AI after resume');

// ============================================================================
// Policy — Completion Detection + Recommendation Eligibility
// ============================================================================
$facade4 = ConversationRuntimeFacade::createForTesting();
$p0 = new \DateTimeImmutable('2026-06-27 13:00:00', $tz);

// Service in progress -> Active, requirement not completed.
$active = $facade4->handleCustomerMessage('conv-policy', [
    'current_stage' => ConversationLifecycle::STAGE_ACTIVE,
    'completion_signals' => [],
    'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
    'tenant_policy' => ConversationPolicy::create(),
], $p0);
test_assert($active['lifecycle_stage'] === ConversationLifecycle::STAGE_ACTIVE, 'facade: stage Active');
test_assert($active['requirement_completed'] === false, 'facade: Active requirement not completed');
test_assert(
    $active['recommendation'][ConversationPolicy::RECOMMENDATION_PRODUCT]['eligible'] === false,
    'facade: no product recommendation while requirement open'
);

// Requirement resolved + product_search + product enabled -> product eligible.
$resolved = $facade4->handleCustomerMessage('conv-policy', [
    'current_stage' => ConversationLifecycle::STAGE_ACTIVE,
    'completion_signals' => ['requirement_resolved' => true],
    'intent_type' => AiRuntimeIntent::PRODUCT_SEARCH,
    'tenant_policy' => ConversationPolicy::create(),
], $p0->modify('+1 minute'));
test_assert($resolved['lifecycle_stage'] === ConversationLifecycle::STAGE_RESOLVED, 'facade: stage Resolved');
test_assert($resolved['requirement_completed'] === true, 'facade: Resolved requirement completed');
test_assert(
    $resolved['recommendation'][ConversationPolicy::RECOMMENDATION_PRODUCT]['eligible'] === true,
    'facade: product recommendation eligible after resolution'
);

// No intent/tenant policy -> recommendation map stays empty (no inference).
$noRec = $facade4->handleCustomerMessage('conv-policy', [
    'completion_signals' => ['requirement_resolved' => true],
], $p0->modify('+2 minutes'));
test_assert($noRec['recommendation'] === [], 'facade: no recommendation evaluation without intent/policy');

// ============================================================================
// Facade exposes the underlying runtimes (single integration entry).
// ============================================================================
test_assert($facade->memory() instanceof ConversationMemoryRuntime, 'facade: exposes Memory Runtime');
test_assert($facade->state() instanceof ConversationStateRuntime, 'facade: exposes State Runtime');
test_assert($facade->policy() instanceof ConversationPolicyRuntime, 'facade: exposes Policy Runtime');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_conversation_runtime_facade\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_conversation_runtime_facade\n");
exit(1);
