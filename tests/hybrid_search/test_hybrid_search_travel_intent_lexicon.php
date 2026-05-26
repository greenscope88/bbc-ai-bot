<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'TravelIntentLexicon.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';

$ref = new DateTimeImmutable('2026-05-25', new DateTimeZone('Asia/Taipei'));

$lex = TravelIntentLexicon::analyze('六月底東京三萬以下');
hybrid_test_assert($lex['is_tour_search'] === true, 'lexicon: tour search');
hybrid_test_assert($lex['destination'] === '東京', 'lexicon: 東京');
hybrid_test_assert($lex['has_date'] === true, 'lexicon: has_date');
hybrid_test_assert($lex['has_budget'] === true, 'lexicon: has_budget');
hybrid_test_assert($lex['reason'] === 'lexicon_date_budget_destination', 'lexicon: composite reason');

$builder = new HybridSearchConditionBuilder();
$condition = $builder->parse('六月底東京三萬以下', ['reference_date' => $ref]);
hybrid_test_assert($condition->getKeyword() === '東京', 'builder: keyword 東京');
hybrid_test_assert($condition->getDateFrom() === '2026-06-21', 'builder: date_from');
hybrid_test_assert($condition->getDateTo() === '2026-06-30', 'builder: date_to');
hybrid_test_assert($condition->getBudgetMax() === 30000, 'builder: budget_max 30000');

foreach (['東京', '歐洲', '北海道', '日本'] as $dest) {
    $a = TravelIntentLexicon::analyze($dest);
    hybrid_test_assert($a['is_tour_search'] === true, "lexicon destination only: {$dest}");
}

hybrid_test_finish('Travel intent lexicon');
