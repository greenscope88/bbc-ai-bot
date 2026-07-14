<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-1 — GroundingRuntime assemble regression tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingAssemblyContext.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ComposerRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';

$failures = 0;

function gra_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param array<string, mixed> $entity
 * @param array<string, mixed> $overrides
 */
function gra_product_context(array $entity, array $overrides = []): GroundingAssemblyContext
{
    $base = [
        'trace_id' => 'trace-gra',
        'conversation_id' => 'conv-gra',
        'tenant_sno' => '1001',
        'customer_query' => '北海道 8月 五天',
        'runtime_type' => RuntimeType::PRODUCT_SEARCH,
        'source_type' => GroundedInput::SOURCE_PRODUCT_SEARCH,
        'runtime_result' => [
            'reply_text' => '推薦行程',
            'grounded' => false,
            'recommendation_summary' => ['result_count' => 0],
            'product_list' => [],
        ],
        'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
        'tenant' => [
            'tenant_key' => 'travel_b',
            'tenant_sno' => '1001',
            'company_name' => 'Travel B',
        ],
        'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
        'aiu_projection' => ['entities' => $entity],
    ];

    return GroundingAssemblyContext::fromArray(array_merge($base, $overrides));
}

$runtime = new GroundingRuntime();

// 1. 北海道 8月 五天
$case1 = $runtime->assemble(gra_product_context([
    'destination' => '北海道',
    'travel_dates' => '8月',
    'duration' => '五天',
], ['customer_query' => '北海道 8月 五天']));
$ctx1 = $case1->getConversationContext();
$meta1 = $case1->getMetadata();
gra_assert(($meta1['customer_query'] ?? '') === '北海道 8月 五天', 'case1 customer_query');
gra_assert(($ctx1['destination'] ?? '') === '北海道', 'case1 destination');
gra_assert(($ctx1['travel_dates'] ?? '') === '8月', 'case1 travel_dates');
gra_assert(($ctx1['duration'] ?? '') === '五天', 'case1 duration');
gra_assert(($ctx1['current_requirement'] ?? '') === '北海道 8月 五天', 'case1 current_requirement');
gra_assert($case1->getReplyPolicy()['grounded_only'] === true, 'case1 grounded_only');

// 2. 北海道 8月
$case2 = $runtime->assemble(gra_product_context(
    ['destination' => '北海道', 'travel_dates' => '8月'],
    ['customer_query' => '北海道 8月']
));
$ctx2 = $case2->getConversationContext();
gra_assert(($ctx2['destination'] ?? '') === '北海道', 'case2 destination');
gra_assert(($ctx2['travel_dates'] ?? '') === '8月', 'case2 travel_dates');
gra_assert(!isset($ctx2['duration']), 'case2 no duration');

// 3. 北海道 五天
$case3 = $runtime->assemble(gra_product_context(
    ['destination' => '北海道', 'duration' => '五天'],
    ['customer_query' => '北海道 五天']
));
$ctx3 = $case3->getConversationContext();
gra_assert(($ctx3['destination'] ?? '') === '北海道', 'case3 destination');
gra_assert(($ctx3['duration'] ?? '') === '五天', 'case3 duration');
gra_assert(!isset($ctx3['travel_dates']), 'case3 no travel_dates');

// 4. 8月 五天
$case4 = $runtime->assemble(gra_product_context(
    ['travel_dates' => '8月', 'duration' => '五天'],
    ['customer_query' => '8月 五天']
));
$ctx4 = $case4->getConversationContext();
gra_assert(($ctx4['travel_dates'] ?? '') === '8月', 'case4 travel_dates');
gra_assert(($ctx4['duration'] ?? '') === '五天', 'case4 duration');
gra_assert(!isset($ctx4['destination']), 'case4 no destination');

// 5. Knowledge facts
$case5 = $runtime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 't5',
    'conversation_id' => 'c5',
    'tenant_sno' => '1001',
    'customer_query' => '公司電話',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'source_type' => GroundedInput::SOURCE_TENANT_PRIVATE,
    'runtime_result' => [
        'reply_text' => '02-1234-5678',
        'grounded' => true,
        'query_type' => 'company_profile',
        'qa_id' => 'qa-phone',
    ],
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
]));
gra_assert($case5->getFactCount() > 0, 'case5 has grounded_facts');
gra_assert($case5->getProductList() === [], 'case5 empty product_list');

