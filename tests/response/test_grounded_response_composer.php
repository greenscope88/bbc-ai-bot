<?php
declare(strict_types=1);

/**
 * Phase 9-C-2C-1 / 9-C-2C-2 — GroundedResponseComposer contract + regression tests.
 *
 * Knowledge Path (9-C-2C-1) thin wrapper:
 *  - reply text passes through unchanged
 *  - grounded contract invariants (used_facts_count / human_service_required)
 *  - no unsupported facts are introduced
 *
 * Product Path (9-C-2C-2) presentation layer:
 *  - reply_text passthrough (general / couponName-only / no-results / external link)
 *  - reply_type + layout_profile derived without regenerating recommendation copy
 *  - composer never fabricates products beyond the Runtime result
 *  - Knowledge Path reply_type/layout_profile defaults unchanged (no regression)
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

// 6. Contract: toArray exposes stable keys (knowledge backward compatibility +
//    Phase 9-C-2C-2 presentation fields)
$arr = $out->toArray();
foreach (
    [
        'text',
        'reply_text',
        'reply_type',
        'layout_profile',
        'grounded',
        'used_facts_count',
        'source_type',
        'human_service_required',
        'safety_notes',
        'reply_suppressed',
        'validation_passed',
        'voice_profile_used',
    ] as $key
) {
    test_assert(array_key_exists($key, $arr), "contract: toArray has key {$key}");
}
test_assert($arr['reply_suppressed'] === false, 'contract: reply_suppressed default false');
test_assert($arr['validation_passed'] === true, 'contract: validation_passed pass-through true');

// 6b. Knowledge path layout/reply_type defaults (no regression)
$knOut = $composer->composeFromKnowledgeResult($serviceQa);
test_assert(
    $knOut->getLayoutProfile() === GroundedOutput::LAYOUT_KNOWLEDGE_STANDARD,
    'knowledge: layout_profile knowledge_standard_v1'
);
test_assert(
    $knOut->getReplyType() === GroundedOutput::REPLY_TYPE_NORMAL,
    'knowledge: reply_type normal_reply'
);
$humanOut = $composer->composeFromKnowledgeResult($humanService);
test_assert(
    $humanOut->getReplyType() === GroundedOutput::REPLY_TYPE_HUMAN_FALLBACK,
    'human_service: reply_type human_agent_fallback'
);
test_assert(
    $humanOut->getLayoutProfile() === GroundedOutput::LAYOUT_MINIMAL,
    'human_service: layout_profile minimal_v1'
);

// ===== Phase 9-C-2C-2 Product Path =====

// P1. General product recommendation (e.g. 近期東京)
$productReply = '為您整理近期東京熱門行程 ✈️：\n1. 東京自由行 5 日';
$tokyoResult = [
    'reply_text' => $productReply,
    'grounded' => true,
    'recommendation_summary' => [
        'result_count' => 3,
        'top_products' => [
            ['title' => '東京自由行 5 日', 'primary_url' => 'https://example.com/a'],
            ['title' => '東京親子 6 日', 'primary_url' => 'https://example.com/b'],
        ],
        'primary_url' => 'https://example.com/a',
    ],
    'product_list' => [
        ['title' => '東京自由行 5 日', 'primary_url' => 'https://example.com/a'],
        ['title' => '東京親子 6 日', 'primary_url' => 'https://example.com/b'],
    ],
];
$out = $composer->composeProductReply($tokyoResult, ['tenant_key' => 'travel_b']);
test_assert($out->getReplyText() === $productReply, 'product general: reply_text passthrough unchanged');
test_assert($out->getText() === $productReply, 'product general: text alias unchanged');
test_assert($out->getSourceType() === GroundedInput::SOURCE_PRODUCT_SEARCH, 'product general: product_search source');
test_assert($out->getReplyType() === GroundedOutput::REPLY_TYPE_NORMAL, 'product general: reply_type normal_reply');
test_assert($out->getLayoutProfile() === GroundedOutput::LAYOUT_PRODUCT_RICH, 'product general: layout_profile product_rich_v1');
test_assert($out->isGrounded() === true, 'product general: grounded true');
test_assert($out->getUsedFactsCount() === 2, 'product general: used_facts_count from product_list');
test_assert($out->isHumanServiceRequired() === false, 'product general: no human service');

// P2. couponName-only Host B data (title resolved upstream by the Runtime fallback)
$couponResult = [
    'reply_text' => '推薦您這個優惠方案 🌸：北海道破盤特惠',
    'grounded' => true,
    'recommendation_summary' => [
        'result_count' => 1,
        'top_products' => [
            ['title' => '北海道破盤特惠', 'primary_url' => 'https://example.com/coupon'],
        ],
    ],
    'product_list' => [
        ['title' => '北海道破盤特惠', 'primary_url' => 'https://example.com/coupon'],
    ],
];
$out = $composer->composeProductReply($couponResult);
test_assert($out->getReplyText() === '推薦您這個優惠方案 🌸：北海道破盤特惠', 'product coupon: reply_text passthrough');
test_assert($out->getReplyType() === GroundedOutput::REPLY_TYPE_NORMAL, 'product coupon: reply_type normal_reply');
test_assert($out->getUsedFactsCount() === 1, 'product coupon: one product fact');

// P3. No results
$noResults = [
    'reply_text' => '目前尚未找到符合條件的行程，您要不要調整日期或目的地呢？',
    'grounded' => false,
    'recommendation_summary' => [
        'result_count' => 0,
        'top_products' => [],
    ],
    'product_list' => [],
];
$out = $composer->composeProductReply($noResults);
test_assert($out->getReplyText() === '目前尚未找到符合條件的行程，您要不要調整日期或目的地呢？', 'product no_results: reply_text passthrough');
test_assert($out->getReplyType() === GroundedOutput::REPLY_TYPE_NO_RESULTS, 'product no_results: reply_type no_results');
test_assert($out->getLayoutProfile() === GroundedOutput::LAYOUT_PRODUCT_RICH, 'product no_results: layout_profile product_rich_v1');
test_assert($out->getUsedFactsCount() === 0, 'product no_results: zero facts');
test_assert($out->isGrounded() === false, 'product no_results: not grounded');

// P4. External links preserved verbatim in passthrough text
$externalLink = 'https://travel-b.example.com/tour/tokyo-001';
$externalResult = [
    'reply_text' => "為您找到行程，詳情請見：{$externalLink}",
    'grounded' => true,
    'recommendation_summary' => [
        'result_count' => 1,
        'top_products' => [
            ['title' => '東京賞櫻 5 日', 'primary_url' => $externalLink],
        ],
    ],
    'product_list' => [
        ['title' => '東京賞櫻 5 日', 'primary_url' => $externalLink],
    ],
];
$out = $composer->composeProductReply($externalResult);
test_assert(strpos($out->getReplyText(), $externalLink) !== false, 'product external_link: URL preserved in reply_text');
test_assert($out->getReplyType() === GroundedOutput::REPLY_TYPE_NORMAL, 'product external_link: reply_type normal_reply');

// P5. Composer never fabricates products beyond what the Runtime provided
test_assert(
    $out->getReplyText() === "為您找到行程，詳情請見：{$externalLink}",
    'product external_link: composer does not append extra products'
);

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_grounded_response_composer (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
