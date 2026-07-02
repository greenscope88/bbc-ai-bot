<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-3 — Grounded Response Composer Pilot Validation.
 *
 * Validates Runtime scenarios, full Composer pipeline integration, Definition of Done,
 * and rollout readiness for Grounded Response Composer v1.0.
 *
 * Does not modify architecture, contracts, or production feature flags.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ComposerPilotValidationGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ComposerRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedOutputValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR
    . 'NextBestActionPresenter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'experience' . DIRECTORY_SEPARATOR . 'PersonaRenderHints.php';
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

/** @var list<array{id: string, passed: bool, note?: string}> */
$cases = [];

/** @var list<array{id: string, passed: bool, note?: string}> */
$definitionOfDone = [];

function pilot_record(array &$bucket, string $id, bool $passed, string $note = ''): void
{
    global $failures;
    $bucket[] = ['id' => $id, 'passed' => $passed, 'note' => $note];
    if (!$passed) {
        ++$failures;
        $msg = $note !== '' ? "{$id}: {$note}" : $id;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$tenant = ['tenant_key' => 'travel_b', 'tenant_sno' => '5f99b8d665e8444d', 'company_name' => '旅行蜜優惠'];
$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$knowledgeComposer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$provider = new LocalTenantPrivateKnowledgeProvider('5f99b8d665e8444d', $fixtureRoot);
$knowledgeRuntime = new TenantPrivateKnowledgeRuntime($provider, null, $knowledgeComposer);
$phoneResult = $knowledgeRuntime->handle('請問客服電話', $tenant);

$legacyComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$runtimeComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => true]);

// --- Runtime Validation: Knowledge Reply ---
$legacyKnowledge = $legacyComposer->composeFromKnowledgeResult($phoneResult, $tenant);
$runtimeKnowledge = $runtimeComposer->composeFromKnowledgeResult($phoneResult, $tenant);
pilot_record(
    $cases,
    'knowledge_reply',
    $runtimeKnowledge->getReplyText() !== ''
    && $runtimeKnowledge->isValidationPassed() === true
    && $runtimeKnowledge->getReplyType() === ReplyType::NORMAL,
    'knowledge reply with validation_passed'
);

// --- Runtime Validation: Product Reply ---
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
    'reply_text' => 'unused legacy text',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日', 'primary_url' => 'https://example.com/tokyo']],
    'recommendation_summary' => $productSummary,
];
$productBaseline = $personaRuntime->composeProductRecommendation($productSummary);
$runtimeProduct = $runtimeComposer->composeProductReply($productPayload, $tenant);
pilot_record(
    $cases,
    'product_reply',
    $runtimeProduct->getReplyText() === $productBaseline
    && $runtimeProduct->isValidationPassed() === true
    && $runtimeProduct->getLayoutProfile() === LayoutProfile::PRODUCT_RICH,
    'product layout + validator'
);

// --- Runtime Validation: Human Takeover ---
$humanInput = GroundedInput::fromArray([
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'tenant' => $tenant,
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'grounded_facts' => [],
    'product_list' => [],
    'external_links' => [],
    'conversation_context' => ['current_requirement' => 'must-not-appear'],
    'reply_policy' => ['mode' => 'recommend', 'grounded_only' => true],
    'conversation_owner' => GroundedInput::CONVERSATION_OWNER_HUMAN,
    'raw_runtime_result' => $phoneResult,
]);
$humanOut = $runtimeComposer->compose($humanInput);
pilot_record(
    $cases,
    'human_takeover',
    $humanOut->isReplySuppressed() === true
    && $humanOut->getReplyText() === ''
    && $humanOut->getReplyType() === ReplyType::SUPPRESSED_HUMAN_TAKEOVER
    && $humanOut->isValidationPassed() === true,
    'Human Guard short-circuit before CEL'
);

// --- Runtime Validation: Resume + Conversation Context ---
$resumeInput = GroundedInput::fromArray(array_merge(
    GroundedInput::fromKnowledgeRuntimeResult($phoneResult, $tenant)->toArray(),
    [
        'resume_context' => ['last_human' => '2026-06-30T10:00:00+08:00'],
        'conversation_context' => ['current_requirement' => '東京自由行'],
        'raw_runtime_result' => $phoneResult,
    ]
));
$resumeOut = $runtimeComposer->compose($resumeInput);
pilot_record(
    $cases,
    'resume',
    strpos($resumeOut->getReplyText(), '我已看過剛才的對話') !== false,
    'resume ack prefix present'
);
pilot_record(
    $cases,
    'conversation_context',
    strpos($resumeOut->getReplyText(), '東京自由行') !== false
    && $resumeOut->isValidationPassed() === true,
    'continuity echo from snapshot'
);

// --- Runtime Validation: Persona ---
$noEmojiInput = GroundedInput::fromArray(array_merge(
    GroundedInput::fromProductRuntimeResult($productPayload, $tenant)->toArray(),
    [
        'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => false],
        'raw_runtime_result' => $productPayload,
    ]
));
$noEmojiOut = $runtimeComposer->compose($noEmojiInput);
pilot_record(
    $cases,
    'persona',
    $noEmojiOut->getVoiceProfileUsed() === 'travel_consultant'
    && strpos($noEmojiOut->getReplyText(), '✈️') === false,
    'persona consume + emoji policy'
);

