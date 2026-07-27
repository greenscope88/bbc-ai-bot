<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_api_client.php';

/**
 * Host B API date (YYYY-MM-DD) → Bonusmee listing date (YYYY/MM/DD). Test-only helper.
 */
function hybrid_api_date_to_listing_date(?string $apiDate): ?string
{
    if ($apiDate === null) {
        return null;
    }
    $trimmed = trim($apiDate);
    if ($trimmed === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $m) === 1) {
        return $m[1] . '/' . $m[2] . '/' . $m[3];
    }

    return $trimmed;
}

$sno = 'e1fd133c7e8e45a1';
$fixtures = [
    '六月底東京三萬以下' => GeminiDerivedSearchConditionFixtures::tokyoLateJuneBudget(),
    '高雄出發東京' => GeminiDerivedSearchConditionFixtures::kaohsiungTokyoDeparture(),
    '歐洲團' => GeminiDerivedSearchConditionFixtures::europeTour(),
    '六月三十出發的東京團' => GeminiDerivedSearchConditionFixtures::tokyoJune30Departure(),
    '釜山八月區間' => SearchCondition::empty('釜山八月區間')->with([
        'keyword' => '釜山',
        'destination' => ['釜山'],
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'search_keyword_tokens' => ['釜山'],
    ])->flag('bats_intent_mapped'),
];

$mapper = new ApiQueryMapper();
$urlBuilder = new SearchUrlBuilder(false);
$client = new TourSearchApiClient();

foreach ($fixtures as $label => $condition) {
    $apiParams = $mapper->toClientParams($condition, ['include_sno' => $sno, 'page' => 1, 'pageSize' => 20]);
    $listingParams = $urlBuilder->searchParamsOnly($condition);
    $listingUrl = $urlBuilder->build($sno, $condition);
    $listingUrlParams = [];
    parse_str((string) parse_url($listingUrl, PHP_URL_QUERY), $listingUrlParams);

    hybrid_test_assert(
        ($apiParams['keyword'] ?? '') === ($listingParams['keyword'] ?? ''),
        "consistency keyword: {$label}"
    );

    $apiFrom = $apiParams['dateFrom'] ?? null;
    $apiTo = $apiParams['dateTo'] ?? null;
    $listingFrom = $listingParams['departureDateS'] ?? null;
    $listingTo = $listingParams['departureDateE'] ?? null;

    hybrid_test_assert(!array_key_exists('dateFrom', $listingParams), "listing params: no dateFrom: {$label}");
    hybrid_test_assert(!array_key_exists('dateTo', $listingParams), "listing params: no dateTo: {$label}");
    hybrid_test_assert(!array_key_exists('dateFrom', $listingUrlParams), "listing URL: no dateFrom: {$label}");
    hybrid_test_assert(!array_key_exists('dateTo', $listingUrlParams), "listing URL: no dateTo: {$label}");

    if ($apiFrom !== null && $apiFrom !== '') {
        hybrid_test_assert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $apiFrom) === 1, "Host B dateFrom YYYY-MM-DD: {$label}");
        hybrid_test_assert($listingFrom === hybrid_api_date_to_listing_date($apiFrom), "semantic dateFrom→departureDateS: {$label}");
        hybrid_test_assert(($listingUrlParams['departureDateS'] ?? null) === $listingFrom, "listing URL departureDateS: {$label}");
        hybrid_test_assert(preg_match('/^\d{4}\/\d{2}\/\d{2}$/', (string) $listingFrom) === 1, "Bonusmee departureDateS YYYY/MM/DD: {$label}");
    } else {
        hybrid_test_assert($listingFrom === null, "no Host B dateFrom ⇒ omit departureDateS: {$label}");
        hybrid_test_assert(!array_key_exists('departureDateS', $listingUrlParams), "listing URL omit departureDateS: {$label}");
    }

    if ($apiTo !== null && $apiTo !== '') {
        hybrid_test_assert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $apiTo) === 1, "Host B dateTo YYYY-MM-DD: {$label}");
        hybrid_test_assert($listingTo === hybrid_api_date_to_listing_date($apiTo), "semantic dateTo→departureDateE: {$label}");
        hybrid_test_assert(($listingUrlParams['departureDateE'] ?? null) === $listingTo, "listing URL departureDateE: {$label}");
        hybrid_test_assert(preg_match('/^\d{4}\/\d{2}\/\d{2}$/', (string) $listingTo) === 1, "Bonusmee departureDateE YYYY/MM/DD: {$label}");
    } else {
        hybrid_test_assert($listingTo === null, "no Host B dateTo ⇒ omit departureDateE: {$label}");
        hybrid_test_assert(!array_key_exists('departureDateE', $listingUrlParams), "listing URL omit departureDateE: {$label}");
    }

    $requestUrl = $client->buildRequestUrlFromParams($sno, $apiParams, 1, 20);
    $reqQuery = [];
    parse_str((string) parse_url($requestUrl, PHP_URL_QUERY), $reqQuery);
    hybrid_test_assert(($reqQuery['keyword'] ?? '') === ($listingParams['keyword'] ?? ''), "consistency client URL keyword: {$label}");
    if ($apiFrom !== null && $apiFrom !== '') {
        $reqDate = $reqQuery['TourDateS'] ?? $reqQuery['dateFrom'] ?? '';
        hybrid_test_assert($reqDate === $apiFrom, "Host B client URL TourDateS = dateFrom: {$label}");
    }
}

