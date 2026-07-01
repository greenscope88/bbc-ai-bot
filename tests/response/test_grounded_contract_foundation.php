<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-1 — GroundedInput / GroundedOutput contract foundation tests.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §8.5 / §9.5.
 *
 * `validation_passed = true` in this phase means legacy pass-through contract-compatible
 * only — Grounded Output Validator is not yet implemented (Phase 2-E-2+).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedOutput.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';

$failures = 0;

function contract_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// --- Closed Enum Value Objects ---

foreach (RuntimeType::all() as $type) {
    contract_assert(RuntimeType::isValid($type), "RuntimeType valid: {$type}");
}
contract_assert(!RuntimeType::isValid('invalid_runtime'), 'RuntimeType rejects invalid');

foreach (ReplyType::all() as $type) {
    contract_assert(ReplyType::isValid($type), "ReplyType valid: {$type}");
}
contract_assert(!ReplyType::isValid('bogus_reply'), 'ReplyType rejects invalid');

foreach (LayoutProfile::all() as $profile) {
    contract_assert(LayoutProfile::isValid($profile), "LayoutProfile valid: {$profile}");
}
contract_assert(!LayoutProfile::isValid('fancy_v99'), 'LayoutProfile rejects invalid');

// --- GroundedInput round-trip (MVP fields) ---

$inputPayload = [
    'schema_version' => GroundedInput::SCHEMA_VERSION,
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'source_type' => GroundedInput::SOURCE_TENANT_PRIVATE,
    'tenant' => [
        'tenant_key' => 'travel_b',
        'tenant_sno' => '1001',
        'company_name' => '旅行蜜',
    ],
    'tone' => [
        'persona' => 'travel_consultant',
        'allow_emoji' => true,
    ],
    'grounded_facts' => [
        [
            'fact_id' => 'qa-001',
            'fact_type' => 'qa',
            'value' => '起飛前二小時抵達機場',
            'source_ref' => 'qa_id',
        ],
    ],
    'product_list' => [
        'items' => [
            ['title' => '東京 5 日', 'primary_url' => 'https://example.com/tokyo'],
        ],
    ],
    'external_links' => [
        ['url' => 'https://example.com/tokyo', 'title' => '東京 5 日'],
    ],
    'reply_policy' => [
        'mode' => 'recommend',
        'grounded_only' => true,
        'max_products' => 3,
    ],
    'metadata' => [
        'customer_query' => '東京行程',
        'trace_id' => 'trace-abc',
        'conversation_id' => 'conv-xyz',
    ],
    'conversation_context' => ['destination' => '東京'],
    'conversation_owner' => GroundedInput::CONVERSATION_OWNER_AI,
    'conversation_status' => GroundedInput::CONVERSATION_STATUS_ACTIVE,
    'raw_runtime_result' => [
        'reply_text' => '起飛前二小時抵達機場',
        'grounded' => true,
        'qa_id' => 'qa-001',
    ],
];

$input = GroundedInput::fromArray($inputPayload);
contract_assert($input->getRuntimeType() === RuntimeType::KNOWLEDGE_PRIVATE, 'Input: runtime_type');
contract_assert($input->getFactCount() === 1, 'Input: fact count');
contract_assert($input->getRuntimeReplyText() === '起飛前二小時抵達機場', 'Input: runtime reply text');

$inputArr = $input->toArray();
foreach (
    [
        'schema_version',
        'runtime_type',
        'tenant',
        'tone',
        'grounded_facts',
        'product_list',
        'external_links',
        'reply_policy',
        'metadata',
        'conversation_context',
        'conversation_owner',
        'conversation_status',
    ] as $mvpKey
) {
    contract_assert(array_key_exists($mvpKey, $inputArr), "Input toArray MVP key: {$mvpKey}");
}
contract_assert(!array_key_exists('raw_runtime_result', $inputArr), 'Input toArray excludes rawRuntimeResult');
contract_assert(!array_key_exists('rawRuntimeResult', $inputArr), 'Input toArray excludes rawRuntimeResult camelCase');

$inputRoundTrip = GroundedInput::fromArray($inputArr);
contract_assert(
    $inputRoundTrip->getRuntimeType() === $input->getRuntimeType(),
    'Input round-trip: runtime_type'
);
contract_assert(
    $inputRoundTrip->getFactCount() === $input->getFactCount(),
    'Input round-trip: fact count'
);
contract_assert(
    $inputRoundTrip->getConversationContext() === $input->getConversationContext(),
    'Input round-trip: conversation_context'
);

