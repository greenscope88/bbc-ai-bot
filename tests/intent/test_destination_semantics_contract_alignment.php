<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$intentDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';
$searchDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuPromptBuilder.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuProductSetContext.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuProductSetContextResolver.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsNormalizer.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuSearchKeywordTokenProjector.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuSearchKeywordProjectionResult.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuSearchKeywordTokenException.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationExecutionGate.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationSemanticGate.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationRelationCapabilityRegistry.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsTestFixtures.php';

$failures = 0;

function c_assert(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

function expect_reason(callable $fn, string $reason, string $label): void
{
    try {
        $fn();
        c_assert(false, "{$label}: expected throw");
    } catch (\InvalidArgumentException $e) {
        c_assert(
            AiuDestinationSemanticsNormalizer::reasonCodeFromMessage($e->getMessage()) === $reason,
            "{$label}: reason={$reason} got=" . $e->getMessage()
        );
    }
}

function candidate(string $label, string $role = 'travel_destination', string $status = 'executable', string $reason = 'ok'): array
{
    return AiuDestinationSemanticsTestFixtures::candidate($label, $role, $status, $reason);
}

$prompt = (new AiuPromptBuilder())->build(new AiuPromptRequest(
    'sno',
    'line',
    '幫我找九月日本行程',
    [],
    'AI',
    'active',
    null,
    new \DateTimeImmutable('2026-07-22', new \DateTimeZone('Asia/Taipei')),
    null,
    null,
    (new AiuProductSetContextResolver())->resolve('5f99b8d665e8444d')
));

// E1–E3 Prompt alignment
c_assert(strpos($prompt, 'destination_relation') !== false, 'E1: B-03 has destination_relation');
c_assert(strpos($prompt, 'destination_semantics') !== false, 'E1: B-03 has destination_semantics');
c_assert(strpos($prompt, '"travel_feasibility"') !== false || strpos($prompt, 'travel_feasibility:') !== false, 'E2: B-10 nested feasibility');
c_assert(strpos($prompt, '"destination_semantics"') !== false, 'E2: B-10 nested destination_semantics key');
c_assert(strpos($prompt, '"entities": {}') === false, 'E2: B-10 not empty entities');
c_assert(strpos($prompt, 'feasibility_reason') !== false, 'E2: B-03/B-10 feasibility_reason key');
c_assert(
    strpos($prompt, 'feasibility_reason is MANDATORY') !== false
    || strpos($prompt, 'non-empty travel_feasibility.feasibility_reason') !== false,
    'E2: Prompt requires non-empty feasibility_reason'
);
c_assert(
    strpos($prompt, 'status=executable, status=non_executable, AND status=uncertain') !== false
    || strpos($prompt, 'Required for status=executable') !== false,
    'E2: Prompt requires reason for all feasibility statuses'
);
c_assert(stripos($prompt, 'blacklist') === false, 'E3: no blacklist');
c_assert(strpos($prompt, '火星') === false && strpos($prompt, '天堂') === false, 'E3: no place special cases');
c_assert(strpos($prompt, 'non_executable') !== false, 'E3: general non_executable policy present');
c_assert(
    strpos($prompt, 'do NOT rely on fixed place lists') !== false
    || strpos($prompt, 'do not rely on fixed place lists') !== false,
    'E3: forbids fixed place lists'
);
c_assert(strpos($prompt, 'destination_relation = uncertain') !== false
    || strpos($prompt, 'destination_relation=uncertain') !== false, 'E7 prompt missing-destination uncertain');
c_assert(
    strpos($prompt, 'Runtime defaults') !== false
    || strpos($prompt, 'Runtime will fill') !== false,
    'E2: Prompt forbids Runtime auto-fill of reason'
);

// E4 single product_search normalize
$single = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['日本'],
    'destination_relation' => 'single',
    'destination_semantics' => [candidate('日本')],
]);
c_assert($single['destination'] === ['日本'], 'E4 destination');
c_assert($single['destination_relation'] === 'single', 'E4 relation');

