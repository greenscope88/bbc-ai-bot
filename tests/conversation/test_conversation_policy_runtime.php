<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationPolicyRuntime.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// --- ConversationLifecycle ---------------------------------------------------
test_assert(count(ConversationLifecycle::all()) === 6, 'lifecycle has 6 stages');
test_assert(ConversationLifecycle::isValid('Resolved'), 'Resolved is valid stage');
test_assert(!ConversationLifecycle::isValid('Bogus'), 'Bogus not valid stage');
test_assert(ConversationLifecycle::isTerminal('Completed') && ConversationLifecycle::isTerminal('Closed'), 'terminal stages');
test_assert(!ConversationLifecycle::isTerminal('Active'), 'active not terminal');
test_assert(ConversationLifecycle::isForward('New', 'Active'), 'New -> Active forward');
test_assert(!ConversationLifecycle::isForward('Closing', 'Resolved'), 'Closing -> Resolved not forward');
test_assert(ConversationLifecycle::toCanonicalStatus('New') === ConversationStatus::ACTIVE, 'New maps ACTIVE');
test_assert(ConversationLifecycle::toCanonicalStatus('Closing') === ConversationStatus::ACTIVE, 'Closing maps ACTIVE');
test_assert(ConversationLifecycle::toCanonicalStatus('Completed') === ConversationStatus::COMPLETED, 'Completed maps COMPLETED');
test_assert(ConversationLifecycle::toCanonicalStatus('Closed') === ConversationStatus::CLOSED, 'Closed maps CLOSED');

