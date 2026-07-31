<?php

$root = dirname(__DIR__, 2);
$intentDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';
$convDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsTestFixtures.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'AiuGoldUtteranceUnderstandingFixtures.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$sno = '5f99b8d665e8444d';
$cid = $sno . ':line:Uaiu01';

function runtime_with(ConversationRuntimeFacade $facade): AiIntentUnderstandingRuntime
{
    return new AiIntentUnderstandingRuntime(
        new AiuGeminiUnderstandingClientStub(AiuGoldUtteranceUnderstandingFixtures::resolver()),
        null,
        null,
        AiIntentContextLoader::createForTesting($facade)
    );
}

$facade = ConversationRuntimeFacade::createForTesting();
$runtime = runtime_with($facade);
$r = $runtime->understand('北海道 8月', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'product: intent category');
test_assert($r->isClarificationRequired() === false, 'product: no clarification');
test_assert(is_array($r->getEntities()['destination'] ?? null), 'product: entities.destination array');
test_assert(!array_key_exists('dispatch_plan', $r->toArray()), 'product: contract has no dispatch_plan');

$r = $runtime->understand('我想去東京自由行', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
test_assert($r->isClarificationRequired() === true, 'product-clar: clarification required');

$r = $runtime->understand('請問你們的客服電話是多少', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::KNOWLEDGE, 'knowledge: intent category');

$r = $runtime->understand('我想出去玩', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::AMBIGUOUS, 'ambiguous: intent category');

$humanFacade = ConversationRuntimeFacade::createForTesting();
$now = new DateTimeImmutable('2026-06-30 13:00:00', $tz);
$humanFacade->state()->recordHumanAgentMessage($cid, $now);
$r = runtime_with($humanFacade)->understand('北海道 8月', [
    'tenant_sno' => $sno,
    'conversation_id' => $cid,
    'now' => $now->modify('+1 minute'),
]);
test_assert($r->getOwnerSnapshot() === 'HUMAN', 'human: owner snapshot HUMAN');
test_assert($r->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'human: intent still understood');

$arr = $r->toArray();
test_assert(isset($arr['entities']) && !isset($arr['entity']), 'stability: entities only in contract');
test_assert(!array_key_exists('dispatch_plan', $arr), 'stability: no dispatch_plan in contract');
test_assert(!array_key_exists('raw_has_date_range', $arr), 'stability: raw presence not in semantic toArray');
$round = AiIntentUnderstandingResult::fromArray($arr);
test_assert($round->toArray() === $arr, 'stability: round-trip equals');

// --- B0-LINE-01C-1: date pipeline raw presence + normalized / expression isolation ---
$fixedClient = new class implements AiuGeminiUnderstandingClientInterface {
    /** @var array<string, mixed> */
    public array $payload = [];

    public function understand(AiuPromptRequest $request): array
    {
        unset($request);

        return $this->payload;
    }
};

$expressionOnlyPayload = [
    'intent' => 'Product Search',
    'entities' => array_merge(
        [
            'date_expression' => '8月',
            'date_from' => null,
            'date_to' => null,
        ],
        AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['東京'])
    ),
    'confidence' => 0.9,
    'clarification' => ['required' => true, 'reason' => 'missing_travel_dates'],
];
$fixedClient->payload = $expressionOnlyPayload;
$exprRuntime = new AiIntentUnderstandingRuntime(
    $fixedClient,
    null,
    null,
    AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
);
$exprResult = $exprRuntime->understand('irrelevant utterance for observation', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
$exprPresence = $exprResult->getDatePipelineRawPresence();
test_assert(is_array($exprPresence), 'obs: raw presence attached');
test_assert(($exprPresence['raw_has_date_expression'] ?? false) === true, 'obs: raw_has_date_expression true');
test_assert(($exprPresence['raw_has_date_range'] ?? true) === false, 'obs: raw_has_date_range false');
test_assert(($exprPresence['raw_has_date_from'] ?? true) === false, 'obs: raw_has_date_from false');
test_assert(($exprPresence['raw_has_date_to'] ?? true) === false, 'obs: raw_has_date_to false');
test_assert(($exprResult->getEntities()['date_from'] ?? null) === null, 'obs: expression does not create date_from');
test_assert(($exprResult->getEntities()['date_to'] ?? null) === null, 'obs: expression does not create date_to');
test_assert(
    AiIntentUnderstandingRuntime::observeRawDateFieldPresence($expressionOnlyPayload)
        === $exprPresence,
    'obs: static presence helper matches attached bag'
);

$rangePayload = [
    'intent' => 'Product Search',
    'entities' => array_merge(
        [
            'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
            'date_expression' => '8月',
            'search_keyword_tokens' => ['日本'],
        ],
        AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['日本'])
    ),
    'confidence' => 0.95,
    'clarification' => ['required' => false, 'reason' => ''],
];
$fixedClient->payload = $rangePayload;
$rangeResult = $exprRuntime->understand('another irrelevant utterance', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
$rangePresence = $rangeResult->getDatePipelineRawPresence();
test_assert(($rangePresence['raw_has_date_range'] ?? false) === true, 'obs-range: raw_has_date_range');
test_assert(($rangePresence['raw_has_date_from'] ?? false) === true, 'obs-range: raw_has_date_from');
test_assert(($rangePresence['raw_has_date_to'] ?? false) === true, 'obs-range: raw_has_date_to');
test_assert(($rangePresence['raw_date_from'] ?? '') === '2026-08-01', 'obs-range: raw_date_from value');
test_assert(($rangePresence['raw_date_to'] ?? '') === '2026-08-31', 'obs-range: raw_date_to value');
$rangeRef = $rangeResult->getDatePipelineReferenceContext();
test_assert(($rangeRef['reference_calendar_date'] ?? '') !== '', 'obs-range: reference_calendar_date attached');
test_assert(($rangeResult->getEntities()['date_from'] ?? null) === '2026-08-01', 'obs-range: normalized date_from');
test_assert(($rangeResult->getEntities()['date_to'] ?? null) === '2026-08-31', 'obs-range: normalized date_to');
$rangeResult->attachDatePipelineRawPresence(['raw_has_date_range' => false]);
test_assert(($rangeResult->getDatePipelineRawPresence()['raw_has_date_range'] ?? false) === true, 'obs: presence immutable after first attach');

// --- B0-LINE-06B-4: product_type Closed Set soft-null before Normalize ---
$ptClient = new class implements AiuGeminiUnderstandingClientInterface {
    /** @var array<string, mixed> */
    public array $payload = [];

    public function understand(AiuPromptRequest $request): array
    {
        unset($request);

        return $this->payload;
    }
};
$ptClient->payload = [
    'intent' => 'product_search',
    'entities' => array_merge(
        [
            'product_type' => '行程',
            'date_from' => '2027-03-01',
            'date_to' => '2027-03-31',
            'keyword' => null,
            'search_keyword_tokens' => ['日本'],
        ],
        AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['日本'])
    ),
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
];
$ptRuntime = new AiIntentUnderstandingRuntime(
    $ptClient,
    null,
    null,
    AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
);
$ptResult = $ptRuntime->understand('日本有什麼推薦行程', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
test_assert(
    array_key_exists('product_type', $ptResult->getEntities())
    && $ptResult->getEntities()['product_type'] === null,
    'pt: 行程 soft-null via runtime Validator'
);
test_assert(($ptResult->getEntities()['destination'] ?? null) === ['日本'], 'pt: destination preserved');
test_assert(($ptResult->getEntities()['date_from'] ?? null) === '2027-03-01', 'pt: date_from preserved');
test_assert(($ptResult->getEntities()['date_to'] ?? null) === '2027-03-31', 'pt: date_to preserved');

$ptClient->payload['entities']['product_type'] = '自由行';
$ptLegal = $ptRuntime->understand('日本自由行', ['tenant_sno' => $sno, 'conversation_id' => $cid]);
test_assert(($ptLegal->getEntities()['product_type'] ?? '') === '自由行', 'pt: legal product_type kept');

if ($failures === 0) {
    echo "ALL PASS test_ai_intent_understanding_runtime_logic\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_ai_intent_understanding_runtime_logic\n");
exit(1);
