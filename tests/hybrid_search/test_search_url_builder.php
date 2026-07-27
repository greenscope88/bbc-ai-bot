<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

$sno = 'e1fd133c7e8e45a1';
$condition = GeminiDerivedSearchConditionFixtures::tokyoLateJuneTour();

$mapper = new ApiQueryMapper();
$apiParams = $mapper->toClientParams($condition);

$urlBuilder = new SearchUrlBuilder(false);
$url = $urlBuilder->build($sno, $condition);
$urlParams = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $urlParams);

hybrid_test_assert(strpos($url, 'cloud_store_tourdate.php') !== false, 'url: base path');
hybrid_test_assert(($urlParams['keyword'] ?? '') === $apiParams['keyword'] || ($urlParams['keyword'] ?? '') !== '', 'url keyword present');
hybrid_test_assert(!array_key_exists('destination', $urlParams), 'url: bonusmee storefront omits destination param');
hybrid_test_assert(!array_key_exists('dateFrom', $urlParams), 'url: no legacy dateFrom');
hybrid_test_assert(!array_key_exists('dateTo', $urlParams), 'url: no legacy dateTo');

$fixtureSno = '5f99b8d665e8444d';
$dated = SearchCondition::empty('dated listing')->with([
    'keyword' => '釜山',
    'destination' => ['釜山'],
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
    'search_keyword_tokens' => ['釜山'],
])->flag('bats_intent_mapped');
$datedUrl = $urlBuilder->build($fixtureSno, $dated);
$datedParams = [];
parse_str((string) parse_url($datedUrl, PHP_URL_QUERY), $datedParams);
$expectedFixtureQs = 'openExternalBrowser=1&sno=5f99b8d665e8444d&keyword=%E9%87%9C%E5%B1%B1&mode=0&departureDateS=2026/08/01&departureDateE=2026/08/31&UnCarousel=1&mcno=0';
hybrid_test_assert(
    (string) parse_url($datedUrl, PHP_URL_QUERY) === $expectedFixtureQs,
    'fixture: frozen 8-key raw query order'
);
hybrid_test_assert(($datedParams['mode'] ?? '') === '0', 'dated: mode=0');
hybrid_test_assert(($datedParams['UnCarousel'] ?? '') === '1', 'dated: UnCarousel=1');
hybrid_test_assert(($datedParams['mcno'] ?? '') === '0', 'dated: mcno=0');
hybrid_test_assert(!array_key_exists('fromDMDetailFlag', $datedParams), 'dated: no fromDMDetailFlag');
hybrid_test_assert(!array_key_exists('clearParam', $datedParams), 'dated: no clearParam');
hybrid_test_assert(($datedParams['mode'] ?? '') !== '1', 'dated: not mode=1');
hybrid_test_assert(substr_count($datedUrl, 'UnCarousel=') === 1, 'dated: UnCarousel once');
hybrid_test_assert(($datedParams['departureDateS'] ?? '') === '2026/08/01', 'dated: departureDateS slash');
hybrid_test_assert(($datedParams['departureDateE'] ?? '') === '2026/08/31', 'dated: departureDateE slash');
hybrid_test_assert(
    strpos($datedUrl, 'departureDateS=2026/08/01') !== false
    && strpos($datedUrl, 'departureDateE=2026/08/31') !== false,
    'dated: raw literal slash dates'
);
hybrid_test_assert(strpos($datedUrl, '%2F') === false && strpos($datedUrl, '%2f') === false, 'dated: raw has no %2F');
hybrid_test_assert(strpos($datedUrl, 'dateFrom=') === false && strpos($datedUrl, 'dateTo=') === false, 'dated: raw no legacy date keys');
hybrid_test_assert(strpos($datedUrl, '2026-08-01') === false && strpos($datedUrl, '2026-08-31') === false, 'dated: raw no dash dates');
hybrid_test_assert(
    strpos($datedUrl, 'keyword=%E9%87%9C%E5%B1%B1') !== false,
    'dated: keyword remains percent-encoded'
);
hybrid_test_assert(strpos($datedUrl, 'sno=' . $fixtureSno) !== false, 'dated: sno dynamic');
hybrid_test_assert(!array_key_exists('dateFrom', $datedParams), 'dated: no dateFrom');
hybrid_test_assert(!array_key_exists('dateTo', $datedParams), 'dated: no dateTo');
hybrid_test_assert(($datedParams['keyword'] ?? '') !== '', 'dated: keyword preserved');

