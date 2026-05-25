<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

$ref = new DateTimeImmutable('2026-05-25', new DateTimeZone('Asia/Taipei'));
$condition = (new HybridSearchConditionBuilder())->parse('六月底東京團', ['reference_date' => $ref]);
$mapper = new ApiQueryMapper();
$params = $mapper->toClientParams($condition, ['page' => 1, 'pageSize' => 30]);

hybrid_test_assert($params['keyword'] === '東京', 'api: keyword 東京');
hybrid_test_assert($params['dateFrom'] === '2026-06-21', 'api: dateFrom');
hybrid_test_assert($params['dateTo'] === '2026-06-30', 'api: dateTo');
hybrid_test_assert($params['destination'] === '東京', 'api: destination');
hybrid_test_assert(!isset($params['priceMax']) && !isset($params['budget_max']), 'api: no budget on allowlist');

$cEurope = (new HybridSearchConditionBuilder())->parse('歐洲', ['reference_date' => $ref]);
$pEurope = $mapper->toClientParams($cEurope);
hybrid_test_assert($pEurope['keyword'] === '歐洲', 'api: 歐洲 fallback keyword');
$meta = $mapper->metaOnly($cEurope);
hybrid_test_assert($meta['area_fallback'] === true, 'api meta: area_fallback');

hybrid_test_finish('ApiQueryMapper');
