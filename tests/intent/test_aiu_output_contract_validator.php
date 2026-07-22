<?php
declare(strict_types=1);

/**
 * B0-LINE-06B-4 — AiuOutputContractValidator focused + Production wiring proofs.
 */

$root = dirname(__DIR__, 2);
$intentDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';
$searchDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search';
$psDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source';
$convDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuOutputContractValidator.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuSemanticJsonNormalizer.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingShadowProbe.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'BatsSearchIntentMapper.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'ProductSearchPolicyRuntime.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once $psDir . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsTestFixtures.php';

$failures = 0;

function v_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @param array<string, mixed> $validated
 * @return mixed
 */
function v_entity_pt(array $validated)
{
    if (!isset($validated['entities']) || !is_array($validated['entities'])) {
        return 'NO_ENTITIES';
    }
    if (!array_key_exists('product_type', $validated['entities'])) {
        return 'ABSENT';
    }

    return $validated['entities']['product_type'];
}

/**
 * @param array<string, mixed> $entities
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function v_semantic(array $entities, array $extra = []): array
{
    return array_merge([
        'intent' => 'product_search',
        'entities' => $entities,
        'confidence' => 0.91,
        'clarification' => ['required' => false, 'reason' => ''],
        'resume_disposition' => '',
        'metadata' => ['fixture' => 'b0-line-06b-4'],
    ], $extra);
}

$validator = new AiuOutputContractValidator();

// --- 1. Closed Set legal values unchanged ---
$closedSet = ['自由行', '半自助', '跟團', '團體', '迷你團', '包車', '郵輪'];
foreach ($closedSet as $legal) {
    $validated = $validator->validate(v_semantic(['product_type' => $legal, 'destination' => ['日本']]));
    v_assert(v_entity_pt($validated) === $legal, "legal keep: {$legal}");
}

// --- 2–5. absent / null / empty / whitespace ---
$absentIn = v_semantic(['destination' => ['日本']]);
$absentOut = $validator->validate($absentIn);
v_assert(v_entity_pt($absentOut) === 'ABSENT', 'absent: key remains absent');

$nullOut = $validator->validate(v_semantic(['product_type' => null]));
v_assert(v_entity_pt($nullOut) === null, 'null stays null');

$emptyOut = $validator->validate(v_semantic(['product_type' => '']));
v_assert(v_entity_pt($emptyOut) === null, 'empty string -> null');

$wsOut = $validator->validate(v_semantic(['product_type' => " \t "]));
v_assert(v_entity_pt($wsOut) === null, 'whitespace-only -> null');

// --- 6–9. illegal / unknown soft-null ---
foreach (['行程', '推薦', '親子團', '豪華遊輪假期'] as $illegal) {
    $out = $validator->validate(v_semantic([
        'product_type' => $illegal,
        'destination' => ['日本'],
        'keyword' => null,
    ]));
    v_assert(v_entity_pt($out) === null, "illegal soft-null: {$illegal}");
    v_assert(array_key_exists('keyword', $out['entities']), "keyword key preserved for: {$illegal}");
    v_assert($out['entities']['keyword'] === null, "keyword not invented for: {$illegal}");
}

// --- 10–13 / 17. other fields unchanged ---
$richIn = v_semantic([
    'product_type' => '行程',
    'destination' => ['日本'],
    'date_from' => '2027-03-01',
    'date_to' => '2027-03-31',
    'keyword' => null,
    'theme' => ['親子'],
    'custom_future_field' => 'keep-me',
], [
    'intent' => 'product_search',
    'confidence' => 0.88,
    'metadata' => ['trace' => 'abc', 'nested' => ['k' => 1]],
]);
$richOut = $validator->validate($richIn);
v_assert(($richOut['entities']['destination'] ?? null) === ['日本'], 'destination unchanged');
v_assert(($richOut['entities']['date_from'] ?? null) === '2027-03-01', 'date_from unchanged');
v_assert(($richOut['entities']['date_to'] ?? null) === '2027-03-31', 'date_to unchanged');
v_assert(array_key_exists('keyword', $richOut['entities']) && $richOut['entities']['keyword'] === null, 'keyword unchanged null');
v_assert(($richOut['intent'] ?? '') === 'product_search', 'intent unchanged');
v_assert(($richOut['confidence'] ?? 0) === 0.88, 'confidence unchanged');
v_assert(($richOut['metadata']['trace'] ?? '') === 'abc', 'metadata unchanged');
v_assert(($richOut['entities']['custom_future_field'] ?? '') === 'keep-me', 'unknown entity field kept');
v_assert(($richOut['entities']['theme'] ?? null) === ['親子'], 'theme unchanged');
v_assert(v_entity_pt($richOut) === null, 'rich: product_type soft-null');

// --- 14–15. no keyword invent / no relocate ---
$noMove = $validator->validate(v_semantic([
    'product_type' => '推薦',
    'destination' => ['大阪'],
]));
v_assert(!array_key_exists('keyword', $noMove['entities']) || $noMove['entities']['keyword'] !== '推薦', 'no relocate to keyword');
v_assert(v_entity_pt($noMove) === null, '推薦 soft-null');

// --- 16. input not mutated in place ---
$mutable = v_semantic([
    'product_type' => '行程',
    'destination' => ['日本'],
]);
$mutableSnapshot = $mutable;
$validatedCopy = $validator->validate($mutable);
v_assert(($mutable['entities']['product_type'] ?? '') === '行程', 'input product_type not mutated');
v_assert($mutable === $mutableSnapshot, 'input array identical after validate');
v_assert(v_entity_pt($validatedCopy) === null, 'copy soft-nulled');

// --- 18. structural invalid list-shaped entities: pass through (no soft rewrite) ---
$listShaped = [
    'intent' => 'product_search',
    'entities' => ['a', 'b'],
    'confidence' => 0.5,
    'clarification' => ['required' => false, 'reason' => ''],
];
$listOut = $validator->validate($listShaped);
v_assert($listOut['entities'] === ['a', 'b'], 'list-shaped entities untouched');

try {
    (new AiuSemanticJsonNormalizer())->normalize([
        'intent' => 'not_a_real_intent',
        'entities' => ['destination' => ['日本']],
        'confidence' => 0.5,
        'clarification' => ['required' => false, 'reason' => ''],
    ], 'x');
    v_assert(false, 'structural invalid intent must throw');
} catch (InvalidArgumentException $e) {
    v_assert(strpos($e->getMessage(), 'invalid semantic intent') !== false, 'existing structural failure route');
}

// --- 19–24 / 29. Runtime: Validator before Normalizer; 行程 → null; resume same path ---
$normOnly = (new AiuSemanticJsonNormalizer())->normalize(v_semantic(
    AiuDestinationSemanticsTestFixtures::mergeEntities([
        'product_type' => '行程',
        'date_from' => '2027-03-01',
        'date_to' => '2027-03-31',
        'keyword' => null,
    ], ['日本'])
), '日本有什麼推薦行程');
v_assert(($normOnly['entities']['product_type'] ?? '') === '行程', '19: Normalizer alone would keep 行程');

$orderProbe = new class implements AiuGeminiUnderstandingClientInterface {
    /** @var int */
    public $calls = 0;

    /** @var array<string, mixed> */
    public array $payload = [];

    public function understand(AiuPromptRequest $request): array
    {
        unset($request);
        ++$this->calls;

        return $this->payload;
    }
};

