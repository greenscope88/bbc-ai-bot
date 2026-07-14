<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-3 — Grounding Production Validation (E2E pilot gate).
 *
 * Validates runtime scenarios, rollback drills, and acceptance checklist A1–A18
 * for travel_b pilot tenant using existing core classes only.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md Appendix D / E.
 */

$root = dirname(__DIR__, 2);

require_once $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ops'
    . DIRECTORY_SEPARATOR . 'phase2f3_grounding_production_validation_gate.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding'
    . DIRECTORY_SEPARATOR . 'GroundingPipelineRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding'
    . DIRECTORY_SEPARATOR . 'GroundingRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding'
    . DIRECTORY_SEPARATOR . 'GroundingOrchestratorContextFactory.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'GroundedOutputValidator.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'ComposerRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'LayoutProfile.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'ComposerPilotValidationGate.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'Phase9C1FeatureGate.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'AcknowledgementReplyComposer.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';

const TRAVEL_B_SNO = '5f99b8d665e8444d';

$failures = 0;

/** @var list<array{id: string, passed: bool, note?: string}> */
$scenarios = [];

/** @var list<array{id: string, passed: bool, note?: string}> */
$rollbackDrills = [];

/** @var list<array{id: string, passed: bool, note?: string}> */
$acceptanceChecklist = [];

function gpv_record(array &$bucket, string $id, bool $passed, string $note = ''): void
{
    global $failures;
    $entry = ['id' => $id, 'passed' => $passed];
    if ($note !== '') {
        $entry['note'] = $note;
    }
    $bucket[] = $entry;
    if (!$passed && $note !== GroundingProductionValidationGate::NOTE_OPS_PENDING) {
        ++$failures;
        $msg = $note !== '' ? "{$id}: {$note}" : $id;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

/**
 * @return array<string, mixed>
 */
function gpv_authoritative_config(): array
{
    return [
        'grounding_layer_authoritative_enabled' => true,
        'grounding_layer_authoritative_tenant_snos' => [TRAVEL_B_SNO],
        'grounded_composer_generative_enabled' => true,
    ];
}

$tenant = [
    'tenant_sno' => TRAVEL_B_SNO,
    'tenant_key' => 'travel_b',
    'company_name' => '旅行蜜優惠',
];
$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'fixtures'
    . DIRECTORY_SEPARATOR . 'travel_b';
$knowledgeComposer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$provider = new LocalTenantPrivateKnowledgeProvider(TRAVEL_B_SNO, $fixtureRoot);
$knowledgeRuntime = new TenantPrivateKnowledgeRuntime($provider, null, $knowledgeComposer);
$composer = new GroundedResponseComposer(gpv_authoritative_config());
$groundingRuntime = new GroundingRuntime();

// --- Knowledge: tenant private ---
$phoneResult = $knowledgeRuntime->handle('請問客服電話', $tenant);
$privateType = GroundingOrchestratorContextFactory::resolveKnowledgeRuntimeType($phoneResult);
$privatePipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-knowledge-private',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-knowledge-private',
    'customer_query' => '請問客服電話',
    'runtime_type' => $privateType,
    'runtime_result' => $phoneResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $composer,
]);
gpv_record(
    $scenarios,
    'knowledge_tenant_private',
    $privateType === RuntimeType::KNOWLEDGE_PRIVATE
    && $privatePipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE
    && $privatePipeline['output']->getReplyText() !== ''
    && $privatePipeline['output']->isValidationPassed() === true,
    'tenant private knowledge authoritative pipeline'
);

