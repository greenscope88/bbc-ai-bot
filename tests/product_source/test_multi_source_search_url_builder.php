<?php
declare(strict_types=1);

/**
 * Phase 9-B-2: MultiSourceSearchUrlBuilder (long URLs only)
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceRegistry.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$root = dirname(__DIR__, 2);
$catalogPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'product_source_catalog.sample.json';
$tenantPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'sample' . DIRECTORY_SEPARATOR . 'travel_b_product_sources.sample.json';

$registry = ProductSourceRegistry::fromLocalFiles($catalogPath, $tenantPath);
$builder = new MultiSourceSearchUrlBuilder();
$sno = '5f99b8d665e8444d';
$keyword = '東京';
$category = 'group_tour';

// bbcshops
$bbc = $builder->buildSearchUrl('bbcshops', $keyword, $category, $sno, $registry);
test_assert($bbc->getSearchUrl() !== null, 'bbcshops has search_url');
$bbcUrl = (string) $bbc->getSearchUrl();
test_assert(strpos($bbcUrl, 'bonusmee.com/view/cloud/cloud_store_tourdate.php') !== false, 'bbcshops bonusmee path');
test_assert(strpos($bbcUrl, 'openExternalBrowser=1') !== false, 'bbcshops openExternalBrowser');
test_assert(strpos($bbcUrl, 'sno=' . $sno) !== false, 'bbcshops sno');
test_assert(strpos($bbcUrl, 'keyword=') !== false, 'bbcshops keyword param');

$bbcFull = $builder->buildForSource('bbcshops', $keyword, $category, $sno, $registry, ['tour_seq_no' => '12345']);
test_assert($bbcFull->getDetailUrl() !== null, 'bbcshops detail_url when supports_detail');
test_assert(strpos((string) $bbcFull->getDetailUrl(), 'cloud_store_tourdetail.php') !== false, 'bbcshops detail path');

// grp
$grp = $builder->buildSearchUrl('grp', $keyword, $category, $sno, $registry);
test_assert($grp->getSearchUrl() !== null, 'grp has search_url');
$grpUrl = (string) $grp->getSearchUrl();
test_assert(strpos($grpUrl, 'dayitravel.grp.com.tw/Exhibition.aspx') !== false, 'grp exhibition path');
test_assert(strpos($grpUrl, 'area_no=TOKYO') !== false, 'grp area_no from keyword map');

$grpDetail = $builder->buildDetailUrl('grp', $keyword, $category, $sno, $registry);
test_assert($grpDetail->getDetailUrl() === null, 'grp no detail_url');

// bbctravel
$btc = $builder->buildSearchUrl('bbctravel', $keyword, $category, $sno, $registry);
test_assert($btc->getSearchUrl() !== null, 'bbctravel has search_url');
$btcUrl = (string) $btc->getSearchUrl();
test_assert(strpos($btcUrl, 'dayitravel.bbctravel.com.tw/searchlist/tpe/tpe') !== false, 'bbctravel searchlist path');

// tourcenter
$tc = $builder->buildSearchUrl('tourcenter', $keyword, $category, $sno, $registry);
test_assert($tc->getSearchUrl() !== null, 'tourcenter has search_url');
$tcUrl = (string) $tc->getSearchUrl();
test_assert(
    strpos($tcUrl, 'dayitravel.tourcenter.com.tw/category/zh-tw/travel/group-tour-travel') !== false,
    'tourcenter category slug path'
);

// buildAllForTenant (travel_b: bbcshops, grp, tourcenter — not bbctravel)
$all = $builder->buildAllForTenant($registry, $keyword, $category);
test_assert(count($all) === 3, 'travel_b enabled sources for group_tour');
$allIds = array_map(static function (ProductSourceUrlResult $r): string {
    return $r->getSourceId();
}, $all);
test_assert(in_array('bbcshops', $allIds, true), 'buildAll includes bbcshops');
test_assert(in_array('grp', $allIds, true), 'buildAll includes grp');
test_assert(in_array('tourcenter', $allIds, true), 'buildAll includes tourcenter');

foreach ($all as $result) {
    test_assert($result->hasSearchUrl(), 'each enabled source has search_url: ' . $result->getSourceId());
}

// no short domain in output (long URL only phase)
foreach ([$bbcUrl, $grpUrl, $btcUrl, $tcUrl] as $url) {
    test_assert(strpos($url, 'bbcshops.com/') === false, 'no short url host in long URL phase: ' . $url);
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_multi_source_search_url_builder (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
