<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';

$ref = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$builder = new HybridSearchConditionBuilder(new DateParser($ref));
$gate = new HybridDateRequiredGate();

function hybrid_parse(HybridSearchConditionBuilder $builder, string $text, DateTimeImmutable $ref): SearchCondition
{
    return $builder->parse($text, [
        'reference_date' => $ref,
        'merge_legacy_keyword' => true,
    ]);
}

// Case 1: 東京
$c1 = hybrid_parse($builder, '東京', $ref);
hybrid_test_assert($gate->evaluate($c1)->requiresDateClarification(), 'case1 gate: 東京 requires clarification');
hybrid_test_assert($c1->getDateFrom() === null && $c1->getDateTo() === null, 'case1 parse: no dates');

// Case 2: 東京行程
$c2 = hybrid_parse($builder, '東京行程', $ref);
hybrid_test_assert($gate->evaluate($c2)->requiresDateClarification(), 'case2 gate: 東京行程 requires clarification');

// Case 3: 近期東京 — gate allows when dates resolved (parser may lag; verify explicit range)
$c3 = hybrid_parse($builder, '近期東京', $ref);
if ($c3->getDateFrom() !== null || $c3->getDateTo() !== null) {
    hybrid_test_assert($gate->evaluate($c3)->allowsSearch(), 'case3 gate: 近期東京 allows when parser produced dates');
} else {
    hybrid_test_assert($gate->evaluate($c3)->requiresDateClarification(), 'case3 gate: 近期東京 blocked until DateParser implements 近期');
    $c3Explicit = new SearchCondition(
        SearchCondition::INTENT_TOUR_SEARCH,
        '東京',
        null,
        null,
        null,
        '2026-06-05',
        '2026-08-04'
    );
    hybrid_test_assert($gate->evaluate($c3Explicit)->allowsSearch(), 'case3 gate: explicit date range allows search');
}

// Case 4: 高雄東京6月底
$c4 = hybrid_parse($builder, '高雄東京6月底', $ref);
hybrid_test_assert($c4->getDateFrom() !== null && $c4->getDateTo() !== null, 'case4 parse: 高雄東京6月底 has dates');
hybrid_test_assert($gate->evaluate($c4)->allowsSearch(), 'case4 gate: 高雄東京6月底 allows search');

$clarify = HybridDateRequiredGate::buildClarificationContext($c1, '東京');
hybrid_test_assert(strpos($clarify, HybridDateRequiredGate::CLARIFICATION_MARKER) !== false, 'clarification context has marker');
hybrid_test_assert(strpos($clarify, '日期澄清') !== false, 'clarification context mentions 日期澄清');

hybrid_test_finish('HybridDateRequiredGate');