// --- Knowledge: industry shared ---
$sharedResult = [
    'reply_text' => '旅遊業通用退改規則說明',
    'grounded' => true,
    'query_type' => 'industry_faq',
    'fallback_layer' => 'industry_shared',
];
$sharedType = GroundingOrchestratorContextFactory::resolveKnowledgeRuntimeType($sharedResult);
$sharedPipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-knowledge-shared',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-knowledge-shared',
    'customer_query' => '退改規則',
    'runtime_type' => $sharedType,
    'runtime_result' => $sharedResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $composer,
]);
gpv_record(
    $scenarios,
    'knowledge_industry_shared',
    $sharedType === RuntimeType::KNOWLEDGE_SHARED
    && $sharedPipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE
    && $sharedPipeline['output']->getReplyText() !== '',
    'industry shared knowledge authoritative pipeline'
);

// --- Knowledge: human service ---
$humanServiceResult = [
    'reply_text' => '為您轉接真人客服',
    'grounded' => true,
    'query_type' => 'human_handoff',
    'fallback_layer' => 'human_service',
    'human_service_required' => true,
];
$humanServiceType = GroundingOrchestratorContextFactory::resolveKnowledgeRuntimeType($humanServiceResult);
$humanServicePipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-knowledge-human',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-knowledge-human',
    'customer_query' => '我要找真人',
    'runtime_type' => $humanServiceType,
    'runtime_result' => $humanServiceResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $composer,
]);
gpv_record(
    $scenarios,
    'knowledge_human_service',
    $humanServiceType === RuntimeType::HUMAN_SERVICE
    && $humanServicePipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE
    && $humanServicePipeline['output']->getReplyType() === ReplyType::HUMAN_FALLBACK
    && $humanServicePipeline['output']->isValidationPassed() === true,
    'human service authoritative pipeline with human_agent_fallback'
);

// --- Product: 北海道 8月 五天 ---
$hokkaidoQuery = '北海道 8月 五天';
$productResult = [
    'reply_text' => '北海道夏季五日行程推薦',
    'grounded' => true,
    'recommendation_summary' => [
        'result_count' => 1,
        'top_products' => [
            ['title' => '北海道五日', 'primary_url' => 'https://example.com/hokkaido', 'display_emoji' => '✈️'],
        ],
        'primary_url' => 'https://example.com/hokkaido',
    ],
    'product_list' => [
        ['title' => '北海道五日', 'primary_url' => 'https://example.com/hokkaido'],
    ],
];
$hokkaidoPipeline = GroundingPipelineRuntime::composeProductReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-product-hokkaido',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-product-hokkaido',
    'customer_query' => $hokkaidoQuery,
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => $productResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'bats_search_intent' => [
        'destination' => ['北海道'],
        'date_from' => '8月',
        'duration' => '五天',
        'free_text' => $hokkaidoQuery,
    ],
    'composer' => $composer,
]);
$hokkaidoAssembled = $groundingRuntime->assemble(GroundingOrchestratorContextFactory::build([
    'trace_id' => 'gpv-hokkaido-assemble',
    'conversation_id' => 'conv-product-hokkaido',
    'tenant_sno' => TRAVEL_B_SNO,
    'customer_query' => $hokkaidoQuery,
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => $productResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
    'bats_search_intent' => [
        'destination' => ['北海道'],
        'date_from' => '8月',
        'duration' => '五天',
    ],
]));
$hokkaidoCtx = $hokkaidoAssembled->getConversationContext();
gpv_record(
    $scenarios,
    'product_hokkaido_8day5',
    $hokkaidoPipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE
    && ($hokkaidoCtx['destination'] ?? '') === '北海道'
    && ($hokkaidoCtx['travel_dates'] ?? '') === '8月'
    && ($hokkaidoCtx['duration'] ?? '') === '五天'
    && ($hokkaidoCtx['current_requirement'] ?? '') === $hokkaidoQuery,
    'destination/travel_dates/duration/current_requirement slots'
);