// 6. Product results
$case6 = $runtime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 't6',
    'conversation_id' => 'c6',
    'tenant_sno' => '1001',
    'customer_query' => '北海道團',
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'source_type' => GroundedInput::SOURCE_PRODUCT_SEARCH,
    'runtime_result' => [
        'reply_text' => '推薦',
        'grounded' => true,
        'recommendation_summary' => ['result_count' => 1],
        'product_list' => [
            ['title' => '北海道五日', 'primary_url' => 'https://example.com/tour'],
        ],
    ],
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
]));
gra_assert(count($case6->getProductList()) === 1, 'case6 product_list');
gra_assert($case6->getFactCount() > 0, 'case6 grounded_facts from product title');

// 7. Human takeover active — Composer must suppress outbound reply
$case7 = $runtime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 't7',
    'conversation_id' => 'c7',
    'tenant_sno' => '1001',
    'customer_query' => '你好',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'source_type' => GroundedInput::SOURCE_TENANT_PRIVATE,
    'runtime_result' => [
        'reply_text' => '您好',
        'grounded' => true,
        'query_type' => 'greeting',
    ],
    'dispatch_result' => [],
    'state_snapshot' => ['conversation_owner' => 'HUMAN', 'conversation_status' => 'ACTIVE'],
]));
gra_assert(
    $case7->getConversationOwner() === GroundedInput::CONVERSATION_OWNER_HUMAN,
    'case7 owner HUMAN'
);
$composer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => true]);
$composerRuntime = new ComposerRuntime();
$out7 = $composerRuntime->run($case7, static function (GroundedInput $input) use ($composer): GroundedOutput {
    return $composer->compose($input);
});
gra_assert($out7->getReplyType() === 'suppressed_human_takeover', 'case7 composer suppressed');

// 8. resume_context present
$case8 = $runtime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 't8',
    'conversation_id' => 'c8',
    'tenant_sno' => '1001',
    'customer_query' => '繼續',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'source_type' => GroundedInput::SOURCE_TENANT_PRIVATE,
    'runtime_result' => ['reply_text' => '好的', 'grounded' => true],
    'dispatch_result' => [],
    'state_snapshot' => [
        'conversation_owner' => 'AI',
        'resume_context' => ['last_topic' => '北海道'],
    ],
]));
$resume8 = $case8->getResumeContext();
gra_assert(is_array($resume8) && ($resume8['last_topic'] ?? '') === '北海道', 'case8 resume_context');

// 9. tenant_context missing fallback
$case9 = $runtime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 't9',
    'conversation_id' => 'c9',
    'tenant_sno' => '1001',
    'customer_query' => '查詢',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'source_type' => GroundedInput::SOURCE_TENANT_PRIVATE,
    'runtime_result' => ['reply_text' => '回覆', 'grounded' => true],
    'dispatch_result' => [],
    'tenant' => [],
]));
$tenant9 = $case9->getTenant();
gra_assert(($tenant9['tenant_sno'] ?? '') === '1001', 'case9 tenant_sno fallback');

// 10. reply_policy missing fallback
$case10 = $runtime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 't10',
    'conversation_id' => 'c10',
    'tenant_sno' => '1001',
    'customer_query' => '查詢',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'source_type' => GroundedInput::SOURCE_TENANT_PRIVATE,
    'runtime_result' => ['reply_text' => '回覆', 'grounded' => true],
    'dispatch_result' => [],
]));
$policy10 = $case10->getReplyPolicy();
gra_assert(isset($policy10['mode']) && $policy10['mode'] !== '', 'case10 reply_policy mode');
gra_assert($policy10['grounded_only'] === true, 'case10 grounded_only fallback');

// Grounding must not produce NLG reply_text on the DTO contract serialization
$serialized1 = $case1->toArray();
gra_assert(!isset($serialized1['reply_text']), 'assembled input has no reply_text field');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_grounding_runtime_assemble.php (10 cases)\n");
exit(0);
