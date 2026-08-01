<?php
declare(strict_types=1);

/**
 * Phase 9-B-3: ProductSourceUrlPublisher + MockShortUrlProvider (no bs_ShortUrl).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceUrlPublisher.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MockShortUrlProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MultiSourceSearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceRegistry.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceDefinition.php';

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
$registry = ProductSourceRegistry::forTenant('5f99b8d665e8444d');
$builder = new MultiSourceSearchUrlBuilder();
$publisher = new ProductSourceUrlPublisher(new MockShortUrlProvider());
$sno = '5f99b8d665e8444d';
$keyword = '東京';
$category = 'group_tour';

// 1. bbcshops — short_search + short_detail
$bbcDef = $registry->getCatalogSource('bbcshops');
test_assert($bbcDef !== null && $bbcDef->isShortUrlEnabled(), 'bbcshops short_url_enabled in catalog');
$bbcLong = $builder->buildForSource('bbcshops', $keyword, $category, $sno, $registry, ['tour_seq_no' => '99']);
$bbcPub = $publisher->publish($bbcLong, $bbcDef);

test_assert($bbcPub->getLongSearchUrl() !== null, 'bbcshops long search');
test_assert($bbcPub->getShortSearchUrl() !== null, 'bbcshops short search');
test_assert($bbcPub->isSearchShortened(), 'bbcshops search shortened');
test_assert(strpos((string) $bbcPub->getShortSearchUrl(), 'https://bbcshops.com/') === 0, 'bbcshops short host');
test_assert($bbcPub->getLongSearchUrl() !== $bbcPub->getShortSearchUrl(), 'bbcshops long != short search');

test_assert($bbcPub->getLongDetailUrl() !== null, 'bbcshops long detail');
test_assert($bbcPub->getShortDetailUrl() !== null, 'bbcshops short detail');
test_assert($bbcPub->isDetailShortened(), 'bbcshops detail shortened');
test_assert($bbcPub->getDomainNamespace() === 'bbcshops', 'bbcshops domain_namespace');

// 2. grp — catalog has short_url_enabled true
$grpDef = $registry->getCatalogSource('grp');
test_assert($grpDef !== null && $grpDef->isShortUrlEnabled(), 'grp short_url_enabled');
$grpLong = $builder->buildSearchUrl('grp', $keyword, $category, $sno, $registry);
$grpPub = $publisher->publish($grpLong, $grpDef);
test_assert($grpPub->isSearchShortened(), 'grp produces short search when enabled');
test_assert(strpos((string) $grpPub->getShortSearchUrl(), 'bbcshops.com/') !== false, 'grp uses catalog short_url_domain');
test_assert($grpPub->getShortDetailUrl() === null, 'grp no detail url');

// 3. short_url_enabled = false — passthrough long only
$disabledDef = new ProductSourceDefinition(
    'stub_off',
    'Stub Off',
    'external',
    'StubProductSourceAdapter',
    'stub_search_v1',
    ['group_tour'],
    100,
    false,
    'bbcshops.com',
    'bbcshops',
    true,
    true,
    false,
    false,
    false,
    true
);
$stubLong = new ProductSourceUrlResult('stub_off', 'group_tour', 'https://example.com/search?q=1', 'https://example.com/detail?id=1');
$stubPub = $publisher->publish($stubLong, $disabledDef);
test_assert($stubPub->getShortSearchUrl() === $stubPub->getLongSearchUrl(), 'disabled: short search equals long');
test_assert($stubPub->getShortDetailUrl() === $stubPub->getLongDetailUrl(), 'disabled: short detail equals long');
test_assert(!$stubPub->isSearchShortened(), 'disabled: not shortened');

// 4. domain_namespace — different namespace → different mock code
$nsA = new ProductSourceDefinition(
    'ns_a',
    'NS A',
    'external',
    'StubProductSourceAdapter',
    'stub_search_v1',
    ['group_tour'],
    1,
    true,
    '598go.com',
    '598go',
    true,
    false,
    false,
    false,
    false,
    true
);
$nsB = new ProductSourceDefinition(
    'ns_b',
    'NS B',
    'external',
    'StubProductSourceAdapter',
    'stub_search_v1',
    ['group_tour'],
    2,
    true,
    'bonusmee.com',
    'bonusmee',
    true,
    false,
    false,
    false,
    false,
    true
);
$longUrl = 'https://example.com/same-path';
$pubA = $publisher->publish(new ProductSourceUrlResult('ns_a', 'group_tour', $longUrl, null), $nsA);
$pubB = $publisher->publish(new ProductSourceUrlResult('ns_b', 'group_tour', $longUrl, null), $nsB);
test_assert(strpos((string) $pubA->getShortSearchUrl(), '598go.com/') !== false, 'namespace 598go domain');
test_assert(strpos((string) $pubB->getShortSearchUrl(), 'bonusmee.com/') !== false, 'namespace bonusmee domain');
test_assert($pubA->getShortSearchUrl() !== $pubB->getShortSearchUrl(), 'different namespace yields different short url');
test_assert($pubA->getDomainNamespace() === '598go', 'published carries domain_namespace A');
test_assert($pubB->getDomainNamespace() === 'bonusmee', 'published carries domain_namespace B');

// batch publishMany
$longList = $builder->buildAllForTenant($registry, $keyword, $category);
$defs = [];
foreach ($registry->getEnabledSourcesByCategory($category) as $def) {
    $defs[$def->getSourceId()] = $def;
}
$published = $publisher->publishMany($longList, $defs);
test_assert(count($published) === 3, 'publishMany count for travel_b');
foreach ($published as $p) {
    test_assert($p->getShortSearchUrl() !== null && $p->isSearchShortened(), 'each enabled source shortened: ' . $p->getSourceId());
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_product_source_url_publisher (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