// --- Runtime Validation: Next Best Action ---
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
pilot_record(
    $cases,
    'next_best_action',
    $nbaOut->getNextBestActionPresented() === NextBestActionPresenter::HINT_VIEW_PRODUCT
    && strpos($nbaOut->getReplyText(), '查看商品詳情') !== false
    && $nbaOut->isValidationPassed() === true,
    'NBA hint consumed with grounded URL'
);

// --- Runtime Validation: Grounded Output Validator fail-closed ---
$validator = new GroundedOutputValidator();
$badCandidate = new GroundedOutput(
    'https://evil.example.com/hack',
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
$badInput = GroundedInput::fromProductRuntimeResult($productPayload, $tenant);
$badOut = $validator->validateAndFinalize($badInput, $badCandidate, true);
pilot_record(
    $cases,
    'grounded_output_validator',
    $badOut->isValidationPassed() === false
    && $badOut->getReplyType() !== ReplyType::NORMAL
    && $badOut->isReplySuppressed() === false,
    'validator fail-closed degrade'
);

// --- Runtime Validation: Feature Flag OFF ---
pilot_record(
    $cases,
    'feature_flag_off',
    !$legacyComposer->isGenerativeRuntimeEnabled()
    && $legacyKnowledge->getReplyText() === $phoneResult['reply_text']
    && $legacyKnowledge->isValidationPassed() === true,
    'flag OFF legacy unchanged'
);

// --- Runtime Validation: Legacy Compatibility (flag ON empty context no-op) ---
pilot_record(
    $cases,
    'legacy_compatibility',
    $runtimeKnowledge->getReplyText() === $legacyKnowledge->getReplyText(),
    'flag ON empty context matches legacy baseline'
);

// --- Integration Validation: full pipeline stages ---
$pipelineInput = GroundedInput::fromKnowledgeRuntimeResult($phoneResult, $tenant);
$pipelineOut = $runtimeComposer->compose($pipelineInput);
pilot_record(
    $cases,
    'integration_pipeline',
    $pipelineOut->getReplyText() !== ''
    && $pipelineOut->isValidationPassed() === true
    && $pipelineOut->isReplySuppressed() === false
    && $pipelineOut->getLayoutProfile() !== '',
    'ComposerRuntime → Layout → Persona → CEL → Builder → Validator'
);

// --- ComposerRuntime run() signature unchanged (reflection smoke) ---
$composerRuntimeReflection = new ReflectionMethod(ComposerRuntime::class, 'run');
pilot_record(
    $cases,
    'composer_runtime_signature',
    $composerRuntimeReflection->getNumberOfParameters() === 2,
    'ComposerRuntime::run(input, callable) unchanged'
);

// --- Definition of Done ---
$featureConfig = GroundedResponseComposer::loadFeatureConfig();
pilot_record(
    $definitionOfDone,
    'contract_grounded_input_output',
    class_exists('GroundedInput') && class_exists('GroundedOutput'),
    'frozen contracts present'
);
pilot_record(
    $definitionOfDone,
    'composer_runtime_human_guard',
    class_exists('HumanTakeoverDefenseGuard') && class_exists('ComposerRuntime'),
    '2-E-2a runtime foundation'
);
pilot_record(
    $definitionOfDone,
    'layout_strategies',
    class_exists('KnowledgeLayoutStrategy') && class_exists('ProductLayoutStrategy'),
    '2-E-2b/c layout strategies'
);
pilot_record(
    $definitionOfDone,
    'grounded_output_validator',
    class_exists('GroundedOutputValidator'),
    '2-E-2d validator gate'
);
pilot_record(
    $definitionOfDone,
    'conversation_experience_layer',
    class_exists('ConversationExperienceLayer') && class_exists('PersonaAdapter'),
    '2-E-2e experience layer'
);
pilot_record(
    $definitionOfDone,
    'feature_flag_default_off',
    ($featureConfig[GroundedResponseComposer::FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED] ?? true) === false,
    'grounded_composer_generative_enabled default false'
);
pilot_record(
    $definitionOfDone,
    'one_composer_entry',
    method_exists('GroundedResponseComposer', 'compose'),
    'single GroundedResponseComposer entry'
);
pilot_record(
    $definitionOfDone,
    'pilot_validation_gate',
    class_exists('ComposerPilotValidationGate'),
    'pilot validation gate present'
);

// --- Gate evaluation ---
$gate = ComposerPilotValidationGate::evaluate([
    'cases' => $cases,
    'definition_of_done' => $definitionOfDone,
    'regression' => [],
]);

pilot_record(
    $cases,
    'pilot_gate_verdict',
    $gate['verdict'] === ComposerPilotValidationGate::VERDICT_PASS && $failures === 0,
    'gate PASS when all cases and DoD pass'
);

if ($failures === 0) {
    fwrite(STDOUT, sprintf(
        "OK: test_composer_pilot_validation (all passed; gate=%s; total=%d)\n",
        $gate['verdict'],
        $gate['total_count']
    ));
    exit(ComposerPilotValidationGate::exitCodeFor($gate['verdict']));
}

fwrite(STDERR, sprintf(
    "FAILED: %d assertion(s); gate=%s; reasons=%s\n",
    $failures,
    $gate['verdict'],
    implode(',', $gate['reasons'])
));
exit(ComposerPilotValidationGate::EXIT_FAIL);
