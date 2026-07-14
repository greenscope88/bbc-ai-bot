<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

$sno = 'e1fd133c7e8e45a1';
$fixtures = [
    '六月底東京三萬以下' => GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget(),
    '高雄出發東京' => GeminiDerivedSearchConditionFixtures::kaohsiungTokyoDeparture(),
    '歐洲團' => GeminiDerivedSearchConditionFixtures::europeTour(),
    '六月三十出發的東京團' => GeminiDerivedSearchConditionFixtures::tokyoJune30Departure(),
];

$mapper = new ApiQueryMapper();
$urlBuilder = new SearchUrlBuilder(false);
$client = new TourSearchApiClient();

foreach ($fixtures as $label => $condition) {
    $apiParams = $mapper->toClientParams($condition, ['include_sno' => $sno, 'page' => 1, 'pageSize' => 20]);
    $urlParams = $urlBuilder->searchParamsOnly($condition);

    hybrid_test_assert(($apiParams['keyword'] ?? '') === ($urlParams['keyword'] ?? ''), "consistency keyword: {$label}");

    $apiFrom = $apiParams['dateFrom'] ?? null;
    $urlFrom = $urlParams['dateFrom'] ?? null;
    hybrid_test_assert($apiFrom === $urlFrom, "consistency dateFrom: {$label}");

    $apiTo = $apiParams['dateTo'] ?? null;
    $urlTo = $urlParams['dateTo'] ?? null;
    hybrid_test_assert($apiTo === $urlTo, "consistency dateTo: {$label}");

    $requestUrl = $client->buildRequestUrlFromParams($sno, $apiParams, 1, 20);
    $reqQuery = [];
    parse_str((string) parse_url($requestUrl, PHP_URL_QUERY), $reqQuery);
    hybrid_test_assert(($reqQuery['keyword'] ?? '') === ($urlParams['keyword'] ?? ''), "consistency client URL keyword: {$label}");
    if ($apiFrom !== null) {
        $reqDate = $reqQuery['TourDateS'] ?? $reqQuery['dateFrom'] ?? '';
        hybrid_test_assert($reqDate === $apiFrom, "consistency client URL dateFrom: {$label}");
    }
}

hybrid_test_finish('Hybrid search URL consistency');
