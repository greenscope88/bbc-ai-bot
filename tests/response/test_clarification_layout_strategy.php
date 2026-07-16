<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContractFactory.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationGeminiGenerator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'ClarificationLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'LayoutStrategySelector.php';

$failures = 0;

function layout_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$factory = new ClarificationContractFactory();
$contract = $factory->create([
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'departure' => '台北',
    ],
    'tenant' => ['tenant_sno' => '1001', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'trace_id' => 'trace-layout-1',
    'conversation_id' => 'conv-layout-1',
]);

$input = GroundedInput::fromClarificationContract($contract);
layout_assert($input->getRuntimeType() === RuntimeType::CLARIFICATION, 'input runtime_type');
layout_assert($input->getReplyPolicyMode() === 'clarification', 'input reply_policy.mode');
layout_assert(($input->getReplyPolicy()['grounded_only'] ?? null) === true, 'input grounded_only');

$callCount = 0;
$generator = new ClarificationGeminiGenerator(null, static function (string $prompt) use (&$callCount, $contract): array {
    ++$callCount;
    layout_assert($prompt !== '', 'stub received prompt');

    return [
        'ok' => true,
        'text' => json_encode([
            'schema_version' => 1,
            'reply_type' => 'clarification',
            'clarification_reason' => $contract->getClarificationReason(),
            'asked_entity' => 'destination',
            'reply_text' => '想先請問這趟想去哪個目的地呢？',
            'acknowledged_entities' => [
                'departure' => '台北',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ],
            'search_claimed' => false,
            'product_facts_used' => false,
        ], JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});

$strategy = new ClarificationLayoutStrategy($generator);
layout_assert($strategy->supports($input) === true, 'strategy supports clarification input');

$draft = $strategy->compose($input);
layout_assert($draft->getReplyType() === ReplyType::CLARIFICATION, 'draft reply_type');
layout_assert($draft->getLayoutProfile() === LayoutProfile::MINIMAL, 'draft layout_profile');
layout_assert($draft->getReplyText() !== ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT, 'not fail-closed');
layout_assert($draft->getReplyText() !== '', 'draft reply non-empty');
layout_assert($draft->getUsedFactsCount() === 3, 'draft used_facts_count');
layout_assert($draft->isGrounded() === true, 'draft grounded');
layout_assert(count($draft->getReferencedFactIds()) === 3, 'draft referenced ids');
layout_assert($callCount === 1, 'single application generation call');

// Gemini failure → technical fail-closed; still one generation call
$failCalls = 0;
$failGenerator = new ClarificationGeminiGenerator(null, static function (string $prompt) use (&$failCalls): array {
    ++$failCalls;

    return ['ok' => false, 'text' => null, 'error' => 'stub transport failure'];
});
$failStrategy = new ClarificationLayoutStrategy($failGenerator);
$failDraft = $failStrategy->compose($input);
layout_assert(
    $failDraft->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
    'gemini failure fail-closed text'
);
layout_assert($failDraft->getReplyType() === ReplyType::CLARIFICATION, 'fail-closed reply_type');
layout_assert($failDraft->getUsedFactsCount() === 0, 'fail-closed used_facts_count 0');
layout_assert($failCalls === 1, 'no application retry loop on gemini failure');

// Validation failure → fail-closed, no second generate
$invalidCalls = 0;
$invalidGenerator = new ClarificationGeminiGenerator(null, static function () use (&$invalidCalls, $contract): array {
    ++$invalidCalls;

    return [
        'ok' => true,
        'text' => json_encode([
            'schema_version' => 1,
            'reply_type' => 'clarification',
            'clarification_reason' => $contract->getClarificationReason(),
            'asked_entity' => 'date',
            'reply_text' => '請問日期？',
            'acknowledged_entities' => [],
            'search_claimed' => false,
            'product_facts_used' => false,
        ], JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});
$invalidDraft = (new ClarificationLayoutStrategy($invalidGenerator))->compose($input);
layout_assert(
    $invalidDraft->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
    'validation failure fail-closed'
);
layout_assert($invalidCalls === 1, 'no application retry on validation failure');

// Reject non-clarification input
$productInput = GroundedInput::fromProductRuntimeResult([
    'reply_text' => '東京行程',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日']],
    'recommendation_summary' => ['result_count' => 1],
]);
layout_assert($strategy->supports($productInput) === false, 'does not support product input');
$productDraft = $strategy->compose($productInput);
layout_assert(
    $productDraft->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
    'non-clarification compose fail-closed'
);

// Strategy is registered exactly once in default selector (C-2)
$selector = LayoutStrategySelector::createDefault();
$resolved = $selector->resolve($input);
layout_assert($resolved instanceof ClarificationLayoutStrategy, 'default selector registers clarification strategy');
layout_assert($selector->resolve($productInput) instanceof ProductLayoutStrategy, 'product still resolves product strategy');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_clarification_layout_strategy (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