// --- Product: clarification ---
$clarificationResult = [
    'reply_text' => '請問您預計什麼時候出發？',
    'grounded' => true,
    'query_type' => 'product_clarification',
];
$clarificationAssembled = $groundingRuntime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 'gpv-clarification',
    'conversation_id' => 'conv-clarification',
    'tenant_sno' => TRAVEL_B_SNO,
    'customer_query' => '想去北海道',
    'runtime_type' => RuntimeType::CLARIFICATION,
    'source_type' => GroundedInput::SOURCE_PRODUCT_SEARCH,
    'runtime_result' => $clarificationResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
]));
$clarificationOut = $composer->compose($clarificationAssembled);
gpv_record(
    $scenarios,
    'product_clarification',
    $clarificationAssembled->getRuntimeType() === RuntimeType::CLARIFICATION
    && $clarificationOut->isValidationPassed() === true,
    'clarification runtime_type + compose validation'
);

// --- Product: waiting reply ---
$waitingText = AcknowledgementReplyComposer::composeProductWaitingReply();
$waitingResult = [
    'reply_text' => $waitingText,
    'grounded' => false,
    'recommendation_summary' => ['result_count' => 0],
    'product_list' => [],
];
$waitingAssembled = $groundingRuntime->assemble(GroundingAssemblyContext::fromArray([
    'trace_id' => 'gpv-waiting',
    'conversation_id' => 'conv-waiting',
    'tenant_sno' => TRAVEL_B_SNO,
    'customer_query' => '北海道 8月',
    'runtime_type' => RuntimeType::WAITING_ACK,
    'source_type' => GroundedInput::SOURCE_PRODUCT_SEARCH,
    'runtime_result' => $waitingResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
]));
$waitingOut = $composer->compose($waitingAssembled);
gpv_record(
    $scenarios,
    'product_waiting_reply',
    $waitingAssembled->getRuntimeType() === RuntimeType::WAITING_ACK
    && $waitingOut->isValidationPassed() === true,
    'waiting_ack runtime_type + compose validation'
);

// --- Conversation: resume context ---
$resumePipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-resume',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-resume',
    'customer_query' => '繼續剛才的',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'runtime_result' => $phoneResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'state_snapshot' => [
        'conversation_owner' => GroundedInput::CONVERSATION_OWNER_AI,
        'conversation_status' => GroundedInput::CONVERSATION_STATUS_ACTIVE,
        'resume_context' => ['last_human' => '2026-06-30T10:00:00+08:00'],
    ],
    'composer' => $composer,
]);
gpv_record(
    $scenarios,
    'conversation_resume_context',
    $resumePipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE
    && strpos($resumePipeline['output']->getReplyText(), '我已看過剛才的對話') !== false,
    'resume ack prefix from CEL'
);

// --- Conversation: customer query preserved ---
$customerQuery = '東京自由行 7月';
$queryPipeline = GroundingPipelineRuntime::composeProductReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-customer-query',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-customer-query',
    'customer_query' => $customerQuery,
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => $productResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'bats_search_intent' => ['destination' => '東京', 'date_from' => '7月'],
    'composer' => $composer,
]);
$queryAssembled = $groundingRuntime->assemble(GroundingOrchestratorContextFactory::build([
    'trace_id' => 'gpv-customer-query',
    'conversation_id' => 'conv-customer-query',
    'tenant_sno' => TRAVEL_B_SNO,
    'customer_query' => $customerQuery,
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => $productResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
    'bats_search_intent' => ['destination' => '東京', 'date_from' => '7月'],
]));
$queryMeta = $queryAssembled->getMetadata();
gpv_record(
    $scenarios,
    'conversation_customer_query',
    $queryPipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE
    && ($queryMeta['customer_query'] ?? '') === $customerQuery,
    'customer_query preserved in metadata'
);

// --- Composer: persona + CEL ---
$personaOut = $composer->compose(
    GroundedInput::fromArray(array_merge(
        GroundedInput::fromProductRuntimeResult($productResult, $tenant)->toArray(),
        [
            'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => false],
            'raw_runtime_result' => $productResult,
        ]
    ))
);
gpv_record(
    $scenarios,
    'composer_persona_cel',
    $personaOut->getVoiceProfileUsed() === 'travel_consultant'
    && strpos($personaOut->getReplyText(), '✈️') === false
    && $personaOut->getReplyText() !== ''
    && $personaOut->isValidationPassed() === true,
    'persona adapter + CEL without emoji'
);

