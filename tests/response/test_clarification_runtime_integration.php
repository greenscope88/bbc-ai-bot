<?php
declare(strict_types=1);

/**
 * B0-LINE-02C-2 — Generative Clarification Runtime Integration tests (stubs only).
 */

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
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'KnowledgeLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'ProductLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'RuntimeType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'response' . DIRECTORY_SEPARATOR . 'ReplyType.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'grounding' . DIRECTORY_SEPARATOR . 'GroundingPipelineRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';

$failures = 0;

function c2_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param callable(string): array{ok:bool,text:?string,error:?string} $stub
 */
function c2_buildComposerWithStub(callable $stub): GroundedResponseComposer
{
    $generator = new ClarificationGeminiGenerator(null, $stub);
    $strategy = new ClarificationLayoutStrategy($generator);
    $selector = new LayoutStrategySelector([
        $strategy,
        new KnowledgeLayoutStrategy(),
        new ProductLayoutStrategy(),
    ]);

    return new GroundedResponseComposer(null, null, $selector);
}

function c2_validJson(ClarificationContract $contract, string $replyText, array $ack = []): string
{
    return (string) json_encode([
        'schema_version' => 1,
        'reply_type' => 'clarification',
        'clarification_reason' => $contract->getClarificationReason(),
        'asked_entity' => $contract->getMissingEntity(),
        'reply_text' => $replyText,
        'acknowledged_entities' => $ack,
        'search_claimed' => false,
        'product_facts_used' => false,
    ], JSON_UNESCAPED_UNICODE);
}

$factory = new ClarificationContractFactory();

// --- Selector: one correct strategy, no dual match ---
$destContract = $factory->create([
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'departure' => '台北',
    ],
    'tenant' => ['tenant_sno' => '1001', 'company_name' => '旅行蜜'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
    'trace_id' => 'trace-c2-1',
]);
$clarifInput = GroundedInput::fromClarificationContract($destContract);
$productInput = GroundedInput::fromProductRuntimeResult([
    'reply_text' => '東京行程',
    'grounded' => true,
    'product_list' => [['title' => '東京 5 日']],
    'recommendation_summary' => ['result_count' => 1],
]);
$knowledgeInput = GroundedInput::fromKnowledgeRuntimeResult([
    'reply_text' => '客服電話',
    'grounded' => true,
    'query_type' => 'company_profile',
]);

$defaultSelector = LayoutStrategySelector::createDefault();
$resolvedClarif = $defaultSelector->resolve($clarifInput);
$resolvedProduct = $defaultSelector->resolve($productInput);
$resolvedKnowledge = $defaultSelector->resolve($knowledgeInput);
c2_assert($resolvedClarif instanceof ClarificationLayoutStrategy, 'selector: clarification strategy');
c2_assert($resolvedProduct instanceof ProductLayoutStrategy, 'selector: product unchanged');
c2_assert($resolvedKnowledge instanceof KnowledgeLayoutStrategy, 'selector: knowledge unchanged');
c2_assert(!($resolvedClarif instanceof ProductLayoutStrategy), 'selector: no dual product match');

// --- destination_unknown via Pipeline + Composer ---
$genCalls = 0;
$composerDest = c2_buildComposerWithStub(static function (string $prompt) use (&$genCalls, $destContract): array {
    ++$genCalls;
    unset($prompt);

    return [
        'ok' => true,
        'text' => c2_validJson(
            $destContract,
            '方便告訴我想去哪個目的地嗎？台北出發、八月都能幫您看。',
            [
                'departure' => '台北',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ]
        ),
        'error' => null,
    ];
});

$destResult = GroundingPipelineRuntime::composeClarificationReply([
    'composer' => $composerDest,
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'departure' => '台北',
    ],
    'tenant' => ['tenant_sno' => '1001', 'company_name' => '旅行蜜'],
    'trace_id' => 'trace-c2-dest',
    'conversation_id' => 'conv-c2-dest',
]);
c2_assert($destResult['route'] === GroundingPipelineRuntime::ROUTE_GENERATIVE_CLARIFICATION, 'dest: generative route');
c2_assert($destResult['asked_entity'] === 'destination', 'dest: asked destination');
c2_assert($destResult['host_b_executed'] === false, 'dest: host B = 0');
c2_assert($destResult['final_owner'] === 'grounded_response_composer', 'dest: composer owner');
c2_assert($destResult['output']->getReplyType() === ReplyType::CLARIFICATION, 'dest: reply_type');
c2_assert($destResult['output']->getReplyText() !== ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT, 'dest: not fail-closed');
c2_assert(strpos($destResult['output']->getReplyText(), '請問您預計什麼時候出發') === false, 'dest: no fixed date wording');
c2_assert($destResult['used_facts_count'] === 3, 'dest: used facts');
c2_assert($genCalls === 1, 'dest: one generation call');

