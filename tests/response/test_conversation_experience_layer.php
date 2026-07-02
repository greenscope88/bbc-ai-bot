<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2e — ConversationExperienceLayer integration tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedOutputValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR
    . 'ConversationExperienceLayer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'PersonaAdapter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'NextBestActionPresenter.php';
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

function cel_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tenant = ['tenant_key' => 'travel_b', 'tenant_sno' => '5f99b8d665e8444d', 'company_name' => '旅行蜜優惠'];
$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$knowledgeComposer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$provider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $fixtureRoot);
$runtime = new TenantPrivateKnowledgeRuntime($provider, null, $knowledgeComposer);
$phoneResult = $runtime->handle('請問客服電話', $tenant);

$legacyComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$runtimeComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => true]);

// --- flag OFF: no CEL, legacy unchanged ---
$legacyOut = $legacyComposer->composeFromKnowledgeResult($phoneResult, $tenant);
$legacyBaseline = $legacyOut->getReplyText();
cel_assert($legacyComposer->isGenerativeRuntimeEnabled() === false, 'flag OFF: generative disabled');

// --- flag ON + empty context: CEL no-op (reply matches baseline) ---
$emptyContextOut = $runtimeComposer->composeFromKnowledgeResult($phoneResult, $tenant);
cel_assert(
    $emptyContextOut->getReplyText() === $legacyBaseline,
    'flag ON + empty context: CEL no-op preserves layout reply'
);
cel_assert($emptyContextOut->isValidationPassed() === true, 'flag ON + empty context: validation passes');

// --- flag ON + continuity echo ---
$requirement = '東京自由行';
$contextInput = GroundedInput::fromKnowledgeRuntimeResult($phoneResult, $tenant);
$contextInput = GroundedInput::fromArray(array_merge($contextInput->toArray(), [
    'conversation_context' => [
        'current_requirement' => $requirement,
    ],
    'raw_runtime_result' => $phoneResult,
]));
$contextOut = $runtimeComposer->compose($contextInput);
cel_assert(strpos($contextOut->getReplyText(), $requirement) !== false, 'continuity: echoes requirement');
cel_assert($contextOut->isValidationPassed() === true, 'continuity output passes validator');

// --- flag ON + NBA view_product ---
$personaFormatter = TravelConsultantPersonaFormatter::createWithFixedIndex(0);
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
$nbaInput = GroundedInput::fromArray(array_merge(
    GroundedInput::fromProductRuntimeResult($productPayload, $tenant)->toArray(),
    [
        'reply_policy' => [
            'mode' => 'recommend',
            'grounded_only' => true,
            'next_best_action_hint' => NextBestActionPresenter::HINT_VIEW_PRODUCT,
        ],
        'raw_runtime_result' => $productPayload,
    ]
));
$nbaOut = $runtimeComposer->compose($nbaInput);
cel_assert(
    $nbaOut->getNextBestActionPresented() === NextBestActionPresenter::HINT_VIEW_PRODUCT,
    'NBA: next_best_action_presented set'
);
cel_assert(strpos($nbaOut->getReplyText(), '查看商品詳情') !== false, 'NBA: closing line appended');
cel_assert($nbaOut->isValidationPassed() === true, 'NBA output passes validator');

// --- Human guard does not run CEL (ComposerRuntime short-circuit) ---
$humanInput = GroundedInput::fromArray([
    'runtime_type' => 'knowledge_private',
    'tenant' => $tenant,
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'conversation_context' => ['current_requirement' => 'SHOULD_NOT_APPEAR_IN_SUPPRESSED'],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'conversation_owner' => GroundedInput::CONVERSATION_OWNER_HUMAN,
    'raw_runtime_result' => $phoneResult,
]);
$humanOut = $runtimeComposer->compose($humanInput);
cel_assert($humanOut->isReplySuppressed() === true, 'HUMAN: suppressed');
cel_assert($humanOut->getReplyText() === '', 'HUMAN: empty reply');
cel_assert(
    strpos($humanOut->getReplyText(), 'SHOULD_NOT_APPEAR_IN_SUPPRESSED') === false,
    'HUMAN: CEL continuity not applied'
);

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_conversation_experience_layer (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
