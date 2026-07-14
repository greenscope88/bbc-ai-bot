<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';

$mapper = new ApiQueryMapper();
$params = $mapper->toClientParams(GeminiDerivedSearchConditionFixtures::tokyoLateJuneTour(), ['page' => 1, 'pageSize' => 30]);

hybrid_test_assert($params['keyword'] === '東京', 'api: keyword 東京');
hybrid_test_assert($params['dateFrom'] === '2026-06-21', 'api: dateFrom');
hybrid_test_assert($params['dateTo'] === '2026-06-30', 'api: dateTo');
hybrid_test_assert($params['destination'] === '東京', 'api: destination');
hybrid_test_assert(!isset($params['priceMax']), 'api: no priceMax without budget');

$pBudget = $mapper->toClientParams(GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget());
hybrid_test_assert(($pBudget['priceMax'] ?? 0) === 30000, 'api: priceMax from budget_max');
$metaBudget = $mapper->metaOnly(GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget());
hybrid_test_assert($metaBudget['maps_to_priceMax'] === true, 'api meta: maps_to_priceMax');

$pEurope = $mapper->toClientParams(GeminiDerivedSearchConditionFixtures::europe());
hybrid_test_assert($pEurope['keyword'] === '歐洲', 'api: 歐洲 fallback keyword');
$meta = $mapper->metaOnly(GeminiDerivedSearchConditionFixtures::europe());
hybrid_test_assert($meta['area_fallback'] === true, 'api meta: area_fallback');

hybrid_test_finish('ApiQueryMapper');