// --- date_required via Pipeline ---
$dateContract = $factory->create([
    'clarification_reason' => ClarificationContract::REASON_DATE_REQUIRED,
    'known_entities' => [
        'destination' => ['北海道'],
    ],
    'tenant' => ['tenant_sno' => '1001'],
    'tone' => ['persona' => 'travel_consultant', 'allow_emoji' => true],
]);
$dateCalls = 0;
$composerDate = c2_buildComposerWithStub(static function () use (&$dateCalls, $dateContract): array {
    ++$dateCalls;

    return [
        'ok' => true,
        'text' => c2_validJson(
            $dateContract,
            '想請問大概哪一段時間出發比較方便呢？',
            ['destination' => ['北海道']]
        ),
        'error' => null,
    ];
});
$dateResult = GroundingPipelineRuntime::composeClarificationReply([
    'composer' => $composerDate,
    'clarification_reason' => ClarificationContract::REASON_DATE_REQUIRED,
    'bats_search_intent' => new BatsSearchIntent(
        'BATS測試北海道',
        BatsSearchIntent::INTENT_TOUR_SEARCH,
        ['北海道'],
        [],
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        [],
        [],
        true,
        ClarificationContract::REASON_DATE_REQUIRED,
        0.5
    ),
    'tenant' => ['tenant_sno' => '1001'],
    'trace_id' => 'trace-c2-date',
]);
c2_assert($dateResult['asked_entity'] === 'date', 'date: asked date');
c2_assert($dateResult['route'] === GroundingPipelineRuntime::ROUTE_GENERATIVE_CLARIFICATION, 'date: generative route');
c2_assert($dateResult['used_facts_count'] === 1, 'date: used facts');
c2_assert($dateCalls === 1, 'date: one generation call');

// --- unsupported reason → fail-closed, 0 Gemini calls ---
$badCalls = 0;
$composerBad = c2_buildComposerWithStub(static function () use (&$badCalls): array {
    ++$badCalls;

    return ['ok' => true, 'text' => '{}', 'error' => null];
});
$badResult = GroundingPipelineRuntime::composeClarificationReply([
    'composer' => $composerBad,
    'clarification_reason' => 'price_required',
    'known_entities' => [],
    'trace_id' => 'trace-c2-bad',
]);
c2_assert($badResult['route'] === GroundingPipelineRuntime::ROUTE_CLARIFICATION_FAIL_CLOSED, 'bad: fail-closed route');
c2_assert(
    $badResult['output']->getReplyText() === ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
    'bad: fail-closed text'
);
c2_assert($badCalls === 0, 'bad: zero gemini calls');

// --- Gemini failure → fail-closed, one call ---
$failCalls = 0;
$composerFail = c2_buildComposerWithStub(static function () use (&$failCalls): array {
    ++$failCalls;

    return ['ok' => false, 'text' => null, 'error' => 'stub fail'];
});
$failResult = GroundingPipelineRuntime::composeClarificationReply([
    'composer' => $composerFail,
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => ['departure' => '台北'],
    'trace_id' => 'trace-c2-fail',
]);
c2_assert($failResult['route'] === GroundingPipelineRuntime::ROUTE_CLARIFICATION_FAIL_CLOSED, 'gemini fail: route');
c2_assert($failCalls === 1, 'gemini fail: one app call / no retry loop');

// --- validation failure → fail-closed ---
$invalidCalls = 0;
$composerInvalid = c2_buildComposerWithStub(static function () use (&$invalidCalls, $destContract): array {
    ++$invalidCalls;

    return [
        'ok' => true,
        'text' => c2_validJson($destContract, '請問日期？', []),
        'error' => null,
    ];
});
// Force wrong asked_entity via malformed JSON rewrite
$composerInvalid = c2_buildComposerWithStub(static function () use (&$invalidCalls, $destContract): array {
    ++$invalidCalls;
    $payload = json_decode(c2_validJson($destContract, '請問日期？', []), true);
    $payload['asked_entity'] = 'date';

    return [
        'ok' => true,
        'text' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});
$invalidResult = GroundingPipelineRuntime::composeClarificationReply([
    'composer' => $composerInvalid,
    'clarification_reason' => ClarificationContract::REASON_DESTINATION_UNKNOWN,
    'known_entities' => ['departure' => '台北'],
    'trace_id' => 'trace-c2-invalid',
]);
c2_assert($invalidResult['route'] === GroundingPipelineRuntime::ROUTE_CLARIFICATION_FAIL_CLOSED, 'validation fail: route');
c2_assert($invalidCalls === 1, 'validation fail: one call');

// --- Composer owns clarification even when generative flag is OFF ---
$flagOffComposer = c2_buildComposerWithStub(static function () use ($destContract): array {
    return [
        'ok' => true,
        'text' => c2_validJson($destContract, '想確認目的地是哪裡呢？', ['departure' => '台北']),
        'error' => null,
    ];
});
// Rebuild with feature flag forced off
$flagOffComposer = new GroundedResponseComposer(
    [GroundedResponseComposer::FEATURE_GROUNDED_COMPOSER_GENERATIVE_ENABLED => false],
    null,
    new LayoutStrategySelector([
        new ClarificationLayoutStrategy(new ClarificationGeminiGenerator(null, static function () use ($destContract): array {
            return [
                'ok' => true,
                'text' => c2_validJson($destContract, '想確認目的地是哪裡呢？', ['departure' => '台北']),
                'error' => null,
            ];
        })),
        new KnowledgeLayoutStrategy(),
        new ProductLayoutStrategy(),
    ])
);
$flagOffOut = $flagOffComposer->composeClarificationReply($destContract);
c2_assert($flagOffOut->getReplyType() === ReplyType::CLARIFICATION, 'flag off: still clarification reply_type');
c2_assert($flagOffOut->getReplyText() !== '', 'flag off: generative wording produced');
c2_assert($flagOffOut->getReplyText() !== ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT, 'flag off: not fail-closed');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_clarification_runtime_integration (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