// --- Composer: validation fail-closed ---
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
    ['北海道五日']
);
$badOut = $validator->validateAndFinalize(
    GroundedInput::fromProductRuntimeResult($productResult, $tenant),
    $badCandidate,
    true
);
gpv_record(
    $scenarios,
    'composer_validation',
    $badOut->isValidationPassed() === false
    && $badOut->getReplyType() !== ReplyType::NORMAL
    && $badOut->isReplySuppressed() === false,
    'GroundedOutputValidator fail-closed degrade'
);

// --- Human takeover suppress ---
$takeoverPipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-human-takeover',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-human-takeover',
    'customer_query' => '你好',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'runtime_result' => $phoneResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'state_snapshot' => [
        'conversation_owner' => GroundedInput::CONVERSATION_OWNER_HUMAN,
        'conversation_status' => GroundedInput::CONVERSATION_STATUS_ACTIVE,
    ],
    'composer' => $composer,
]);
gpv_record(
    $scenarios,
    'human_takeover_suppress',
    $takeoverPipeline['output']->isReplySuppressed() === true
    && $takeoverPipeline['output']->getReplyText() === ''
    && $takeoverPipeline['output']->getReplyType() === ReplyType::SUPPRESSED_HUMAN_TAKEOVER,
    'Human Guard suppresses outbound reply'
);

// --- Rollback D1: flag OFF ---
$legacyComposer = new GroundedResponseComposer(['grounded_composer_generative_enabled' => false]);
$d1Knowledge = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => [
        'grounding_layer_authoritative_enabled' => false,
        'grounding_layer_authoritative_tenant_snos' => [TRAVEL_B_SNO],
    ],
    'trace_id' => 'gpv-d1',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-d1',
    'customer_query' => '電話',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'runtime_result' => $phoneResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $legacyComposer,
]);
$d1Direct = $legacyComposer->composeFromKnowledgeResult($phoneResult, $tenant);
gpv_record(
    $rollbackDrills,
    'D1_flag_off',
    $d1Knowledge['pipeline'] === GroundingPipelineRuntime::PIPELINE_LEGACY
    && $d1Direct->getReplyText() === $d1Knowledge['output']->getReplyText(),
    'D.1 flag OFF restores legacy reply'
);

// --- Rollback D2: allowlist empty ---
$d2Pipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => [
        'grounding_layer_authoritative_enabled' => true,
        'grounding_layer_authoritative_tenant_snos' => [],
    ],
    'trace_id' => 'gpv-d2',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-d2',
    'customer_query' => '電話',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'runtime_result' => $phoneResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $legacyComposer,
]);
gpv_record(
    $rollbackDrills,
    'D2_allowlist_empty',
    $d2Pipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_LEGACY,
    'D.2 empty allowlist isolates tenant to legacy'
);

// --- Rollback D3: legacy compose direct (git revert sim) ---
$d3ProductDirect = $legacyComposer->composeProductReply($productResult, $tenant);
$d3ProductPipeline = GroundingPipelineRuntime::composeProductReply([
    'config' => [
        'grounding_layer_authoritative_enabled' => false,
        'grounding_layer_authoritative_tenant_snos' => [TRAVEL_B_SNO],
    ],
    'trace_id' => 'gpv-d3',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-d3',
    'customer_query' => $hokkaidoQuery,
    'runtime_type' => RuntimeType::PRODUCT_SEARCH,
    'runtime_result' => $productResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_PRODUCT_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $legacyComposer,
]);
gpv_record(
    $rollbackDrills,
    'D3_legacy_compose_direct',
    $d3ProductDirect->getReplyText() === $d3ProductPipeline['output']->getReplyText()
    && $d3ProductPipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_LEGACY,
    'D.3 direct legacy compose matches flag-off pipeline'
);

