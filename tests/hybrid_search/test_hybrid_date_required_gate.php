<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

$gate = new HybridDateRequiredGate();

$c1 = GeminiDerivedSearchConditionFixtures::tokyoDestinationOnly();
hybrid_test_assert($gate->evaluate($c1)->requiresDateClarification(), 'case1 gate: 東京 requires clarification');
hybrid_test_assert($c1->getDateFrom() === null && $c1->getDateTo() === null, 'case1 parse: no dates');

$c2 = GeminiDerivedSearchConditionFixtures::tokyoTourDestinationOnly();
hybrid_test_assert($gate->evaluate($c2)->requiresDateClarification(), 'case2 gate: 東京行程 requires clarification');

$c3 = GeminiDerivedSearchConditionFixtures::recentTokyo();
hybrid_test_assert($c3->getDateFrom() === '2026-06-06' && $c3->getDateTo() === '2026-08-05', 'case3 parse: 近期東京 dates');
hybrid_test_assert($gate->evaluate($c3)->allowsSearch(), 'case3 gate: 近期東京 allows search');

$c3b = GeminiDerivedSearchConditionFixtures::recentOsaka();
hybrid_test_assert($c3b->getKeyword() === '大阪', 'case3b parse: 大阪近期 keyword');
hybrid_test_assert($c3b->getDateFrom() === '2026-06-06' && $c3b->getDateTo() === '2026-08-05', 'case3b parse: 大阪近期 dates');
hybrid_test_assert($gate->evaluate($c3b)->allowsSearch(), 'case3b gate: 大阪近期 allows search');

$c4 = GeminiDerivedSearchConditionFixtures::kaohsiungTokyoLateJune();
hybrid_test_assert($c4->getDateFrom() !== null && $c4->getDateTo() !== null, 'case4 parse: 高雄東京6月底 has dates');
hybrid_test_assert($gate->evaluate($c4)->allowsSearch(), 'case4 gate: 高雄東京6月底 allows search');

$clarify = HybridDateRequiredGate::buildClarificationContext($c1, '東京');
hybrid_test_assert(strpos($clarify, HybridDateRequiredGate::CLARIFICATION_MARKER) !== false, 'clarification context has marker');
hybrid_test_assert(strpos($clarify, '日期澄清') !== false, 'clarification context mentions 日期澄清');

hybrid_test_finish('HybridDateRequiredGate');
