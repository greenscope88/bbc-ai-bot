<?php
declare(strict_types=1);

/**
 * Phase 9-C-2C-1 — GroundedResponseComposer contract + regression tests.
 *
 * Verifies the Knowledge Path thin wrapper:
 *  - reply text passes through unchanged
 *  - grounded contract invariants (used_facts_count / human_service_required)
 *  - no unsupported facts are introduced
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$composer = new GroundedResponseComposer();

// 1. Tenant private grounded (company_profile: grounded, no explicit id)
$companyProfile = [
    'reply_text' => '旅行蜜優惠客服電話為：07-5224856',
    'grounded' => true,
    'query_type' => 'company_profile',
    'final_route' => 'phase_9c2b2a_knowledge_runtime',
];
$out = $composer->composeFromKnowledgeResult($companyProfile, ['tenant_key' => 'travel_b']);
test_assert($out->getText() === '旅行蜜優惠客服電話為：07-5224856', 'company_profile: text passthrough unchanged');
test_assert($out->isGrounded() === true, 'company_profile: grounded true');
test_assert($out->getUsedFactsCount() > 0, 'company_profile: used_facts_count > 0 when grounded');
test_assert($out->getSourceType() === GroundedInput::SOURCE_TENANT_PRIVATE, 'company_profile: tenant_private source');
test_assert($out->isHumanServiceRequired() === false, 'company_profile: no human service');
test_assert($out->getSafetyNotes() === [], 'company_profile: no safety notes');

// 2. Tenant private grounded with explicit id (service_qa)
$serviceQa = [
    'reply_text' => '【搭機須知】飛機起飛前二個小時前抵達機場',
    'grounded' => true,
    'query_type' => 'service_qa',
    'final_route' => 'phase_9c2b3_qa_knowledge_runtime',
    'qa_id' => 'qa-checkin-001',
];
$out = $composer->composeFromKnowledgeResult($serviceQa);
test_assert($out->isGrounded() === true, 'service_qa: grounded true');
test_assert($out->getUsedFactsCount() === 1, 'service_qa: one fact counted from qa_id');
test_assert($out->getSourceType() === GroundedInput::SOURCE_TENANT_PRIVATE, 'service_qa: tenant_private source');

// 3. Industry shared grounded
$industryShared = [
    'reply_text' => '國際線一般建議起飛前 3 小時抵達機場辦理報到。',
    'grounded' => true,
    'final_route' => 'phase_9c2d_industry_shared_runtime',
    'fallback_layer' => 'industry_shared',
    'shared_item_id' => 'faq-checkin-intl',
];
$out = $composer->composeFromKnowledgeResult($industryShared);
test_assert($out->isGrounded() === true, 'industry_shared: grounded true');
test_assert($out->getSourceType() === GroundedInput::SOURCE_INDUSTRY_SHARED, 'industry_shared: source type');
test_assert($out->getUsedFactsCount() === 1, 'industry_shared: fact counted from shared_item_id');
test_assert($out->isHumanServiceRequired() === false, 'industry_shared: no human service');

// 4. Human service fallback
$humanService = [
    'reply_text' => '這部分需由專人客服為您確認，我已為您轉接，請稍候。',
    'grounded' => false,
    'final_route' => 'phase_9c2b3_knowledge_human_service',
    'fallback_layer' => 'human_service',
];
$out = $composer->composeFromKnowledgeResult($humanService);
test_assert($out->getText() === '這部分需由專人客服為您確認，我已為您轉接，請稍候。', 'human_service: text passthrough unchanged');
test_assert($out->isHumanServiceRequired() === true, 'human_service: human_service_required true');
test_assert($out->isGrounded() === false, 'human_service: not grounded');
test_assert($out->getUsedFactsCount() === 0, 'human_service: zero facts');

// 5. Safety: ungrounded result must not claim facts (no hallucination)
$ungroundedWithStrayId = [
    'reply_text' => '目前無法確認，建議改由專人協助。',
    'grounded' => false,
    'query_type' => 'service_qa',
    'final_route' => 'phase_9c2b3_qa_knowledge_runtime',
    'qa_id' => 'should-not-count',
];
$out = $composer->composeFromKnowledgeResult($ungroundedWithStrayId);
test_assert($out->isGrounded() === false, 'ungrounded: grounded false');
test_assert($out->getUsedFactsCount() === 0, 'ungrounded: fact count reset to 0');
test_assert(in_array('ungrounded_fact_count_reset', $out->getSafetyNotes(), true), 'ungrounded: safety note recorded');

// 6. Contract: toArray exposes stable keys
$arr = $out->toArray();
foreach (['text', 'grounded', 'used_facts_count', 'source_type', 'human_service_required', 'safety_notes'] as $key) {
    test_assert(array_key_exists($key, $arr), "contract: toArray has key {$key}");
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_grounded_response_composer (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