// --- Rollback restore (A15) ---
$restorePipeline = GroundingPipelineRuntime::composeKnowledgeReply([
    'config' => gpv_authoritative_config(),
    'trace_id' => 'gpv-restore',
    'tenant_sno' => TRAVEL_B_SNO,
    'conversation_id' => 'conv-restore',
    'customer_query' => '電話',
    'runtime_type' => RuntimeType::KNOWLEDGE_PRIVATE,
    'runtime_result' => $phoneResult,
    'dispatch_result' => ['reply_purpose' => GroundedInput::PURPOSE_KNOWLEDGE_REPLY],
    'tenant' => $tenant,
    'legacy_tenant' => $tenant,
    'composer' => $composer,
]);
gpv_record(
    $rollbackDrills,
    'restore_authoritative_config',
    $restorePipeline['pipeline'] === GroundingPipelineRuntime::PIPELINE_AUTHORITATIVE,
    'post-drill authoritative config restored'
);

// --- Acceptance A4: PHP syntax on core/grounding ---
$groundingDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding';
$a4Passed = true;
$a4Note = '';
$phpBin = getenv('PHP_BIN') !== false && trim((string) getenv('PHP_BIN')) !== ''
    ? trim((string) getenv('PHP_BIN'))
    : 'C:\\Web\\xampp\\php\\php.exe';