$orderProbe->payload = v_semantic(
    AiuDestinationSemanticsTestFixtures::mergeEntities([
        'product_type' => '行程',
        'date_from' => '2027-03-01',
        'date_to' => '2027-03-31',
        'keyword' => null,
    ], ['日本'])
);

$cid = '5f99b8d665e8444d:line:Uvalidator01';
$runtime = new AiIntentUnderstandingRuntime(
    $orderProbe,
    null,
    null,
    AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
);
$result = $runtime->understand(
    'BATS測試 2027年3月 日本有什麼推薦行程',
    [
        'conversation_id' => $cid,
        'tenant_sno' => '5f99b8d665e8444d',
        'channel_id' => 'Cvalidator',
        'line_user_id' => 'Uvalidator01',
        'webhook_event_id' => 'evt-validator-1',
        'trace_id' => 'trace-validator-1',
        'reference_date' => new DateTimeImmutable('2026-07-18', new DateTimeZone('Asia/Taipei')),
    ]
);

v_assert($orderProbe->calls === 1, '20: authoritative Gemini result processed once');
v_assert(
    array_key_exists('product_type', $result->getEntities())
    && $result->getEntities()['product_type'] === null,
    '19/21: Validator before Normalizer soft-nulls 行程'
);
v_assert(($result->getEntities()['destination'] ?? null) === ['日本'], '22: destination 日本 kept');
v_assert(($result->getEntities()['date_from'] ?? null) === '2027-03-01', '23: date_from kept');
v_assert(($result->getEntities()['date_to'] ?? null) === '2027-03-31', '23: date_to kept');
v_assert(
    !array_key_exists('keyword', $result->getEntities()) || $result->getEntities()['keyword'] === null,
    '24: semantic keyword stays null/absent'
);
v_assert($result->getIntent() === AiIntentCategory::PRODUCT_SEARCH, '20: authoritative product_search');

