<?php
declare(strict_types=1);

/**
 * Phase 1B-A: "詳細內容" short URLs (SHORT_URL_ITEM_LINKS_ENABLED).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'legacy_storefront_crypto.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'short_url_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_search_service.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

function env_snapshot(): array
{
    return [
        'SHORT_URL_ENABLED' => getenv('SHORT_URL_ENABLED'),
        'SHORT_URL_ITEM_LINKS_ENABLED' => getenv('SHORT_URL_ITEM_LINKS_ENABLED'),
        'SHORT_URL_SCHEDULE_LINKS_ENABLED' => getenv('SHORT_URL_SCHEDULE_LINKS_ENABLED'),
        'SHORT_URL_PUBLIC_BASE' => getenv('SHORT_URL_PUBLIC_BASE'),
    ];
}

/**
 * @param array<string, string|false> $snapshot
 */
function env_restore(array $snapshot): void
{
    foreach ($snapshot as $key => $value) {
        if ($value === false) {
            putenv($key);
            continue;
        }
        putenv($key . '=' . $value);
    }
}

$detailCrypto = new LegacyStorefrontCrypto('testkey8');
$detailBuilder = new TourDetailUrlBuilder($detailCrypto);

$withLinks = [
    'success' => true,
    'pagination' => ['total' => 1],
    'items' => [
        [
            'title' => '連結測試行程',
            'tourDate' => '2026-06-15',
            'price' => 19900,
            'departureStr' => '台北',
            'couponNo' => 88001,
            'tourSeqNo' => 99001,
            'schLink' => 'https://drive.google.com/example-itinerary',
        ],
    ],
    'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=link',
];

$buildOptions = [
    'storeNo' => 6290,
    'detailUrlBuilder' => $detailBuilder,
    'maxItems' => 5,
    'includeInstructions' => false,
];

$snap = env_snapshot();
putenv('SHORT_URL_PUBLIC_BASE=https://bbcshops.com/');

// 1. ITEM_LINKS disabled → tourdate_dm long URL in context
putenv('SHORT_URL_ITEM_LINKS_ENABLED=0');
putenv('SHORT_URL_ENABLED=0');
$builder = new GeminiTourContextBuilder();
$textOff = $builder->build($withLinks, $buildOptions);
test_assert(strpos($textOff, GeminiTourContextBuilder::DETAIL_URL_LABEL) !== false, '1: detail label present');
test_assert(strpos($textOff, 'tourdate_dm.php') !== false, '1: detail remains tourdate_dm long URL');
test_assert(strpos($textOff, 'https://bbcshops.com/') === false || strpos($textOff, '詳細內容：https://bbcshops.com/') === false, '1: detail not bbcshops short when flag off');

// 2. ITEM_LINKS enabled → bbcshops short (or fail-open long)
putenv('SHORT_URL_ITEM_LINKS_ENABLED=1');
$textOn = $builder->build($withLinks, $buildOptions);
test_assert(strpos($textOn, GeminiTourContextBuilder::DETAIL_URL_LABEL) !== false, '2: detail label present');
$detailLinePos = strpos($textOn, GeminiTourContextBuilder::DETAIL_URL_LABEL);
$detailSlice = $detailLinePos !== false ? substr($textOn, $detailLinePos, 200) : '';
$detailIsShort = strpos($detailSlice, 'https://bbcshops.com/') !== false
    && strpos($detailSlice, 'tourdate_dm.php') === false;
$detailIsLong = strpos($detailSlice, 'tourdate_dm.php') !== false;
test_assert($detailIsShort || $detailIsLong, '2: detail is bbcshops short OR fail-open long');
if ($detailIsShort) {
    fwrite(STDOUT, "INFO: detail short URL in context\n");
}

// 3. 行程表 unchanged when SCHEDULE flag off (Phase 1B-B isolated)
putenv('SHORT_URL_SCHEDULE_LINKS_ENABLED=0');
$textScheduleOff = $builder->build($withLinks, $buildOptions);
test_assert(strpos($textScheduleOff, '行程表：https://drive.google.com/example-itinerary') !== false, '3: schedule URL stays long when SCHEDULE off');
test_assert(
    preg_match('/行程表：\s*https:\/\/bbcshops\.com\//u', $textScheduleOff) !== 1,
    '3: schedule not shortened to bbcshops when SCHEDULE off'
);

// 4. Phase 1A isolation: search_url uses SHORT_URL_ENABLED only
putenv('SHORT_URL_ENABLED=1');
putenv('SHORT_URL_ITEM_LINKS_ENABLED=0');
$searchUrl = TourSearchService::buildSearchUrl('e1fd133c7e8e45a1', '東京');
$searchIsShort = (bool) preg_match('#^https://bbcshops\.com/[A-Za-z0-9]+$#', $searchUrl);
$searchIsLong = strpos($searchUrl, 'cloud_store_tourdate.php') !== false;
test_assert($searchIsShort || $searchIsLong, '4: search_url still governed by SHORT_URL_ENABLED');
test_assert(strpos($searchUrl, 'tourdate_dm.php') === false, '4: search_url is not detail path');

$textSearchOnly = $builder->build($withLinks, $buildOptions);
test_assert(strpos($textSearchOnly, 'tourdate_dm.php') !== false, '4: detail long when ITEM off even if SEARCH on');

// 5. ShortUrlService flag separation (constructor overrides)
$longDetail = 'https://bonusmee.com/view/cloud/tourdate_dm.php?mode=1&sno=a&cid=b&trsno=1';
$svcSearchOnly = new ShortUrlService(true, 'https://bbcshops.com/', false);
$svcItemOnly = new ShortUrlService(false, 'https://bbcshops.com/', true);
test_assert(strpos($svcSearchOnly->toPublicShortUrlForItemLink($longDetail), 'tourdate_dm.php') !== false, '5: item method off → long');
test_assert(strpos($svcSearchOnly->toPublicShortUrl($longDetail), 'bbcshops.com') !== false || strpos($svcSearchOnly->toPublicShortUrl($longDetail), 'tourdate_dm') !== false, '5: search method independent');
test_assert(strpos($svcItemOnly->toPublicShortUrl($longDetail), 'tourdate_dm.php') !== false, '5: search method off → long');
$itemResult = $svcItemOnly->toPublicShortUrlForItemLink($longDetail);
test_assert(
    preg_match('#^https://bbcshops\.com/[A-Za-z0-9]+$#', $itemResult) === 1
    || strpos($itemResult, 'tourdate_dm.php') !== false,
    '5: item method on → short or fail-open'
);

env_restore($snap);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "\nAll Phase 1B-A detail tests passed.\n");
exit(0);
