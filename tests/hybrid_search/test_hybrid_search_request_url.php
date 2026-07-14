<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

$sno = 'e1fd133c7e8e45a1';
$client = new TourSearchApiClient();

$condition = GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget();
$mapper = new ApiQueryMapper();
$params = $mapper->toHostBParams($condition, ['include_sno' => $sno, 'page' => 1, 'pageSize' => 30]);
$url = $client->buildRequestUrlFromParams($sno, $params, 1, 30);

parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

hybrid_test_assert(strpos($url, 'bonusmee.com/api/gateway/tour/search.php') !== false, 'url: gateway path');
hybrid_test_assert(($q['keyword'] ?? '') === '東京', 'url: keyword');
hybrid_test_assert(($q['TourDateS'] ?? '') === '2026-06-21', 'url: TourDateS');
hybrid_test_assert(($q['TourDateE'] ?? '') === '2026-06-30', 'url: TourDateE');
hybrid_test_assert(($q['AmountMax'] ?? '') === '30000', 'url: AmountMax');
hybrid_test_assert(!isset($q['dateFrom']) && !isset($q['priceMax']), 'url: no legacy keys');

hybrid_test_finish('Hybrid search request URL');
