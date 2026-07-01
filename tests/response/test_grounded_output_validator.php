<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2d — GroundedOutputValidator tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedOutputValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'validation' . DIRECTORY_SEPARATOR
    . 'GroundedFactAllowlistBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'validation' . DIRECTORY_SEPARATOR
    . 'GroundedOutputDegrader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR
    . 'TravelConsultantPersonaRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'product_source' . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR
    . 'TravelConsultantPersonaFormatter.php';

$failures = 0;

function gov_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$validator = new GroundedOutputValidator();
$tenant = ['tenant_key' => 'travel_b', 'tenant_sno' => '5f99b8d665e8444d', 'company_name' => '旅行蜜優惠'];

// --- flag OFF: validator not invoked via composer ---
$legacyComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$knowledgeComposer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$provider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $fixtureRoot);
$runtime = new TenantPrivateKnowledgeRuntime($provider, null, $knowledgeComposer);
$phoneResult = $runtime->handle('請問客服電話', $tenant);
$legacyOut = $legacyComposer->composeFromKnowledgeResult($phoneResult, $tenant);
gov_assert($legacyOut->isValidationPassed() === true, 'flag OFF: validation_passed true legacy semantics');

// --- flag ON + valid knowledge ---
$runtimeComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => true]);
$knowledgeOut = $runtimeComposer->composeFromKnowledgeResult($phoneResult, $tenant);
gov_assert($knowledgeOut->isValidationPassed() === true, 'flag ON + valid knowledge: validation_passed true');
gov_assert($knowledgeOut->getReplyType() === ReplyType::NORMAL, 'flag ON + valid knowledge: normal_reply');

// --- flag ON + valid product ---
$personaFormatter = TravelConsultantPersonaFormatter::createWithFixedIndex(0);
$personaRuntime = new TravelConsultantPersonaRuntime($personaFormatter);
$productSummary = [
    'result_count' => 1,
    'top_products' => [
        ['title' => '東京 5 日', 'primary_url' => 'https://example.com/tokyo', 'display_emoji' => '✈️'],
    ],
    'primary_url' => 'https://example.com/tokyo',
];
$productPayload = [
    'reply_text' => 'unused',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日', 'primary_url' => 'https://example.com/tokyo']],
    'recommendation_summary' => $productSummary,
];
$productOut = $runtimeComposer->composeProductReply($productPayload, $tenant);
gov_assert($productOut->isValidationPassed() === true, 'flag ON + valid product: validation_passed true');
gov_assert($productOut->getReplyType() === ReplyType::NORMAL, 'flag ON + valid product: normal_reply');

// --- invented URL → fail + degrade ---
$badInput = GroundedInput::fromProductRuntimeResult($productPayload, $tenant);
$badCandidate = new GroundedOutput(
    "推薦行程：https://evil.example.com/hack\n\n東京 5 日",
    true,
    1,
    GroundedInput::SOURCE_PRODUCT_SEARCH,
    false,
    [],
    ReplyType::NORMAL,
    LayoutProfile::PRODUCT_RICH,
    false,
    true,
    'travel_consultant',
    [],
    ['東京 5 日']
);
$badOut = $validator->validateAndFinalize($badInput, $badCandidate, true);
gov_assert($badOut->isValidationPassed() === false, 'invented URL: validation_passed false');
gov_assert($badOut->getReplyType() === ReplyType::NO_RESULTS, 'invented URL: degraded to no_results');
gov_assert($badOut->isReplySuppressed() === false, 'invented URL: not reply_suppressed');
gov_assert(
    strpos($badOut->getReplyText(), 'evil.example.com') === false,
    'invented URL: hallucinated text replaced'
);

// --- used_facts_count exceeds input facts ---
$overCountInput = GroundedInput::fromArray([
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'tenant' => $tenant,
    'grounded_facts' => [
        ['fact_id' => '東京 5 日', 'fact_type' => 'product_row', 'value' => '東京 5 日'],
    ],
    'product_list' => [['title' => '東京 5 日', 'primary_url' => 'https://example.com/tokyo']],
    'external_links' => [['url' => 'https://example.com/tokyo', 'title' => '東京 5 日']],
    'recommendation_summary' => $productSummary,
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => $productPayload,
]);
$overCountCandidate = new GroundedOutput(
    $personaRuntime->composeProductRecommendation($productSummary),
    true,
    5,
    GroundedInput::SOURCE_PRODUCT_SEARCH,
    false,
    [],
    ReplyType::NORMAL,
    LayoutProfile::PRODUCT_RICH,
    false,
    true,
    'travel_consultant',
    [],
    ['東京 5 日']
);
$overCountOut = $validator->validateAndFinalize($overCountInput, $overCountCandidate, true);
gov_assert($overCountOut->isValidationPassed() === false, 'used_facts_count over bound: validation_passed false');
gov_assert($overCountOut->getReplyType() !== ReplyType::NORMAL, 'used_facts_count over bound: not normal_reply');

