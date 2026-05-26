<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

$ref = new DateTimeImmutable('2026-05-25', new DateTimeZone('Asia/Taipei'));
$sno = 'e1fd133c7e8e45a1';
$sentences = [
    '六月底東京三萬以下',
    '高雄出發東京',
    '歐洲團',
    '日本親子團',
    '六月三十出發的東京團',
];

$builder = new HybridSearchConditionBuilder();
$mapper = new ApiQueryMapper();
$urlBuilder = new SearchUrlBuilder(false);
$client = new TourSearchApiClient();

foreach ($sentences as $sentence) {
    $condition = $builder->parse($sentence, ['reference_date' => $ref]);
    $apiParams = $mapper->toClientParams($condition, ['include_sno' => $sno, 'page' => 1, 'pageSize' => 20]);
    $urlParams = $urlBuilder->searchParamsOnly($condition);

    hybrid_test_assert(($apiParams['keyword'] ?? '') === ($urlParams['keyword'] ?? ''), "consistency keyword: {$sentence}");

    $apiFrom = $apiParams['dateFrom'] ?? null;
    $urlFrom = $urlParams['dateFrom'] ?? null;
    hybrid_test_assert($apiFrom === $urlFrom, "consistency dateFrom: {$sentence}");

    $apiTo = $apiParams['dateTo'] ?? null;
    $urlTo = $urlParams['dateTo'] ?? null;
    hybrid_test_assert($apiTo === $urlTo, "consistency dateTo: {$sentence}");

    $requestUrl = $client->buildRequestUrlFromParams($sno, $apiParams, 1, 20);
    $reqQuery = [];
    parse_str((string) parse_url($requestUrl, PHP_URL_QUERY), $reqQuery);
    hybrid_test_assert(($reqQuery['keyword'] ?? '') === ($urlParams['keyword'] ?? ''), "consistency client URL keyword: {$sentence}");
    if ($apiFrom !== null) {
        hybrid_test_assert(($reqQuery['dateFrom'] ?? '') === $apiFrom, "consistency client URL dateFrom: {$sentence}");
    }
}

hybrid_test_finish('Hybrid search URL consistency');
