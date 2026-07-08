<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_test_helpers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';

$ref = new DateTimeImmutable('2026-05-25', new DateTimeZone('Asia/Taipei'));
$sno = 'e1fd133c7e8e45a1';
$condition = (new HybridSearchConditionBuilder())->parse('六月底東京團', ['reference_date' => $ref]);

$mapper = new ApiQueryMapper();
$apiParams = $mapper->toClientParams($condition);

$urlBuilder = new SearchUrlBuilder(false);
$url = $urlBuilder->build($sno, $condition);
$urlParams = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $urlParams);

hybrid_test_assert(strpos($url, 'cloud_store_tourdate.php') !== false, 'url: base path');
hybrid_test_assert(($urlParams['keyword'] ?? '') === $apiParams['keyword'] || ($urlParams['keyword'] ?? '') !== '', 'url keyword present');
hybrid_test_assert(($urlParams['dateFrom'] ?? '') === ($apiParams['dateFrom'] ?? ''), 'url dateFrom = api');
hybrid_test_assert(($urlParams['dateTo'] ?? '') === ($apiParams['dateTo'] ?? ''), 'url dateTo = api');
hybrid_test_assert(!array_key_exists('destination', $urlParams), 'url: bonusmee storefront omits destination param');

$searchOnly = $urlBuilder->searchParamsOnly($condition);
hybrid_test_assert($searchOnly['keyword'] === $apiParams['keyword'], 'searchParamsOnly keyword match');
hybrid_test_assert($searchOnly['dateFrom'] === $apiParams['dateFrom'], 'searchParamsOnly dateFrom match');

$listingUrl = $urlBuilder->buildStorefrontListingUrl($sno);
$listingParams = [];
parse_str((string) parse_url($listingUrl, PHP_URL_QUERY), $listingParams);
hybrid_test_assert(strpos($listingUrl, 'cloud_store_tourdate.php') !== false, 'listing url: base path');
hybrid_test_assert(($listingParams['openExternalBrowser'] ?? '') === '1', 'listing url: openExternalBrowser');
hybrid_test_assert(($listingParams['clearParam'] ?? '') === 'Y', 'listing url: clearParam');
hybrid_test_assert(($listingParams['sno'] ?? '') === $sno, 'listing url: sno');
hybrid_test_assert(!array_key_exists('keyword', $listingParams), 'listing url: no keyword');

hybrid_test_finish('SearchUrlBuilder');
