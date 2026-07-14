<?php
declare(strict_types=1);

/**
 * grp.com.tw Golden Reference: ClassifyProduct.aspx search URL (Phase 2C).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';

$failures = 0;

function grp_test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

/**
 * @return array{
 *   platforms: array<string, array<string, mixed>>,
 *   instances: array<string, array<string, mixed>>,
 *   templates: array<string, array<string, mixed>>
 * }
 */
function grp_load_travel_b_registry(): array
{
    $path = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'config'
        . DIRECTORY_SEPARATOR . 'product_source'
        . DIRECTORY_SEPARATOR . 'travel_b_search_registry.php';

    /** @var mixed $loaded */
    $loaded = require $path;

    if (!is_array($loaded)) {
        return ['platforms' => [], 'instances' => [], 'templates' => []];
    }

    return [
        'platforms' => isset($loaded['platforms']) && is_array($loaded['platforms']) ? $loaded['platforms'] : [],
        'instances' => isset($loaded['instances']) && is_array($loaded['instances']) ? $loaded['instances'] : [],
        'templates' => isset($loaded['templates']) && is_array($loaded['templates']) ? $loaded['templates'] : [],
    ];
}

function grp_url_from_multi_builder(TravelBMultiSourceLinkBuilder $builder, SearchCondition $condition, string $sno): string
{
    foreach ($builder->buildFromHybridCondition($condition, $sno) as $row) {
        if (($row['platform'] ?? '') === 'grp') {
            return (string) ($row['search_url'] ?? '');
        }
    }

    return '';
}

$registryData = grp_load_travel_b_registry();
$registry = new SearchUrlBuilderRegistry(
    $registryData['platforms'],
    $registryData['instances'],
    $registryData['templates']
);
$searchBuilder = new ProductSourceSearchUrlBuilder($registry);

$multiSourceConfig = [
    'enabled' => true,
    'tenant_sno' => TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO,
    'source_instance_keys' => ['dayitravel_grp'],
];
$multiBuilder = new TravelBMultiSourceLinkBuilder($multiSourceConfig);
$sno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;

// Case 1: 瑞士 2026-07-01 ~ 2026-07-03
$result1 = $searchBuilder->buildSearchUrl([
    'tenant_instance' => 'dayitravel_grp',
    'platform' => 'grp',
    'keyword' => '瑞士',
    'date_from' => '2026-07-01',
    'date_to' => '2026-07-03',
    'product_category' => 'group_tour',
]);
$url1 = $result1['search_url'];

grp_test_assert(strpos($url1, 'ClassifyProduct.aspx') !== false, 'case1: ClassifyProduct.aspx path');
grp_test_assert(strpos($url1, 'l=l') !== false, 'case1: l=l');
grp_test_assert(strpos($url1, 'RadDatePicker1=2026-07-01') !== false, 'case1: RadDatePicker1');
grp_test_assert(strpos($url1, 'RadDatePicker2=2026-07-03') !== false, 'case1: RadDatePicker2');
grp_test_assert(
    strpos($url1, 'tp=%E7%91%9E%E5%A3%AB') !== false || strpos($url1, 'tp=%e7%91%9e%e5%a3%ab') !== false || strpos($url1, 'tp=瑞士') !== false,
    'case1: tp=瑞士'
);
grp_test_assert(strpos($url1, '/Tour/Search') === false, 'case1: not old Tour/Search path');
grp_test_assert(strpos($url1, 'GetStore=') === false, 'case1: no GetStore');

grp_test_assert(
    ($registryData['instances']['dayitravel_grp']['url_template_id'] ?? '') === 'grp_dayitravel_classify_v1',
    'case1: dayitravel_grp uses classify template'
);

// Case 2: 高雄東京近期 — grp ignores departure
$condition2 = GeminiDerivedSearchConditionFixtures::kaohsiungTokyoRecent();
$url2 = grp_url_from_multi_builder($multiBuilder, $condition2, $sno);

grp_test_assert($condition2->getDepartureCity() === '高雄', 'case2: hybrid departure_city 高雄');
grp_test_assert(strpos($url2, 'ClassifyProduct.aspx') !== false, 'case2: ClassifyProduct.aspx');
grp_test_assert(strpos($url2, 'khh') === false, 'case2: no khh in grp URL');
grp_test_assert(strpos($url2, 'departure_path_code') === false, 'case2: no departure_path_code param');
grp_test_assert(
    strpos($url2, 'tp=%E6%9D%B1%E4%BA%AC') !== false
        || strpos($url2, 'tp=%e6%9d%b1%e4%ba%ac') !== false
        || strpos($url2, 'tp=東京') !== false,
    'case2: tp=東京'
);
grp_test_assert(strpos($url2, 'RadDatePicker1=') !== false, 'case2: RadDatePicker1 present');
grp_test_assert(strpos($url2, 'RadDatePicker2=') !== false, 'case2: RadDatePicker2 present');

// Case 3: 東京（無日期）— gate blocks; template does not invent dates
$gate = new HybridDateRequiredGate();
$condition3 = GeminiDerivedSearchConditionFixtures::tokyoDestinationOnly();
grp_test_assert(!$gate->evaluate($condition3)->allowsSearch(), 'case3: gate blocks 東京 without dates');

$url3Multi = grp_url_from_multi_builder($multiBuilder, $condition3, $sno);
grp_test_assert($url3Multi !== '', 'case3: builder can render keyword-only URL when invoked directly');
grp_test_assert(strpos($url3Multi, 'RadDatePicker1=') === false, 'case3: multi path does not invent RadDatePicker1');
grp_test_assert(strpos($url3Multi, 'RadDatePicker2=') === false, 'case3: multi path does not invent RadDatePicker2');

$result3 = $searchBuilder->buildSearchUrl([
    'tenant_instance' => 'dayitravel_grp',
    'platform' => 'grp',
    'keyword' => '東京',
    'product_category' => 'group_tour',
]);
$url3 = $result3['search_url'];
grp_test_assert(strpos($url3, 'ClassifyProduct.aspx') !== false, 'case3: template still builds entry without dates');
grp_test_assert(strpos($url3, 'RadDatePicker1=') === false, 'case3: template does not add RadDatePicker1');
grp_test_assert(strpos($url3, 'RadDatePicker2=') === false, 'case3: template does not add RadDatePicker2');
grp_test_assert(strpos($url3, 'tp=') !== false, 'case3: tp still present when keyword only');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_grp_classify_search_url\n");
exit(0);