$withCity = SearchCondition::empty('with departure city')->with([
    'keyword' => '釜山',
    'destination' => ['釜山'],
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
    'departure_city' => 'TPE',
    'search_keyword_tokens' => ['釜山'],
])->flag('bats_intent_mapped');
$withCityUrl = $urlBuilder->build($fixtureSno, $withCity);
$withCityQs = (string) parse_url($withCityUrl, PHP_URL_QUERY);
hybrid_test_assert(strpos($withCityQs, 'departureCity=TPE') !== false, 'city: departureCity present once');
hybrid_test_assert(substr_count($withCityQs, 'departureCity=') === 1, 'city: departureCity once');
hybrid_test_assert(
    preg_match(
        '#departureDateE=2026/08/31&departureCity=TPE&UnCarousel=1&mcno=0$#',
        $withCityQs
    ) === 1,
    'city: after dates, before UnCarousel/mcno'
);
hybrid_test_assert(strpos($withCityQs, 'mode=0') !== false, 'city: mode=0 preserved');
hybrid_test_assert(strpos($withCityQs, 'fromDMDetailFlag') === false, 'city: no fromDMDetailFlag');
hybrid_test_assert(strpos($withCityQs, 'clearParam') === false, 'city: no clearParam');

// Generic non-fixture window also uses literal YYYY/MM/DD in raw URL
$generic = SearchCondition::empty('generic window')->with([
    'keyword' => '大阪',
    'destination' => ['大阪'],
    'date_from' => '2026-09-10',
    'date_to' => '2026-09-20',
    'search_keyword_tokens' => ['大阪'],
])->flag('bats_intent_mapped');
$genericUrl = $urlBuilder->build($sno, $generic);
hybrid_test_assert(
    strpos($genericUrl, 'departureDateS=2026/09/10') !== false
    && strpos($genericUrl, 'departureDateE=2026/09/20') !== false,
    'generic: raw literal slash dates'
);
hybrid_test_assert(strpos($genericUrl, '%2F') === false && strpos($genericUrl, '%2f') === false, 'generic: raw has no %2F');

$searchOnly = $urlBuilder->searchParamsOnly($dated);
hybrid_test_assert(($searchOnly['departureDateS'] ?? '') === '2026/08/01', 'searchParamsOnly departureDateS');
hybrid_test_assert(($searchOnly['departureDateE'] ?? '') === '2026/08/31', 'searchParamsOnly departureDateE');
hybrid_test_assert(!array_key_exists('dateFrom', $searchOnly), 'searchParamsOnly: no dateFrom');
hybrid_test_assert(!array_key_exists('dateTo', $searchOnly), 'searchParamsOnly: no dateTo');

$noDate = SearchCondition::empty('no date')->with([
    'keyword' => '釜山',
    'destination' => ['釜山'],
    'search_keyword_tokens' => ['釜山'],
])->flag('bats_intent_mapped');
$noDateUrl = $urlBuilder->build($sno, $noDate);
$noDateParams = [];
parse_str((string) parse_url($noDateUrl, PHP_URL_QUERY), $noDateParams);
hybrid_test_assert(!array_key_exists('departureDateS', $noDateParams), 'no-date: omit departureDateS');
hybrid_test_assert(!array_key_exists('departureDateE', $noDateParams), 'no-date: omit departureDateE');
hybrid_test_assert(!array_key_exists('dateFrom', $noDateParams), 'no-date: no dateFrom');
hybrid_test_assert(!array_key_exists('dateTo', $noDateParams), 'no-date: no dateTo');
$noDateOnly = $urlBuilder->searchParamsOnly($noDate);
hybrid_test_assert(!array_key_exists('departureDateS', $noDateOnly), 'searchParamsOnly no-date: omit S');
hybrid_test_assert(!array_key_exists('departureDateE', $noDateOnly), 'searchParamsOnly no-date: omit E');

$listingUrl = $urlBuilder->buildStorefrontListingUrl($sno);
$listingParams = [];
parse_str((string) parse_url($listingUrl, PHP_URL_QUERY), $listingParams);
hybrid_test_assert(strpos($listingUrl, 'cloud_store_tourdate.php') !== false, 'listing url: base path');
hybrid_test_assert(($listingParams['openExternalBrowser'] ?? '') === '1', 'listing url: openExternalBrowser');
hybrid_test_assert(($listingParams['clearParam'] ?? '') === 'Y', 'listing url: clearParam');
hybrid_test_assert(($listingParams['sno'] ?? '') === $sno, 'listing url: sno');
hybrid_test_assert(!array_key_exists('keyword', $listingParams), 'listing url: no keyword');

hybrid_test_finish('SearchUrlBuilder');