// Optional fields omission
$minimalInput = GroundedInput::fromArray([
    'runtime_type' => RuntimeType::CLARIFICATION,
    'tenant' => ['tenant_key' => 't', 'tenant_sno' => '1', 'company_name' => 'Co'],
    'tone' => ['persona' => 'p', 'allow_emoji' => false],
    'grounded_facts' => [],
    'product_list' => ['items' => []],
    'external_links' => [],
    'reply_policy' => ['mode' => 'clarification', 'grounded_only' => true],
    'metadata' => ['customer_query' => '', 'trace_id' => '', 'conversation_id' => ''],
    'conversation_context' => [],
    'conversation_owner' => GroundedInput::CONVERSATION_OWNER_AI,
    'conversation_status' => GroundedInput::CONVERSATION_STATUS_ACTIVE,
]);
contract_assert($minimalInput->getResumeContext() === null, 'Input: resume_context optional null');

// Legacy factory mapping sourceType → runtime_type
$legacyKnowledge = GroundedInput::fromKnowledgeRuntimeResult([
    'reply_text' => '客服電話 07-5224856',
    'grounded' => true,
    'query_type' => 'company_profile',
]);
contract_assert(
    $legacyKnowledge->getRuntimeType() === RuntimeType::KNOWLEDGE_PRIVATE,
    'Legacy knowledge factory: knowledge_private runtime_type'
);
contract_assert(
    $legacyKnowledge->getSourceType() === GroundedInput::SOURCE_TENANT_PRIVATE,
    'Legacy knowledge factory: tenant_private source preserved'
);

$legacyShared = GroundedInput::fromKnowledgeRuntimeResult([
    'reply_text' => '國際線建議提前 3 小時',
    'grounded' => true,
    'fallback_layer' => 'industry_shared',
    'shared_item_id' => 'faq-1',
]);
contract_assert(
    $legacyShared->getRuntimeType() === RuntimeType::KNOWLEDGE_SHARED,
    'Legacy shared factory: knowledge_shared runtime_type'
);

$legacyProduct = GroundedInput::fromProductRuntimeResult([
    'reply_text' => '東京行程推薦',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日']],
    'recommendation_summary' => ['result_count' => 1],
]);
contract_assert(
    $legacyProduct->getRuntimeType() === RuntimeType::PRODUCT_SEARCH,
    'Legacy product factory: product_search runtime_type'
);

// --- GroundedOutput round-trip (MVP fields) ---

$output = new GroundedOutput(
    '為您整理東京行程',
    true,
    2,
    GroundedInput::SOURCE_PRODUCT_SEARCH,
    false,
    [],
    ReplyType::NORMAL,
    LayoutProfile::PRODUCT_RICH,
    false,
    true,
    'travel_consultant'
);

contract_assert($output->isReplySuppressed() === false, 'Output: reply_suppressed default false');
contract_assert($output->isValidationPassed() === true, 'Output: validation_passed default true (pass-through phase)');

$outputArr = $output->toArray();
foreach (
    [
        'reply_text',
        'reply_type',
        'grounded',
        'used_facts_count',
        'layout_profile',
        'voice_profile_used',
        'reply_suppressed',
        'validation_passed',
    ] as $mvpOutKey
) {
    contract_assert(array_key_exists($mvpOutKey, $outputArr), "Output toArray MVP key: {$mvpOutKey}");
}
contract_assert($outputArr['reply_suppressed'] === false, 'Output toArray: reply_suppressed false');
contract_assert($outputArr['validation_passed'] === true, 'Output toArray: validation_passed true');

$outputRoundTrip = GroundedOutput::fromArray($outputArr);
contract_assert($outputRoundTrip->getReplyText() === $output->getReplyText(), 'Output round-trip: reply_text');
contract_assert($outputRoundTrip->getReplyType() === $output->getReplyType(), 'Output round-trip: reply_type');
contract_assert(
    $outputRoundTrip->isReplySuppressed() === $output->isReplySuppressed(),
    'Output round-trip: reply_suppressed'
);
contract_assert(
    $outputRoundTrip->isValidationPassed() === $output->isValidationPassed(),
    'Output round-trip: validation_passed'
);

// Optional output fields omitted from toArray when empty
$sparseOutput = new GroundedOutput('ok', false, 0, '', false);
$sparseArr = $sparseOutput->toArray();
contract_assert(!array_key_exists('validation_notes', $sparseArr), 'Output: validation_notes omitted when empty');
contract_assert(!array_key_exists('referenced_fact_ids', $sparseArr), 'Output: referenced_fact_ids omitted when empty');
contract_assert(!array_key_exists('next_best_action_presented', $sparseArr), 'Output: nba omitted when empty');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_grounded_contract_foundation (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