if (is_dir($groundingDir)) {
    foreach (glob($groundingDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $phpFile) {
        $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($phpFile) . ' 2>&1';
        $lintOutput = [];
        $lintCode = 0;
        exec($cmd, $lintOutput, $lintCode);
        if ($lintCode !== 0) {
            $a4Passed = false;
            $a4Note = basename($phpFile) . ' syntax error';
            break;
        }
    }
} else {
    $a4Passed = false;
    $a4Note = 'core/grounding directory missing';
}

// --- Acceptance checklist A1–A18 ---
$knowledgeScenariosOk = GroundingProductionValidationGate::allPassed(array_filter(
    $scenarios,
    static function (array $item): bool {
        return in_array($item['id'], [
            'knowledge_tenant_private',
            'knowledge_industry_shared',
            'knowledge_human_service',
        ], true);
    }
));
$productScenariosOk = GroundingProductionValidationGate::allPassed(array_filter(
    $scenarios,
    static function (array $item): bool {
        return in_array($item['id'], [
            'product_hokkaido_8day5',
            'product_clarification',
            'product_waiting_reply',
        ], true);
    }
));
$rollbackOk = GroundingProductionValidationGate::allPassed($rollbackDrills);

gpv_record($acceptanceChecklist, 'A1', class_exists('GroundingRuntime') && class_exists('GroundingPipelineRuntime'), 'grounding core classes present; full suite via runner');
gpv_record($acceptanceChecklist, 'A2', class_exists('ComposerPilotValidationGate') && class_exists('GroundedResponseComposer'), 'composer pilot gate present; full suite via runner');
gpv_record($acceptanceChecklist, 'A3', class_exists('Phase9C1FeatureGate'), 'saas router pilot gate present; full suite via runner');
gpv_record($acceptanceChecklist, 'A4', $a4Passed, $a4Note !== '' ? $a4Note : 'core/grounding php -l clean');
gpv_record($acceptanceChecklist, 'A5', $knowledgeScenariosOk, 'knowledge path scenarios');
gpv_record($acceptanceChecklist, 'A6', $productScenariosOk, 'product path scenarios');
gpv_record($acceptanceChecklist, 'A7', GroundingProductionValidationGate::allPassed(array_filter(
    $scenarios,
    static function (array $item): bool {
        return $item['id'] === 'product_hokkaido_8day5';
    }
)), '北海道 8月 五天 slots');
gpv_record($acceptanceChecklist, 'A8', TRAVEL_B_SNO === Phase9C1FeatureGate::TRAVEL_B_SNO && GroundingProductionValidationGate::allPassed($scenarios), 'travel_b pilot tenant E2E');
gpv_record($acceptanceChecklist, 'A9', GroundingProductionValidationGate::allPassed(array_filter(
    $scenarios,
    static function (array $item): bool {
        return $item['id'] === 'composer_persona_cel';
    }
)), 'persona / tone policy');
gpv_record($acceptanceChecklist, 'A10', strpos($productResult['recommendation_summary']['primary_url'] ?? '', 'https://') === 0
    && $hokkaidoPipeline['output']->isValidationPassed() === true, 'grounded URLs traceable');
gpv_record($acceptanceChecklist, 'A11', GroundingProductionValidationGate::allPassed(array_filter(
    $scenarios,
    static function (array $item): bool {
        return $item['id'] === 'human_takeover_suppress';
    }
)), 'human takeover suppress');
gpv_record($acceptanceChecklist, 'A12', GroundingProductionValidationGate::allPassed(array_filter(
    $scenarios,
    static function (array $item): bool {
        return $item['id'] === 'knowledge_human_service';
    }
)), 'human service handoff path');
gpv_record($acceptanceChecklist, 'A13', GroundingProductionValidationGate::allPassed(array_filter(
    $rollbackDrills,
    static function (array $item): bool {
        return $item['id'] === 'D1_flag_off';
    }
)), 'D.1 flag OFF drill');
gpv_record($acceptanceChecklist, 'A14', GroundingProductionValidationGate::allPassed(array_filter(
    $rollbackDrills,
    static function (array $item): bool {
        return $item['id'] === 'D2_allowlist_empty';
    }
)), 'D.2 allowlist drill');
gpv_record($acceptanceChecklist, 'A15', GroundingProductionValidationGate::allPassed(array_filter(
    $rollbackDrills,
    static function (array $item): bool {
        return in_array($item['id'], ['D3_legacy_compose_direct', 'restore_authoritative_config'], true);
    }
)), 'D.3 + restore drill');
gpv_record($acceptanceChecklist, 'A16', false, GroundingProductionValidationGate::NOTE_OPS_PENDING);
gpv_record($acceptanceChecklist, 'A17', false, GroundingProductionValidationGate::NOTE_OPS_PENDING);
gpv_record($acceptanceChecklist, 'A18', false, GroundingProductionValidationGate::NOTE_OPS_PENDING);

// --- Gate evaluation ---
$gate = GroundingProductionValidationGate::evaluate([
    'scenarios' => $scenarios,
    'rollback_drills' => $rollbackDrills,
    'acceptance_checklist' => $acceptanceChecklist,
    'regression_runs' => [],
]);

$summary = [
    'phase' => '2-F-3',
    'test' => 'test_grounding_production_validation.php',
    'tenant_sno' => TRAVEL_B_SNO,
    'scenarios' => $scenarios,
    'rollback_drills' => $rollbackDrills,
    'acceptance_checklist' => $acceptanceChecklist,
    'gate' => $gate,
    'failures' => $failures,
];

if ($failures > 0 || $gate['technical_verdict'] !== GroundingProductionValidationGate::VERDICT_PASS) {
    fwrite(STDERR, sprintf(
        "FAILED: %d assertion(s); technical_verdict=%s; reasons=%s\n",
        $failures,
        $gate['technical_verdict'],
        implode(',', $gate['reasons'])
    ));
    exit(GroundingProductionValidationGate::EXIT_FAIL);
}

$json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "FAIL: json_encode error\n");
    exit(GroundingProductionValidationGate::EXIT_FAIL);
}

fwrite(STDOUT, $json . "\n");
exit(GroundingProductionValidationGate::technicalExitCodeFor($gate));
