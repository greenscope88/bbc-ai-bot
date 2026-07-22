<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$intentDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';
$searchDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationExecutionGate.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationSemanticGate.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationRelationCapabilityRegistry.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsTestFixtures.php';

$failures = 0;

function tr_assert(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$translator = new AiuProductIntentTranslator();

// C-0: Constructor omits destination_relation → blank sentinel, not single
$batsCtorOmit = new BatsSearchIntent('ctor-omit');
tr_assert($batsCtorOmit->getDestinationRelation() === '', 'C-0: omitted constructor relation is blank');
tr_assert($batsCtorOmit->getDestinationRelation() !== 'single', 'C-0: omitted constructor relation is not single');
$gateCtorOmit = DestinationExecutionGate::evaluate($batsCtorOmit);
tr_assert($gateCtorOmit['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, 'C-0: omitted relation Gate fail_closed');

$batsCtorDestOnly = new BatsSearchIntent('北海道7月', BatsSearchIntent::INTENT_TOUR_SEARCH, ['北海道']);
tr_assert($batsCtorDestOnly->getDestinationRelation() === '', 'C-0b: dest-only ctor relation blank');
$gateCtorDestOnly = DestinationExecutionGate::evaluate($batsCtorDestOnly);
tr_assert($gateCtorDestOnly['execution_allowed'] === false, 'C-0b: dest-only ctor execution blocked');

$mockCalled = false;
$mockClient = new TourSearchApiClient('https://example.test/search', 5, static function () use (&$mockCalled): array {
    $mockCalled = true;
    return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
});
$tourBlocked = (new TourPromptContextService())->buildTourContextResult([
    'userText' => 'gate-test',
    'sno' => 'e1fd133c7e8e45a1',
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'authoritativeIntent' => $batsCtorDestOnly,
]);
tr_assert($tourBlocked->getSearchCondition() === null, 'C-0c: omitted relation no SearchCondition');
tr_assert($mockCalled === false, 'C-0d: omitted relation Host B zero-call');

function makeResult(array $entities): AiIntentUnderstandingResult
{
    $r = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH);
    $r->setEntities($entities);
    return $r;
}

// C-1: Missing destination_relation → empty string → Gate fail_closed
$bats1 = $translator->translate(makeResult([
    'destination' => ['東京'],
    'destination_semantics' => [AiuDestinationSemanticsTestFixtures::candidate('東京')],
]));
tr_assert($bats1->getDestinationRelation() === '', 'C-1: missing relation → empty string');
$gate1 = DestinationExecutionGate::evaluate($bats1);
tr_assert($gate1['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, 'C-1: Gate fail_closed');

// C-2: null destination_relation → empty string → Gate fail_closed
$bats2 = $translator->translate(makeResult([
    'destination' => ['東京'],
    'destination_relation' => null,
    'destination_semantics' => [AiuDestinationSemanticsTestFixtures::candidate('東京')],
]));
tr_assert($bats2->getDestinationRelation() === '', 'C-2: null relation → empty string');
$gate2 = DestinationExecutionGate::evaluate($bats2);
tr_assert($gate2['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, 'C-2: Gate fail_closed');

// C-3: empty string destination_relation → Gate fail_closed
$bats3 = $translator->translate(makeResult([
    'destination' => ['東京'],
    'destination_relation' => '',
    'destination_semantics' => [AiuDestinationSemanticsTestFixtures::candidate('東京')],
]));
tr_assert($bats3->getDestinationRelation() === '', 'C-3: empty relation stays empty');
$gate3 = DestinationExecutionGate::evaluate($bats3);
tr_assert($gate3['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, 'C-3: Gate fail_closed');

// C-4: illegal destination_relation → Gate fail_closed
$bats4 = $translator->translate(makeResult([
    'destination' => ['東京'],
    'destination_relation' => 'sequential',
    'destination_semantics' => [AiuDestinationSemanticsTestFixtures::candidate('東京')],
]));
tr_assert($bats4->getDestinationRelation() === 'sequential', 'C-4: illegal relation preserved');
$gate4 = DestinationExecutionGate::evaluate($bats4);
tr_assert($gate4['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, 'C-4: Gate fail_closed');

// C-5: missing relation → no SearchCondition path (zero-call evidence via Gate)
tr_assert($gate1['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, 'C-5: fail_closed blocks SearchCondition');

// C-6/7: fail_closed → zero-call contract (no Product Source / Host B / Flex Carousel)
tr_assert(!isset($gate1['allow_single_search']), 'C-6: no allow_single_search on fail_closed');
tr_assert(!isset($gate1['flex_carousel']), 'C-7: no Flex Carousel on fail_closed');

// C-8: old destination[] without destination_semantics → Gate fail_closed (explicit single does not bypass)
$bats8 = new BatsSearchIntent('', BatsSearchIntent::INTENT_TOUR_SEARCH, ['東京'], [], null, null, null, null, null, null, null, null, null, null, [], [], false, null, 0.9, 'single', []);
$gate8 = DestinationExecutionGate::evaluate($bats8);
tr_assert($gate8['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_FAIL_CLOSED, 'C-8: old dest[] no semantics → fail_closed');

// C-9: test helper must not auto-fabricate semantics
tr_assert(!method_exists(AiuDestinationSemanticsTestFixtures::class, 'ensureProductSearchDestinationContract'), 'C-9: auto-contract helper removed');

// C-10: explicit single fixture → allow_single_search
$bats10 = $translator->translate(makeResult(
    AiuDestinationSemanticsTestFixtures::mergeEntities([], ['東京'])
));
$gate10 = DestinationExecutionGate::evaluate($bats10);
tr_assert($gate10['execution_decision'] === 'allow_single_search', 'C-10: explicit single → allow_single_search');

// C-11: Mars (non-executable) → deny_non_executable
$bats11 = $translator->translate(makeResult([
    'destination' => ['火星'],
    'destination_relation' => 'single',
    'destination_semantics' => [AiuDestinationSemanticsTestFixtures::candidate('火星', 'travel_destination', 'non_executable', 'destination_not_serviceable')],
]));
$gate11 = DestinationExecutionGate::evaluate($bats11);
tr_assert($gate11['execution_decision'] === 'deny_non_executable', 'C-11: Mars → deny_non_executable');

// C-12: Tokyo OR Osaka → capability_unavailable, zero-call
$bats12 = $translator->translate(makeResult(
    AiuDestinationSemanticsTestFixtures::mergeEntities([], ['東京', '大阪'], 'or')
));
$gate12 = DestinationExecutionGate::evaluate($bats12);
tr_assert($gate12['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE, 'C-12: OR → deny_relation_capability_unavailable');

// C-13: Tokyo + Mars mixed → deny_mixed_destination
$bats13 = $translator->translate(makeResult([
    'destination' => ['東京', '火星'],
    'destination_relation' => 'and',
    'destination_semantics' => [
        AiuDestinationSemanticsTestFixtures::candidate('東京'),
        AiuDestinationSemanticsTestFixtures::candidate('火星', 'travel_destination', 'non_executable', 'not_travel'),
    ],
]));
$gate13 = DestinationExecutionGate::evaluate($bats13);
tr_assert($gate13['execution_decision'] === DestinationFeasibilityContracts::EXECUTION_DENY_MIXED_DESTINATION, 'C-13: Tokyo+Mars deny_mixed_destination');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

echo "ALL PASS test_translator_relation_fail_closed\n";
exit(0);
