<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HostBTourSearchParamMapper.php';

$ref = new DateTimeImmutable('2026-05-26', new DateTimeZone('Asia/Taipei'));
$builder = new HybridSearchConditionBuilder();
$mapper = new ApiQueryMapper();

$condition = $builder->parse('六月底高雄出發的東京親子團三萬以下', ['reference_date' => $ref]);
$internal = $mapper->toClientParams($condition, ['page' => 1, 'pageSize' => 10]);
$hostb = $mapper->toHostBParams($condition, ['page' => 1, 'pageSize' => 10]);
$audit = $mapper->hostbMappingAudit($internal);

hybrid_test_assert($internal['dateFrom'] === '2026-06-21', 'internal: dateFrom');
hybrid_test_assert($internal['priceMax'] === 30000, 'internal: priceMax');
hybrid_test_assert($internal['departureCity'] === '高雄', 'internal: departureCity');

hybrid_test_assert($hostb['TourDateS'] === '2026-06-21', 'hostb: TourDateS');
hybrid_test_assert($hostb['TourDateE'] === '2026-06-30', 'hostb: TourDateE');
hybrid_test_assert($hostb['AmountMax'] === 30000, 'hostb: AmountMax');
hybrid_test_assert($hostb['Departure'] === '高雄', 'hostb: Departure');
hybrid_test_assert(!isset($hostb['dateFrom']) && !isset($hostb['priceMax']), 'hostb: no legacy keys');

hybrid_test_assert($audit['dateFrom'] === '2026-06-21' && $audit['TourDateS'] === '2026-06-21', 'audit: date mapping');
hybrid_test_assert($audit['priceMax'] === 30000 && $audit['AmountMax'] === 30000, 'audit: price mapping');

$mapped = HostBTourSearchParamMapper::toHostBQueryParams([
    'keyword' => '東京',
    'dateFrom' => '2026-06-21',
    'priceMax' => 30000,
]);
hybrid_test_assert($mapped['TourDateS'] === '2026-06-21', 'mapper: direct internal array');

hybrid_test_finish('Host B param mapping');
