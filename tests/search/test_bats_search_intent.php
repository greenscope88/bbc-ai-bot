<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'hybrid_search' . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntentBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntentMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';

$ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$builder = new BatsSearchIntentBuilder(
    new HybridSearchConditionBuilder(new DateParser($ref))
);
$mapper = new BatsSearchIntentMapper();

function bats_intent_parse(BatsSearchIntentBuilder $builder, string $text): BatsSearchIntent
{
    global $ref;

    return $builder->parse($text, [
        'reference_date' => $ref,
        'merge_legacy_keyword' => true,
    ]);
}

// Case 1: 北海道 — missing date → clarification_required
$c1 = bats_intent_parse($builder, '北海道');
hybrid_test_assert($c1->isClarificationRequired(), 'case1: 北海道 requires clarification');
hybrid_test_assert(
    $c1->getClarificationReason() === ClarificationPolicy::REASON_DATE_REQUIRED,
    'case1: reason date_required'
);
hybrid_test_assert($mapper->canMap($c1) === false, 'case1: mapper blocks search');
hybrid_test_assert($mapper->toSearchCondition($c1) === null, 'case1: mapper returns null');

// Case 2: 北海道7月 — destination + dates
$c2 = bats_intent_parse($builder, '北海道7月');
hybrid_test_assert($c2->getDestination() === '北海道', 'case2: destination 北海道');
hybrid_test_assert($c2->getDateFrom() !== null && $c2->getDateTo() !== null, 'case2: has date range');
hybrid_test_assert($c2->isClarificationRequired() === false, 'case2: allows search');
$mapped2 = $mapper->toSearchCondition($c2);
hybrid_test_assert($mapped2 !== null, 'case2: mapper produces SearchCondition');
hybrid_test_assert($mapped2->getKeyword() === '北海道', 'case2: mapped keyword');

// Case 3: 北海道東京 — multi destination (R2)
$c3 = bats_intent_parse($builder, '北海道東京');
hybrid_test_assert($c3->getDestination() === '北海道', 'case3: primary destination 北海道');
hybrid_test_assert($c3->getMultiDestination() === ['東京'], 'case3: multi_destination 東京');
hybrid_test_assert($c3->isClarificationRequired(), 'case3: multi dest without date requires clarification');
$mapped3 = $mapper->toSearchCondition($c3);
hybrid_test_assert($mapped3 === null, 'case3: mapper blocked without date');

// Case 4: 北海道東京7月 — multi dest with date
$c4 = bats_intent_parse($builder, '北海道東京7月');
hybrid_test_assert($c4->getDestination() === '北海道', 'case4: primary 北海道');
hybrid_test_assert($c4->getMultiDestination() === ['東京'], 'case4: multi 東京');
hybrid_test_assert($c4->isClarificationRequired() === false, 'case4: dated multi allows search');

// Case 5: budget parsing
$c5 = bats_intent_parse($builder, '東京6月底五萬內');
hybrid_test_assert($c5->getBudgetMax() === 50000, 'case5: budget_max 50000');
hybrid_test_assert($c5->isClarificationRequired() === false, 'case5: dated query allows search');

// Case 6: departure parsing
$c6 = bats_intent_parse($builder, '台北出發六月底東京');
hybrid_test_assert($c6->getDepartureCity() === '台北', 'case6: departure_city 台北');
hybrid_test_assert($c6->getDestination() === '東京', 'case6: destination 東京');
hybrid_test_assert($c6->isClarificationRequired() === false, 'case6: allows search');

// Case 7: travel_type from style normalizer
$c7 = bats_intent_parse($builder, '六月底東京親子五萬內');
hybrid_test_assert(in_array('親子', $c7->getTravelType(), true), 'case7: travel_type contains 親子');

// Case 8: toArray contract keys
$arr = $c2->toArray();
hybrid_test_assert(isset($arr['destination'], $arr['multi_destination'], $arr['clarification_required']), 'case8: toArray contract');

hybrid_test_finish('BatsSearchIntent');