hybrid_test_assert(
    ($fixtures['釜山八月區間'] instanceof SearchCondition),
    'frozen fixture present'
);
$frozenApi = $mapper->toClientParams($fixtures['釜山八月區間']);
$frozenListing = $urlBuilder->searchParamsOnly($fixtures['釜山八月區間']);
hybrid_test_assert(($frozenApi['dateFrom'] ?? '') === '2026-08-01', 'frozen Host B dateFrom');
hybrid_test_assert(($frozenApi['dateTo'] ?? '') === '2026-08-31', 'frozen Host B dateTo');
hybrid_test_assert(($frozenListing['departureDateS'] ?? '') === '2026/08/01', 'frozen Bonusmee departureDateS');
hybrid_test_assert(($frozenListing['departureDateE'] ?? '') === '2026/08/31', 'frozen Bonusmee departureDateE');
$frozenUrl = $urlBuilder->build($sno, $fixtures['釜山八月區間']);
hybrid_test_assert(
    strpos($frozenUrl, 'departureDateS=2026/08/01') !== false
    && strpos($frozenUrl, 'departureDateE=2026/08/31') !== false,
    'frozen raw literal slash dates'
);
hybrid_test_assert(strpos($frozenUrl, '%2F') === false && strpos($frozenUrl, '%2f') === false, 'frozen raw has no %2F');
hybrid_test_assert(strpos($frozenUrl, 'dateFrom=') === false && strpos($frozenUrl, 'dateTo=') === false, 'frozen raw no legacy date keys');
hybrid_test_assert(
    strpos($frozenUrl, 'keyword=%E9%87%9C%E5%B1%B1') !== false,
    'frozen keyword remains percent-encoded'
);
$frozenQs = (string) parse_url($frozenUrl, PHP_URL_QUERY);
hybrid_test_assert(
    $frozenQs === 'openExternalBrowser=1&sno=e1fd133c7e8e45a1&keyword=%E9%87%9C%E5%B1%B1&mode=0&departureDateS=2026/08/01&departureDateE=2026/08/31&UnCarousel=1&mcno=0',
    'frozen: fixed-param 8-key raw order'
);
hybrid_test_assert(strpos($frozenQs, 'fromDMDetailFlag') === false, 'frozen: no fromDMDetailFlag');
hybrid_test_assert(strpos($frozenQs, 'clearParam') === false, 'frozen: no clearParam');
hybrid_test_assert(strpos($frozenQs, 'mode=1') === false, 'frozen: no mode=1');
hybrid_test_assert(substr_count($frozenQs, 'UnCarousel=') === 1, 'frozen: UnCarousel once');

hybrid_test_finish('Hybrid search URL consistency');