// Resume-shaped second Gemini result through the same Runtime entry (same Validator).
$orderProbe->calls = 0;
$orderProbe->payload = v_semantic(
    AiuDestinationSemanticsTestFixtures::mergeEntities([
        'product_type' => '行程',
        'date_from' => '2027-03-01',
        'date_to' => '2027-03-31',
        'keyword' => null,
    ], ['日本']),
    ['resume_disposition' => StructuredSearchResumeDispositionContract::NEW_REQUEST]
);
$resumeResult = $runtime->understand(
    '3月還有嗎',
    [
        'conversation_id' => $cid,
        'tenant_sno' => '5f99b8d665e8444d',
        'channel_id' => 'Cvalidator',
        'line_user_id' => 'Uvalidator01',
        'webhook_event_id' => 'evt-validator-2',
        'trace_id' => 'trace-validator-2',
        'reference_date' => new DateTimeImmutable('2026-07-18', new DateTimeZone('Asia/Taipei')),
    ]
);
v_assert($orderProbe->calls === 1, '29: resume new Gemini via same Runtime entry');
v_assert(
    array_key_exists('product_type', $resumeResult->getEntities())
    && $resumeResult->getEntities()['product_type'] === null,
    '29: resume product_type soft-null'
);

// --- 25–28. Translator / Source mapping / Policy after soft-null ---
$translator = new AiuProductIntentTranslator();
$bats = $translator->translate($result);
v_assert($bats->getProductType() === null, '25: translator product_type null');

$condition = (new BatsSearchIntentMapper())->toSearchCondition($bats);
v_assert($condition !== null, 'mapper produces condition');
v_assert($condition->getProductType() === null, 'condition product_type null');
v_assert($condition->getKeyword() === null, 'condition keyword null');

$hostBKeyword = SourceQueryMapper::buildHostBKeywordFromSearchCondition($condition);
v_assert($hostBKeyword === '' || strpos($hostBKeyword, '行程') === false, '26: no keyword=行程 from product_type');
$wireDoc = [
    'destination' => ['日本'],
    'keyword' => null,
    'product_type' => $condition->getProductType(),
];
$enriched = SourceQueryMapper::enrichSearchDocument($wireDoc);
v_assert(strpos((string) ($enriched['source_keyword_query'] ?? ''), '行程') === false, '26: source_keyword_query no 行程');
$hostBFromCond = SourceQueryMapper::buildHostBKeywordFromSearchCondition($condition);
v_assert($hostBFromCond === '', '26: Host B keyword empty when product_type null and keyword null');

