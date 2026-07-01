<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2a / 2-E-2b — ComposerRuntime pipeline tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ComposerRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'HumanTakeoverDefenseGuard.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'knowledge' . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';

$failures = 0;

function pipeline_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR
    . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$composer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$provider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $fixtureRoot);
$runtime = new TenantPrivateKnowledgeRuntime($provider, null, $composer);
$tenant = ['tenant_key' => 'travel_b', 'tenant_sno' => '5f99b8d665e8444d', 'company_name' => '旅行蜜優惠'];

$phoneResult = $runtime->handle('請問客服電話', $tenant);

$legacyComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$runtimeComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => true]);

// --- flag OFF: legacy pass-through unchanged ---
$legacyOut = $legacyComposer->composeFromKnowledgeResult($phoneResult, $tenant);
pipeline_assert(
    $legacyOut->getText() === $phoneResult['reply_text'],
    'flag OFF: reply text passthrough unchanged'
);
pipeline_assert($legacyComposer->isGenerativeRuntimeEnabled() === false, 'flag OFF: generative disabled');

// --- flag ON + owner=AI + knowledge_private: strategy path matches legacy ---
$aiInput = GroundedInput::fromKnowledgeRuntimeResult($phoneResult, $tenant);
$runtimeAiOut = $runtimeComposer->compose($aiInput);
pipeline_assert($runtimeComposer->isGenerativeRuntimeEnabled() === true, 'flag ON: generative enabled');
pipeline_assert(
    $runtimeAiOut->getReplyText() === $legacyOut->getReplyText(),
    'flag ON + knowledge_private: reply text matches legacy output'
);
pipeline_assert(
    $runtimeAiOut->getReplyText() === $phoneResult['reply_text'],
    'flag ON + knowledge_private: matches KnowledgeResponseComposer baseline'
);
pipeline_assert($runtimeAiOut->isReplySuppressed() === false, 'flag ON + owner AI: not suppressed');

// --- flag ON + product_search: legacy fallback ---
$productInput = GroundedInput::fromProductRuntimeResult([
    'reply_text' => 'product copy unchanged',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日']],
    'recommendation_summary' => ['result_count' => 1],
], $tenant);
$productOut = $runtimeComposer->compose($productInput);
pipeline_assert(
    $productOut->getReplyText() === 'product copy unchanged',
    'flag ON + product_search: legacy fallback'
);

// --- HumanTakeoverDefenseGuard: owner=HUMAN ---
$humanInput = GroundedInput::fromArray([
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'tenant' => ['tenant_key' => 'travel_b', 'tenant_sno' => '1', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => ['items' => []],
    'external_links' => [],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'metadata' => ['customer_query' => '', 'trace_id' => '', 'conversation_id' => ''],
    'conversation_context' => [],
    'conversation_owner' => GroundedInput::CONVERSATION_OWNER_HUMAN,
    'conversation_status' => GroundedInput::CONVERSATION_STATUS_ACTIVE,
    'raw_runtime_result' => $phoneResult,
]);

$guardOut = HumanTakeoverDefenseGuard::evaluate($humanInput);
pipeline_assert($guardOut !== null, 'guard: returns output for owner HUMAN');
pipeline_assert($guardOut->getReplyText() === '', 'guard: reply_text empty');
pipeline_assert($guardOut->isReplySuppressed() === true, 'guard: reply_suppressed true');
pipeline_assert(
    $guardOut->getReplyType() === ReplyType::SUPPRESSED_HUMAN_TAKEOVER,
    'guard: reply_type suppressed_human_takeover'
);
pipeline_assert($guardOut->isValidationPassed() === true, 'guard: validation_passed true per SSOT §15');

// --- ComposerRuntime via flag ON + owner=HUMAN ---
$runtimeHumanOut = $runtimeComposer->compose($humanInput);
pipeline_assert($runtimeHumanOut->getReplyText() === '', 'runtime HUMAN: reply_text empty');
pipeline_assert($runtimeHumanOut->isReplySuppressed() === true, 'runtime HUMAN: reply_suppressed true');
pipeline_assert(
    $runtimeHumanOut->getReplyType() === ReplyType::SUPPRESSED_HUMAN_TAKEOVER,
    'runtime HUMAN: reply_type suppressed_human_takeover'
);

// --- ComposerRuntime direct: generative callback invoked for AI owner ---
$runtime = new ComposerRuntime();
$invoked = false;
$directOut = $runtime->run($aiInput, function (GroundedInput $input) use (&$invoked, $legacyComposer): GroundedOutput {
    $invoked = true;

    return $legacyComposer->composeGenerativePath($input);
});
pipeline_assert($invoked === true, 'ComposerRuntime: generative callback invoked for AI owner');
pipeline_assert(
    $directOut->getReplyText() === $phoneResult['reply_text'],
    'ComposerRuntime: knowledge strategy reply preserved'
);

$suppressedDirect = $runtime->run($humanInput, function (): GroundedOutput {
    throw new RuntimeException('legacy must not run when owner is HUMAN');
});
pipeline_assert($suppressedDirect->isReplySuppressed() === true, 'ComposerRuntime: guard blocks legacy for HUMAN');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_composer_runtime_pipeline (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
