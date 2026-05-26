<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

$ref = new DateTimeImmutable('2026-05-26', new DateTimeZone('Asia/Taipei'));
$builder = new HybridSearchConditionBuilder();
$mapper = new ApiQueryMapper();

$condition = $builder->parse('六月底東京三萬以下', ['reference_date' => $ref]);
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
    $builder->parse('六月底東京五萬以下', ['reference_date' => $ref]),
    ['page' => 1, 'pageSize' => 30]
);
hybrid_test_assert($params50k['priceMax'] === 50000, 'mapping: 五萬 priceMax');

$paramsTokyo = $mapper->toClientParams(
    $builder->parse('東京', ['reference_date' => $ref]),
    ['page' => 1, 'pageSize' => 30]
);
hybrid_test_assert(!isset($paramsTokyo['dateFrom']), 'mapping: 東京 no dateFrom');
hybrid_test_assert(!isset($paramsTokyo['priceMax']), 'mapping: 東京 no priceMax');

hybrid_test_finish('Hybrid search API param mapping');