// E5 OR / Mixed normalize
$or = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['東京', '大阪'],
    'destination_relation' => 'or',
    'destination_semantics' => [candidate('東京'), candidate('大阪')],
]);
c_assert($or['destination'] === ['東京', '大阪'], 'E5 OR labels');

$mixed = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['東京', '火星'],
    'destination_relation' => 'and',
    'destination_semantics' => [
        candidate('東京'),
        candidate('火星', 'travel_destination', 'non_executable', 'not_serviceable'),
    ],
]);
c_assert($mixed['destination'] === ['東京', '火星'], 'E6 non_executable kept in destination');

// E7 missing destination explicit contract
$missing = AiuDestinationSemanticsNormalizer::apply([], AiuDestinationSemanticsTestFixtures::missingDestinationContract());
c_assert($missing['destination'] === [], 'E7 empty destination');
c_assert($missing['destination_semantics'] === [], 'E7 empty semantics');
c_assert($missing['destination_relation'] === 'uncertain', 'E7 relation uncertain');

// E8–E14 typed failures
expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination_relation' => 'single',
        'destination_semantics' => [],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_MISSING_DESTINATION_KEY, 'E8');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_semantics' => [candidate('日本')],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_MISSING_RELATION_KEY, 'E9a');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => '',
        'destination_semantics' => [candidate('日本')],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_INVALID_RELATION, 'E9b');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => 'nope',
        'destination_semantics' => [candidate('日本')],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_INVALID_RELATION, 'E9c');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => 'single',
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_MISSING_SEMANTICS_KEY, 'E10');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            [
                'label' => '日本',
                'semantic_role' => 'travel_destination',
                'travel_feasibility' => 'executable',
            ],
        ],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_INVALID_FEASIBILITY_SHAPE, 'E11 flat');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            ['label' => '日本', 'semantic_role' => 'bad_role', 'travel_feasibility' => ['status' => 'executable', 'feasibility_reason' => 'x']],
        ],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_INVALID_ROLE, 'E12 role');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            ['label' => '日本', 'semantic_role' => 'travel_destination', 'travel_feasibility' => ['status' => 'bad', 'feasibility_reason' => 'x']],
        ],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_INVALID_FEASIBILITY_STATUS, 'E12 status');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            ['label' => '日本', 'semantic_role' => 'travel_destination', 'travel_feasibility' => ['status' => 'executable', 'feasibility_reason' => '']],
        ],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_MISSING_FEASIBILITY_REASON, 'E12 reason');

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            ['label' => '日本', 'semantic_role' => 'travel_destination', 'travel_feasibility' => ['status' => 'executable']],
        ],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_MISSING_FEASIBILITY_REASON, 'E12 reason key missing');

foreach (['executable', 'non_executable', 'uncertain'] as $statusNeedReason) {
    expect_reason(static function () use ($statusNeedReason): void {
        AiuDestinationSemanticsNormalizer::apply([], [
            'destination' => ['日本'],
            'destination_relation' => 'single',
            'destination_semantics' => [
                [
                    'label' => '日本',
                    'semantic_role' => 'travel_destination',
                    'travel_feasibility' => ['status' => $statusNeedReason, 'feasibility_reason' => '   '],
                ],
            ],
        ]);
    }, AiuDestinationSemanticsNormalizer::REASON_MISSING_FEASIBILITY_REASON, "E12 blank reason status={$statusNeedReason}");
}

// Nested complete reason → PASS (no Runtime auto-fill / alias)
$passEntities = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['日本'],
    'destination_relation' => 'single',
    'destination_semantics' => [
        candidate('日本', 'travel_destination', 'executable', 'bookable_market_destination'),
    ],
]);
c_assert(
    ($passEntities['destination_semantics'][0]['travel_feasibility']['feasibility_reason'] ?? '') === 'bookable_market_destination',
    'E12 complete nested reason PASS'
);

expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], [
        'destination' => ['日本', '花季'],
        'destination_relation' => 'single',
        'destination_semantics' => [
            candidate('日本'),
            candidate('花季', 'preference_or_descriptor'),
        ],
    ]);
}, AiuDestinationSemanticsNormalizer::REASON_DESTINATION_PROJECTION_MISMATCH, 'E13');

// E14 no silent single for empty-path without keys
expect_reason(static function (): void {
    AiuDestinationSemanticsNormalizer::apply([], []);
}, AiuDestinationSemanticsNormalizer::REASON_MISSING_DESTINATION_KEY, 'E14 no auto contract');

// E15 Normalize → Translate → Gate
$translator = new AiuProductIntentTranslator();

function to_intent(array $entities): BatsSearchIntent
{
    global $translator;
    $result = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH)
        ->setEntities($entities)
        ->setConfidence(0.9);
    $tokens = $entities['search_keyword_tokens'] ?? null;
    if (is_array($tokens)) {
        $projection = (new AiuSearchKeywordTokenProjector())->project(
            $tokens,
            AiuSearchKeywordTokenProjector::MODE_REQUIRED
        );
        if ($projection instanceof AiuSearchKeywordProjectionResult) {
            $result->attachSearchKeywordProjection($projection);
        }
    }

    return $translator->translate($result);
}

function assert_zero_call(BatsSearchIntent $intent, string $label): void
{
    $called = false;
    $client = new TourSearchApiClient('https://example.test/search', 5, static function () use (&$called): array {
        $called = true;
        return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
    });
    $res = (new TourPromptContextService())->buildTourContextResult([
        'userText' => 'gate-test',
        'sno' => 'e1fd133c7e8e45a1',
        'featureEnabled' => true,
        'searchClient' => $client,
        'authoritativeIntent' => $intent,
    ]);
    c_assert($res->getSearchCondition() === null, "{$label} no SearchCondition");
    c_assert($called === false, "{$label} Host B zero-call");
}

$allowEntities = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['東京'],
    'destination_relation' => 'single',
    'destination_semantics' => [candidate('東京')],
]);
$allowEntities['search_keyword_tokens'] = ['東京'];
$allowGate = DestinationExecutionGate::evaluate(to_intent($allowEntities));
c_assert($allowGate['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_ALLOW_SINGLE_SEARCH, 'E15 allow_single_search');

$denyEntities = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['火星'],
    'destination_relation' => 'single',
    'destination_semantics' => [candidate('火星', 'travel_destination', 'non_executable', 'x')],
]);
$denyEntities['search_keyword_tokens'] = ['火星'];
$denyIntent = to_intent($denyEntities);
$denyGate = DestinationExecutionGate::evaluate($denyIntent);
c_assert($denyGate['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_DENY_NON_EXECUTABLE, 'E15 deny_non_executable');
assert_zero_call($denyIntent, 'E16 mars');

$mixedEntities = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['東京', '火星'],
    'destination_relation' => 'and',
    'destination_semantics' => [
        candidate('東京'),
        candidate('火星', 'travel_destination', 'non_executable', 'x'),
    ],
]);
$mixedEntities['search_keyword_tokens'] = ['東京', '火星'];
$mixedIntent = to_intent($mixedEntities);
c_assert(
    DestinationExecutionGate::evaluate($mixedIntent)['execution_decision']
        === DestinationFeasibilityContracts::EXECUTION_DENY_MIXED_DESTINATION,
    'E15 mixed'
);
assert_zero_call($mixedIntent, 'E16 mixed');

$orEntities = AiuDestinationSemanticsNormalizer::apply([], [
    'destination' => ['東京', '大阪'],
    'destination_relation' => 'or',
    'destination_semantics' => [candidate('東京'), candidate('大阪')],
]);
$orEntities['search_keyword_tokens'] = ['東京', '大阪'];
$orIntent = to_intent($orEntities);
c_assert(
    DestinationExecutionGate::evaluate($orIntent)['execution_decision']
        === DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE,
    'E15 OR capability'
);
assert_zero_call($orIntent, 'E16 OR');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

echo "ALL PASS test_destination_semantics_contract_alignment\n";
exit(0);
