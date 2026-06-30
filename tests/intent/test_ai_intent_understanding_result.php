<?php

$intentDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function expect_throws(callable $fn, string $message): void
{
    $threw = false;
    try {
        $fn();
    } catch (\Throwable $e) {
        $threw = true;
    }
    test_assert($threw, $message);
}

// ============================================================================
// 1. Value Object closed enums (ABI / Contract Stability)
// ============================================================================
test_assert(AiIntentCategory::all() === ['Product Search', 'Knowledge', 'Ambiguous'], 'AiIntentCategory: frozen set');
test_assert(AiIntentCategory::isValid('Product Search') && !AiIntentCategory::isValid('Random'), 'AiIntentCategory: validation');

test_assert(DispatchPlan::all() === ['product', 'knowledge', 'clarification', 'human'], 'DispatchPlan: frozen set');
test_assert(DispatchPlan::isValid('human') && !DispatchPlan::isValid('reply'), 'DispatchPlan: validation');

test_assert(
    ExecutionHint::all() === [
        'product_search',
        'product_clarification',
        'product_no_search',
        'knowledge_resolve',
        'human_blocked',
    ],
    'ExecutionHint: frozen set'
);
test_assert(ExecutionHint::isValid(null), 'ExecutionHint: null allowed');
test_assert(ExecutionHint::isValid('human_blocked') && !ExecutionHint::isValid('blocked'), 'ExecutionHint: validation');

// ============================================================================
// 2. DTO construction + defaults (SSOT §5 defaults)
// ============================================================================
$r = new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::PRODUCT);
test_assert($r->getIntent() === 'Product Search', 'default: intent set');
test_assert($r->getDispatchPlan() === 'product', 'default: dispatch_plan set');
test_assert($r->getEntity() === [], 'default: entity empty array');
test_assert($r->getContextSnapshot() === [], 'default: context_snapshot empty array');
test_assert($r->getOwnerSnapshot() === 'AI', 'default: owner_snapshot AI');
test_assert($r->getConversationStage() === '', 'default: conversation_stage empty');
test_assert($r->getResumeContext() === null, 'default: resume_context null');
test_assert($r->isClarificationRequired() === false, 'default: clarification.required false');
test_assert($r->getClarificationReason() === '', 'default: clarification.reason empty');
test_assert($r->getExecutionHint() === null, 'default: execution_hint null');

// ============================================================================
// 3. Setters + getters (fluent)
// ============================================================================
$r->setEntity(['destination' => 'Tokyo'])
    ->setContextSnapshot(['current_requirement' => 'tour'])
    ->setOwnerSnapshot('HUMAN')
    ->setConversationStage('Active')
    ->setResumeContext(['last_human' => '2026-06-30T10:00:00+08:00'])
    ->setClarification(true, 'date_missing')
    ->setExecutionHint(ExecutionHint::HUMAN_BLOCKED);

test_assert($r->getEntity() === ['destination' => 'Tokyo'], 'setter: entity');
test_assert($r->getContextSnapshot() === ['current_requirement' => 'tour'], 'setter: context_snapshot');
test_assert($r->getOwnerSnapshot() === 'HUMAN', 'setter: owner_snapshot');
test_assert($r->getConversationStage() === 'Active', 'setter: conversation_stage');
test_assert($r->getResumeContext() === ['last_human' => '2026-06-30T10:00:00+08:00'], 'setter: resume_context');
test_assert($r->isClarificationRequired() === true && $r->getClarificationReason() === 'date_missing', 'setter: clarification');
test_assert($r->getExecutionHint() === 'human_blocked', 'setter: execution_hint');

// ============================================================================
// 4. Validation guards (ABI integrity)
// ============================================================================
expect_throws(static function () {
    new AiIntentUnderstandingResult('NotAnIntent', DispatchPlan::PRODUCT);
}, 'guard: invalid intent throws');
expect_throws(static function () {
    new AiIntentUnderstandingResult(AiIntentCategory::KNOWLEDGE, 'reply');
}, 'guard: invalid dispatch_plan throws');
expect_throws(static function () use ($r) {
    $r->setOwnerSnapshot('ROBOT');
}, 'guard: invalid owner_snapshot throws');
expect_throws(static function () use ($r) {
    $r->setExecutionHint('not_a_hint');
}, 'guard: invalid execution_hint throws');

// empty execution_hint string normalizes to null
$r2 = new AiIntentUnderstandingResult(AiIntentCategory::KNOWLEDGE, DispatchPlan::KNOWLEDGE);
$r2->setExecutionHint('   ');
test_assert($r2->getExecutionHint() === null, 'execution_hint: blank normalizes to null');

// ============================================================================
// 5. toArray = exactly 9 frozen keys, correct shape (Contract Stability)
// ============================================================================
$arr = $r->toArray();
$expectedKeys = [
    'intent',
    'entity',
    'context_snapshot',
    'owner_snapshot',
    'conversation_stage',
    'resume_context',
    'clarification',
    'dispatch_plan',
    'execution_hint',
];
test_assert(array_keys($arr) === $expectedKeys, 'contract: toArray has 9 frozen keys in SSOT order');
test_assert(count($arr) === 9, 'contract: exactly 9 fields');
test_assert(
    is_array($arr['clarification'])
        && array_keys($arr['clarification']) === ['required', 'reason'],
    'contract: clarification is { required, reason }'
);

// ============================================================================
// 6. fromArray(toArray) round-trip equals (Contract Stability)
// ============================================================================
$round = AiIntentUnderstandingResult::fromArray($arr);
test_assert($round->toArray() === $arr, 'round-trip: fromArray(toArray) equals');

// fromArray tolerates missing optional fields, applies defaults
$min = AiIntentUnderstandingResult::fromArray([
    'intent' => 'Ambiguous',
    'dispatch_plan' => 'clarification',
]);
test_assert($min->getIntent() === 'Ambiguous' && $min->getDispatchPlan() === 'clarification', 'fromArray: minimal');
test_assert($min->getOwnerSnapshot() === 'AI' && $min->getExecutionHint() === null, 'fromArray: defaults applied');

// fromArray with null resume_context / execution_hint preserved
$withNulls = AiIntentUnderstandingResult::fromArray([
    'intent' => 'Knowledge',
    'dispatch_plan' => 'knowledge',
    'resume_context' => null,
    'execution_hint' => null,
    'clarification' => ['required' => false, 'reason' => ''],
]);
test_assert($withNulls->getResumeContext() === null, 'fromArray: null resume_context');
test_assert($withNulls->getExecutionHint() === null, 'fromArray: null execution_hint');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_ai_intent_understanding_result\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_ai_intent_understanding_result\n");
exit(1);