// --- referenced_fact_ids not in input ---
$refInput = GroundedInput::fromArray([
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'tenant' => $tenant,
    'grounded_facts' => [
        ['fact_id' => 'qa-1', 'fact_type' => 'qa', 'value' => '客服電話 02-1234-5678'],
    ],
    'product_list' => [],
    'external_links' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => ['reply_text' => '客服電話 02-1234-5678', 'grounded' => true],
]);
$refCandidate = new GroundedOutput(
    '客服電話 02-1234-5678',
    true,
    1,
    GroundedInput::SOURCE_TENANT_PRIVATE,
    false,
    [],
    ReplyType::NORMAL,
    LayoutProfile::KNOWLEDGE_STANDARD,
    false,
    true,
    '',
    [],
    ['qa-unknown']
);
$refOut = $validator->validateAndFinalize($refInput, $refCandidate, true);
gov_assert($refOut->isValidationPassed() === false, 'bad referenced_fact_id: validation_passed false');
gov_assert($refOut->isReplySuppressed() === false, 'bad referenced_fact_id: not reply_suppressed');

// --- grounded=true + used_facts_count=0 + normal_reply ---
$invariantInput = GroundedInput::fromArray([
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'tenant' => $tenant,
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => ['reply_text' => 'hallucinated', 'grounded' => true],
]);
$invariantCandidate = new GroundedOutput(
    'hallucinated grounded reply',
    true,
    0,
    GroundedInput::SOURCE_TENANT_PRIVATE,
    false,
    ['grounded_without_facts'],
    ReplyType::NORMAL,
    LayoutProfile::KNOWLEDGE_STANDARD,
    false,
    true
);
$invariantOut = $validator->validateAndFinalize($invariantInput, $invariantCandidate, true);
gov_assert($invariantOut->isValidationPassed() === false, 'VR-001: validation_passed false');
gov_assert($invariantOut->getReplyType() === ReplyType::NO_RESULTS, 'VR-001: degraded to no_results');

// --- Human guard suppressed path: validation_passed true ---
$humanCandidate = new GroundedOutput(
    '',
    false,
    0,
    GroundedInput::SOURCE_TENANT_PRIVATE,
    false,
    ['human_takeover_suppressed'],
    ReplyType::SUPPRESSED_HUMAN_TAKEOVER,
    LayoutProfile::MINIMAL,
    true,
    true,
    'travel_consultant'
);
$humanInput = GroundedInput::fromArray([
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'tenant' => $tenant,
    'conversation_owner' => GroundedInput::CONVERSATION_OWNER_HUMAN,
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'raw_runtime_result' => [],
]);
$humanOut = $validator->validateAndFinalize($humanInput, $humanCandidate, true);
gov_assert($humanOut->isValidationPassed() === true, 'human guard: validation_passed true');
gov_assert($humanOut->isReplySuppressed() === true, 'human guard: reply_suppressed preserved');

// --- runtime HUMAN via composer ---
$humanRuntimeOut = $runtimeComposer->compose($humanInput);
gov_assert($humanRuntimeOut->isValidationPassed() === true, 'composer HUMAN: validation_passed true');
gov_assert($humanRuntimeOut->isReplySuppressed() === true, 'composer HUMAN: reply_suppressed true');

// --- validation fail uses degrader safe fixed text (no hallucination fix) ---
gov_assert(
    $invariantOut->getReplyText() === $knowledgeComposer->composeUnresolvableReply(),
    'validation fail: knowledge safe fixed phrase'
);
gov_assert($invariantOut->getReplyText() !== 'hallucinated grounded reply', 'validation fail: original reply not preserved');

// --- allowlist builder smoke ---
$allowlist = GroundedFactAllowlistBuilder::build($badInput);
gov_assert(in_array('https://example.com/tokyo', $allowlist['urls'], true), 'allowlist: product url collected');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_grounded_output_validator (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
