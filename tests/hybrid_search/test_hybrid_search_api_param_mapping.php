<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

$mapper = new ApiQueryMapper();

$condition = GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget();
$arr = $condition->toArray();

hybrid_test_assert($arr['keyword'] === '東京', 'mapping: keyword 東京');
hybrid_test_assert($arr['date_from'] === '2026-06-21', 'mapping: date_from');
hybrid_test_assert($arr['date_to'] === '2026-06-30', 'mapping: date_to');
hybrid_test_assert($arr['budget_max'] === 30000, 'mapping: budget_max');

$params = $mapper->toClientParams($condition, ['page' => 1, 'pageSize' => 30]);
hybrid_test_assert($params['keyword'] === '東京', 'mapping: api keyword');
hybrid_test_assert($params['dateFrom'] === '2026-06-21', 'mapping: dateFrom');
hybrid_test_assert($params['dateTo'] === '2026-06-30', 'mapping: dateTo');
hybrid_test_assert($params['priceMax'] === 30000, 'mapping: priceMax');

$params50k = $mapper->toClientParams(
    GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget50k(),
    ['page' => 1, 'pageSize' => 30]
);
hybrid_test_assert($params50k['priceMax'] === 50000, 'mapping: 五萬 priceMax');

$paramsTokyo = $mapper->toClientParams(
    GeminiDerivedSearchConditionFixtures::tokyoDestinationOnly(),
    ['page' => 1, 'pageSize' => 30]
);
hybrid_test_assert(!isset($paramsTokyo['dateFrom']), 'mapping: 東京 no dateFrom');
hybrid_test_assert(!isset($paramsTokyo['priceMax']), 'mapping: 東京 no priceMax');

hybrid_test_finish('Hybrid search API param mapping');
