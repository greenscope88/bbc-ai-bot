<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

$prodRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_gateway' . DIRECTORY_SEPARATOR . 'production';
require_once $prodRoot . DIRECTORY_SEPARATOR . 'ServiceRegistry.php';
require_once $prodRoot . DIRECTORY_SEPARATOR . 'TourSearchRequestBuilder.php';

$ref = new DateTimeImmutable('2026-05-26', new DateTimeZone('Asia/Taipei'));
$sno = 'e1fd133c7e8e45a1';
$condition = (new HybridSearchConditionBuilder())->parse('六月底東京三萬以下', ['reference_date' => $ref]);
$internal = (new ApiQueryMapper())->toClientParams($condition, ['include_sno' => $sno, 'page' => 1, 'pageSize' => 30]);
$hostb = (new ApiQueryMapper())->toHostBParams($condition, ['include_sno' => $sno, 'page' => 1, 'pageSize' => 30]);

$url = (new TourSearchApiClient())->buildRequestUrlFromParams($sno, $hostb, 1, 30);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

hybrid_test_assert(strpos($url, 'AmountMax=30000') !== false, 'url: AmountMax');
hybrid_test_assert(strpos($url, 'TourDateS=2026-06-21') !== false, 'url: TourDateS');
hybrid_test_assert(strpos($url, 'TourDateE=2026-06-30') !== false, 'url: TourDateE');
hybrid_test_assert(!isset($q['priceMax']) && !isset($q['dateFrom']), 'url: no legacy keys');

$tenant = [
    'sno' => $sno,
    'depID' => '888',
    'storeNo' => '6290',
    'store_uid' => '6290',
    'provider_id_no' => '102',
];
$built = TourSearchRequestBuilder::build($internal, $tenant);
$bq = $built['query'];

hybrid_test_assert(($bq['AmountMax'] ?? '') === '30000', 'builder: AmountMax from internal clientParams');
hybrid_test_assert(($bq['TourDateS'] ?? '') === '2026-06-21', 'builder: TourDateS');
hybrid_test_assert(!isset($bq['priceMax']), 'builder: strips legacy priceMax');

hybrid_test_finish('Host B request alignment');