// --- ConversationPolicy (tenant policy) --------------------------------------
$policy = ConversationPolicy::create();
test_assert($policy->isRecommendationEnabled(ConversationPolicy::RECOMMENDATION_PRODUCT), 'product enabled by default');
test_assert(!$policy->isRecommendationEnabled(ConversationPolicy::RECOMMENDATION_CAMPAIGN), 'campaign disabled by default');
test_assert(!$policy->isRecommendationEnabled(ConversationPolicy::RECOMMENDATION_COUPON), 'coupon disabled by default');
test_assert(!$policy->isRecommendationEnabled(ConversationPolicy::RECOMMENDATION_REVIEW_INVITE), 'review disabled by default');
$policy->enableRecommendation(ConversationPolicy::RECOMMENDATION_REVIEW_INVITE);
test_assert($policy->isRecommendationEnabled(ConversationPolicy::RECOMMENDATION_REVIEW_INVITE), 'review enabled after toggle');
$policyRoundTrip = ConversationPolicy::fromArray($policy->toArray());
test_assert($policyRoundTrip->toArray() === $policy->toArray(), 'policy round-trip');
$threw = false;
try {
    $policy->isRecommendationEnabled('bogus');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
test_assert($threw, 'invalid recommendation type throws');

// --- Completion Detection ----------------------------------------------------
$runtime = new ConversationPolicyRuntime();

test_assert(
    $runtime->detectLifecycleStage('New', []) === ConversationLifecycle::STAGE_ACTIVE,
    'no signals New -> Active'
);
test_assert(
    $runtime->detectLifecycleStage('Active', ['requirement_resolved' => true]) === ConversationLifecycle::STAGE_RESOLVED,
    'requirement resolved -> Resolved'
);
test_assert(
    $runtime->detectLifecycleStage('Resolved', ['requirement_resolved' => true, 'closing_initiated' => true]) === ConversationLifecycle::STAGE_CLOSING,
    'closing initiated -> Closing'
);
test_assert(
    $runtime->detectLifecycleStage('Closing', ['service_acknowledged' => true]) === ConversationLifecycle::STAGE_COMPLETED,
    'service acknowledged -> Completed'
);
test_assert(
    $runtime->detectLifecycleStage('Active', ['customer_ended_conversation' => true]) === ConversationLifecycle::STAGE_CLOSED,
    'customer ended -> Closed'
);
test_assert(
    $runtime->detectLifecycleStage('Active', ['timed_out_no_interaction' => true]) === ConversationLifecycle::STAGE_CLOSED,
    'timeout no interaction -> Closed'
);
// terminal protection
test_assert(
    $runtime->detectLifecycleStage('Completed', ['requirement_resolved' => true]) === ConversationLifecycle::STAGE_COMPLETED,
    'terminal Completed stays Completed'
);
test_assert(
    $runtime->detectLifecycleStage('Closed', []) === ConversationLifecycle::STAGE_CLOSED,
    'terminal Closed stays Closed'
);
// no-regress protection
test_assert(
    $runtime->detectLifecycleStage('Closing', []) === ConversationLifecycle::STAGE_CLOSING,
    'no signals does not regress Closing to Active'
);

// isRequirementCompleted
test_assert(!$runtime->isRequirementCompleted('Active'), 'Active not completed');
test_assert($runtime->isRequirementCompleted('Resolved'), 'Resolved completed');
test_assert($runtime->isRequirementCompleted('Completed'), 'Completed completed');

// --- Recommendation Eligibility (CA-012) -------------------------------------
$tenant = ConversationPolicy::create(); // product on, others off

// requirement not completed -> denied
$d = $runtime->evaluateRecommendation('product_search', false, $tenant, ConversationPolicy::RECOMMENDATION_PRODUCT);
test_assert($d['eligible'] === false && $d['reason'] === 'requirement_not_completed', 'denied when not completed');

// product_search + completed + product enabled -> eligible
$d = $runtime->evaluateRecommendation('product_search', true, $tenant, ConversationPolicy::RECOMMENDATION_PRODUCT);
test_assert($d['eligible'] === true, 'product eligible when all conditions met');

// product_search + completed but campaign disabled -> tenant_policy_disabled
$d = $runtime->evaluateRecommendation('product_search', true, $tenant, ConversationPolicy::RECOMMENDATION_CAMPAIGN);
test_assert($d['eligible'] === false && $d['reason'] === 'tenant_policy_disabled', 'campaign denied by tenant policy');

// knowledge_query + product -> intent_incompatible
$d = $runtime->evaluateRecommendation('knowledge_query', true, $tenant, ConversationPolicy::RECOMMENDATION_PRODUCT);
test_assert($d['eligible'] === false && $d['reason'] === 'intent_incompatible', 'knowledge incompatible with product');

// ambiguous -> never eligible
$d = $runtime->evaluateRecommendation('ambiguous', true, $tenant, ConversationPolicy::RECOMMENDATION_PRODUCT);
test_assert($d['eligible'] === false && $d['reason'] === 'intent_incompatible', 'ambiguous not eligible');

// knowledge_query + review_invite (enabled) -> eligible
$tenant2 = ConversationPolicy::create()->enableRecommendation(ConversationPolicy::RECOMMENDATION_REVIEW_INVITE);
$d = $runtime->evaluateRecommendation('knowledge_query', true, $tenant2, ConversationPolicy::RECOMMENDATION_REVIEW_INVITE);
test_assert($d['eligible'] === true, 'knowledge + review_invite eligible when enabled');

// evaluateRecommendationForStage uses lifecycle completion
$all = $runtime->evaluateRecommendationForStage('product_search', 'Active', $tenant);
test_assert($all[ConversationPolicy::RECOMMENDATION_PRODUCT]['eligible'] === false, 'stage Active -> product not eligible');
$all = $runtime->evaluateRecommendationForStage('product_search', 'Completed', $tenant);
test_assert($all[ConversationPolicy::RECOMMENDATION_PRODUCT]['eligible'] === true, 'stage Completed -> product eligible');

// hasAnyEligible via evaluator
$evaluator = new RecommendationEligibilityEvaluator();
test_assert($evaluator->hasAnyEligible('product_search', true, $tenant) === true, 'product_search has eligible');
test_assert($evaluator->hasAnyEligible('ambiguous', true, $tenant) === false, 'ambiguous has none eligible');

// --- Human Handoff Policy ----------------------------------------------------
$hand = $runtime->evaluateHumanHandoffPolicy(true);
test_assert($hand['owner_should_be'] === ConversationOwner::HUMAN, 'handoff -> owner HUMAN');
test_assert($hand['ai_may_send_official_reply'] === false, 'handoff -> AI may not reply');
$noHand = $runtime->evaluateHumanHandoffPolicy(false);
test_assert($noHand['owner_should_be'] === ConversationOwner::AI, 'no handoff -> owner AI');
test_assert($noHand['ai_may_send_official_reply'] === true, 'no handoff -> AI may reply');

// --- AI Resume Policy (three conditions) -------------------------------------
$r = $runtime->evaluateAiResumePolicy(ConversationOwner::AI, true, true);
test_assert($r['resume_permitted'] === false && $r['reason'] === 'owner_not_human', 'resume denied owner AI');
$r = $runtime->evaluateAiResumePolicy(ConversationOwner::HUMAN, false, true);
test_assert($r['resume_permitted'] === false && $r['reason'] === 'no_customer_new_message', 'resume denied no customer message');
$r = $runtime->evaluateAiResumePolicy(ConversationOwner::HUMAN, true, false);
test_assert($r['resume_permitted'] === false && $r['reason'] === 'within_human_hold_window', 'resume denied within hold');
$r = $runtime->evaluateAiResumePolicy(ConversationOwner::HUMAN, true, true);
test_assert($r['resume_permitted'] === true && $r['reason'] === 'all_conditions_met', 'resume permitted all conditions');

$threw = false;
try {
    $runtime->evaluateAiResumePolicy('ROBOT', true, true);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
test_assert($threw, 'invalid owner throws');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_conversation_policy_runtime (all passed)\n");
exit(0);
