<?php

$root = dirname(__DIR__, 2);
$intentDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';
$convDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$sno = '5f99b8d665e8444d';
$userId = 'Uaiu01';
$cid = $sno . ':line:' . $userId;

/**
 * Build a runtime whose context loader is backed by an injected in-memory facade,
 * so Memory/State snapshots are deterministic and disk-isolated.
 */
function runtime_with(ConversationRuntimeFacade $facade): AiIntentUnderstandingRuntime
{
    return AiIntentUnderstandingRuntime::createForTesting(
        null,
        null,
        AiIntentContextLoader::createForTesting($facade)
    );
}

// ============================================================================
// 1. Product Intent → dispatch product + execution_hint product_search
// ============================================================================
$facade = ConversationRuntimeFacade::createForTesting();
$runtime = runtime_with($facade);
$r = $runtime->understand('我想3月去東京自由行', ['conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'product: intent category');
test_assert($r->getDispatchPlan() === DispatchPlan::PRODUCT, 'product: dispatch_plan product');
test_assert($r->getExecutionHint() === ExecutionHint::PRODUCT_SEARCH, 'product: hint product_search');
test_assert($r->isClarificationRequired() === false, 'product: no clarification');
test_assert(is_array($r->getEntity()) && ($r->getEntity()['destination'] ?? '') !== '', 'product: entity has destination');
test_assert($r->getOwnerSnapshot() === 'AI', 'product: owner AI');

// ============================================================================
// 2. Product Intent but missing date → Clarification Required
// ============================================================================
$r = $runtime->understand('我想去東京自由行', ['conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'product-clar: intent product');
test_assert($r->isClarificationRequired() === true, 'product-clar: clarification required');
test_assert($r->getDispatchPlan() === DispatchPlan::CLARIFICATION, 'product-clar: dispatch clarification');
test_assert($r->getExecutionHint() === ExecutionHint::PRODUCT_CLARIFICATION, 'product-clar: hint product_clarification');
test_assert($r->getClarificationReason() !== '', 'product-clar: reason present');

// ============================================================================
// 3. Knowledge Intent → dispatch knowledge + hint knowledge_resolve
// ============================================================================
$r = $runtime->understand('請問你們的客服電話是多少', ['conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::KNOWLEDGE, 'knowledge: intent category');
test_assert($r->getDispatchPlan() === DispatchPlan::KNOWLEDGE, 'knowledge: dispatch knowledge');
test_assert($r->getExecutionHint() === ExecutionHint::KNOWLEDGE_RESOLVE, 'knowledge: hint knowledge_resolve');
test_assert($r->isClarificationRequired() === false, 'knowledge: no clarification');
test_assert($r->getEntity() === [], 'knowledge: entity empty');

// ============================================================================
// 4. Ambiguous Intent → clarification required + dispatch clarification + null hint
// ============================================================================
$r = $runtime->understand('我想出去玩', ['conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::AMBIGUOUS, 'ambiguous: intent category');
test_assert($r->isClarificationRequired() === true, 'ambiguous: clarification required');
test_assert($r->getDispatchPlan() === DispatchPlan::CLARIFICATION, 'ambiguous: dispatch clarification');
test_assert($r->getExecutionHint() === null, 'ambiguous: hint null');
test_assert($r->getClarificationReason() === 'intent_ambiguous', 'ambiguous: reason intent_ambiguous');

// ============================================================================
// 5. Owner = HUMAN → dispatch human + hint human_blocked (Owner First)
// ============================================================================
$humanFacade = ConversationRuntimeFacade::createForTesting();
$now = new DateTimeImmutable('2026-06-30 13:00:00', $tz);
$humanFacade->state()->recordHumanAgentMessage($cid, $now); // Owner=HUMAN, within hold
$humanRuntime = runtime_with($humanFacade);
$r = $humanRuntime->understand('我想3月去東京自由行', [
    'conversation_id' => $cid,
    'now' => $now->modify('+1 minute'),
]);
test_assert($r->getOwnerSnapshot() === 'HUMAN', 'human: owner snapshot HUMAN');
test_assert($r->getDispatchPlan() === DispatchPlan::HUMAN, 'human: dispatch human');
test_assert($r->getExecutionHint() === ExecutionHint::HUMAN_BLOCKED, 'human: hint human_blocked');
// understanding still proceeds (intent computed)
test_assert($r->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'human: intent still understood');

// Owner First: AIU must not have mutated owner/state
$stateAfter = $humanFacade->state()->get($cid);
test_assert($stateAfter !== null && $stateAfter->getOwner() === 'HUMAN', 'human: state owner unchanged by AIU');

// ============================================================================
// 6. Resume Context (CA-006): owner HUMAN + customer new msg + >3min → resume_context populated
// ============================================================================
$resumeFacade = ConversationRuntimeFacade::createForTesting();
$base = new DateTimeImmutable('2026-06-30 13:00:00', $tz);
$resumeFacade->state()->recordHumanAgentMessage($cid, $base);
$resumeFacade->state()->recordCustomerMessage($cid, $base->modify('+4 minutes'));
$resumeRuntime = runtime_with($resumeFacade);
$r = $resumeRuntime->understand('那幫我看3月東京的行程', [
    'conversation_id' => $cid,
    'now' => $base->modify('+4 minutes'),
]);
test_assert($r->getResumeContext() !== null, 'resume: resume_context populated');
test_assert(($r->getResumeContext()['resumed_from_owner'] ?? '') === 'HUMAN', 'resume: resumed_from_owner HUMAN');
test_assert($r->getOwnerSnapshot() === 'AI', 'resume: effective owner AI (hold expired)');
// AIU read-only: stored owner still HUMAN (resume transfer is State Runtime job, not AIU)
test_assert($resumeFacade->state()->get($cid)->getOwner() === 'HUMAN', 'resume: AIU did not apply owner transfer');

// no-resume case → resume_context null
$freshFacade = ConversationRuntimeFacade::createForTesting();
$r = runtime_with($freshFacade)->understand('我想3月去東京自由行', ['conversation_id' => $cid]);
test_assert($r->getResumeContext() === null, 'no-resume: resume_context null');

// ============================================================================
// 7. context_snapshot / conversation_stage read from Memory (read-only)
// ============================================================================
$memFacade = ConversationRuntimeFacade::createForTesting();
$memFacade->memory()->remember($cid, ['conversation_stage' => 'Active', 'current_requirement' => '東京自由行']);
$r = runtime_with($memFacade)->understand('請問護照怎麼辦', ['conversation_id' => $cid]);
test_assert($r->getConversationStage() === 'Active', 'context: conversation_stage from memory');
test_assert(($r->getContextSnapshot()['current_requirement'] ?? '') === '東京自由行', 'context: snapshot from memory');
// read-only: memory card stage unchanged (AIU does not write)
test_assert($memFacade->memory()->get($cid)->getConversationStage() === 'Active', 'context: memory unchanged by AIU');

// ============================================================================
// 8. No conversation_id → safe defaults, still classifies
// ============================================================================
$r = runtime_with(ConversationRuntimeFacade::createForTesting())->understand('請問客服電話');
test_assert($r->getOwnerSnapshot() === 'AI', 'no-cid: owner default AI');
test_assert($r->getContextSnapshot() === [] && $r->getResumeContext() === null, 'no-cid: empty snapshots');
test_assert($r->getDispatchPlan() === DispatchPlan::KNOWLEDGE, 'no-cid: still classifies knowledge');

// ============================================================================
// 9. Contract Stability: toArray 9 keys + round-trip
// ============================================================================
$arr = $r->toArray();
test_assert(count($arr) === 9, 'stability: 9 fields');
$round = AiIntentUnderstandingResult::fromArray($arr);
test_assert($round->toArray() === $arr, 'stability: round-trip equals');

// ============================================================================
// 10. execution_hint always within closed enum (or null)
// ============================================================================
$messages = ['我想3月去東京自由行', '我想去東京', '請問客服電話', '我想出去玩'];
$allValid = true;
foreach ($messages as $m) {
    $hint = runtime_with(ConversationRuntimeFacade::createForTesting())
        ->understand($m, ['conversation_id' => $cid])
        ->getExecutionHint();
    if (!ExecutionHint::isValid($hint)) {
        $allValid = false;
    }
}
test_assert($allValid, 'stability: execution_hint always valid enum/null');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_ai_intent_understanding_runtime_logic\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_ai_intent_understanding_runtime_logic\n");
exit(1);
