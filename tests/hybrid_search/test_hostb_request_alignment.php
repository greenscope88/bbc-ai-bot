<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

$sno = 'e1fd133c7e8e45a1';
$client = new TourSearchApiClient();
$mapper = new ApiQueryMapper();

$condition = GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget();
$internal = $mapper->toClientParams($condition, ['page' => 1, 'pageSize' => 30]);
$hostb = $mapper->toHostBParams($condition, ['include_sno' => $sno, 'page' => 1, 'pageSize' => 30]);
$url = $client->buildRequestUrlFromParams($sno, $hostb, 1, 30);

parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

hybrid_test_assert(($q['keyword'] ?? '') === ($internal['keyword'] ?? ''), 'alignment: keyword');
hybrid_test_assert(($q['TourDateS'] ?? '') === ($internal['dateFrom'] ?? ''), 'alignment: TourDateS = dateFrom');
hybrid_test_assert(($q['TourDateE'] ?? '') === ($internal['dateTo'] ?? ''), 'alignment: TourDateE = dateTo');
hybrid_test_assert(($q['AmountMax'] ?? '') === (string) ($internal['priceMax'] ?? ''), 'alignment: AmountMax = priceMax');

hybrid_test_finish('Host B request alignment');