$policyItems = [
    ['title' => '日本賞櫻五日', 'primary_url' => 'https://example.test/jp-1'],
    ['title' => '日本自由行六日', 'primary_url' => 'https://example.test/jp-2'],
];
$policy = new ProductSearchPolicyRuntime();
$nullPolicy = $policy->apply($condition, $policyItems);
v_assert(($nullPolicy['policy'] ?? '') !== 'product_type_strict', '27: no strict for illegal/null');
v_assert(count($nullPolicy['primary_results']) === 2, '27: results not emptied by illegal product_type');

$legalRuntime = new AiIntentUnderstandingRuntime(
    new class implements AiuGeminiUnderstandingClientInterface {
        public function understand(AiuPromptRequest $request): array
        {
            unset($request);

            return v_semantic(
                AiuDestinationSemanticsTestFixtures::mergeEntities([
                    'product_type' => '自由行',
                    'date_from' => '2026-11-01',
                    'date_to' => '2026-11-30',
                    'keyword' => null,
                ], ['首爾'])
            );
        }
    },
    null,
    null,
    AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
);
$legalResult = $legalRuntime->understand('首爾自由行11月', ['conversation_id' => $cid]);
v_assert(($legalResult->getEntities()['product_type'] ?? '') === '自由行', '28: legal product_type kept');
$legalBats = $translator->translate($legalResult);
$legalCond = (new BatsSearchIntentMapper())->toSearchCondition($legalBats);
v_assert($legalCond !== null && $legalCond->getProductType() === '自由行', '28: legal condition product_type');
$legalPolicy = $policy->apply($legalCond, [
    ['title' => '首爾11月自由行五日'],
    ['title' => '首爾11月跟團六日'],
]);
v_assert(($legalPolicy['policy'] ?? '') === 'product_type_strict', '28: legal still strict');
v_assert(count($legalPolicy['primary_results']) === 1, '28: legal strict filters');

// --- 30. Shadow observes only; does not overwrite authoritative validated result ---
$authEntities = $result->getEntities();
$shadow = AiIntentUnderstandingShadowProbe::run([
    'tenant_sno' => 'shadow-off-tenant',
    'conversation_id' => $cid,
    'message' => '日本行程',
    'trace_id' => 'shadow-trace',
    'config' => [
        AiIntentUnderstandingShadowProbe::FLAG_ENABLED => false,
        AiIntentUnderstandingShadowProbe::FLAG_TENANTS => [],
    ],
], $runtime);
v_assert(($shadow['executed'] ?? true) === false, '30: shadow disabled does not execute');
v_assert($result->getEntities() === $authEntities, '30: authoritative entities unchanged by shadow');

$shadowOnProbe = new class implements AiuGeminiUnderstandingClientInterface {
    public function understand(AiuPromptRequest $request): array
    {
        unset($request);

        return v_semantic(
            AiuDestinationSemanticsTestFixtures::mergeEntities([
                'product_type' => '推薦',
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ], ['京都'])
        );
    }
};
$shadowRuntime = new AiIntentUnderstandingRuntime(
    $shadowOnProbe,
    null,
    null,
    AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
);
$authBeforeShadow = $result->toArray();
$shadowExecuted = AiIntentUnderstandingShadowProbe::run([
    'tenant_sno' => '5f99b8d665e8444d',
    'conversation_id' => $cid,
    'message' => '京都推薦',
    'trace_id' => 'shadow-on',
    'config' => [
        AiIntentUnderstandingShadowProbe::FLAG_ENABLED => true,
        AiIntentUnderstandingShadowProbe::FLAG_TENANTS => ['5f99b8d665e8444d'],
    ],
], $shadowRuntime);
v_assert(($shadowExecuted['executed'] ?? false) === true, '30: shadow can execute observably');
v_assert($result->toArray() === $authBeforeShadow, '30: shadow does not overwrite authoritative');

if ($failures === 0) {
    echo "ALL PASS test_aiu_output_contract_validator\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_output_contract_validator\n");
exit(1);
