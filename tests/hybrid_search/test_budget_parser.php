<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BudgetParser.php';

$parser = new BudgetParser();

$r1 = $parser->parse('三萬以下');
hybrid_test_assert($r1->getBudgetMax() === 30000 && $r1->getBudgetMin() === null, '三萬以下 max');

$r2 = $parser->parse('三萬元內');
hybrid_test_assert($r2->getBudgetMax() === 30000, '三萬元內');

$r3 = $parser->parse('五萬左右');
hybrid_test_assert($r3->getBudgetMin() !== null && $r3->getBudgetMax() !== null, '五萬左右 range');
hybrid_test_assert($r3->getBudgetMin() <= 50000 && $r3->getBudgetMax() >= 50000, '五萬左右 centers 5万');

$r4 = $parser->parse('兩萬到三萬');
hybrid_test_assert($r4->getBudgetMin() === 20000 && $r4->getBudgetMax() === 30000, '兩萬到三萬');

$r5 = $parser->parse('預算三萬');
hybrid_test_assert($r5->getBudgetMax() !== null || $r5->getBudgetMin() !== null, '預算三萬 parsed');

hybrid_test_finish('BudgetParser');
