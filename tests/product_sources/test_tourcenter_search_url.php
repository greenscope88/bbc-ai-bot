<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';

$failures = 0;

function tc_url_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

function tc_load_travel_b_registry(): array
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

function tc_tourcenter_url_from_multi(TravelBMultiSourceLinkBuilder $builder, SearchCondition $condition, string $sno): string
{
    foreach ($builder->buildFromHybridCondition($condition, $sno) as $row) {
        if (($row['platform'] ?? '') === 'tourcenter') {
            return (string) ($row['search_url'] ?? '');
        }
    }

    return '';
}

$registryData = tc_load_travel_b_registry();
$registry = new SearchUrlBuilderRegistry(
    $registryData['platforms'],
    $registryData['instances'],
    $registryData['templates']
);
$searchBuilder = new ProductSourceSearchUrlBuilder($registry);

tc_url_assert(
    ($registryData['instances']['dayitravel_tourcenter']['url_template_id'] ?? '') === 'tourcenter_dayitravel_search_v1',
    'dayitravel_tourcenter uses tourcenter_dayitravel_search_v1'
);

$baseInput = [
    'tenant_instance' => 'dayitravel_tourcenter',
    'platform' => 'tourcenter',
    'keyword' => '東京',
    'date_from' => '2026-06-11',
    'date_to' => '2026-06-11',
    'product_category' => 'group_tour',
];

$cases = [
    ['departure_id' => 'TPE', 'label' => 'TPE'],
    ['departure_id' => 'TCH', 'label' => 'TCH'],
    ['departure_id' => 'KHH', 'label' => 'KHH'],
    ['departure_id' => 'TNN', 'label' => 'TNN'],
    ['departure_id' => '', 'label' => 'empty departure'],
];

foreach ($cases as $case) {
    $input = $baseInput;
    $input['departure_id'] = $case['departure_id'];
    $result = $searchBuilder->buildSearchUrl($input);
    $url = $result['search_url'];

    tc_url_assert(strpos($url, 'dayitravel.tourcenter.com.tw') !== false, $case['label'] . ': host dayitravel.tourcenter.com.tw');
    tc_url_assert(strpos($url, '/travel/search') !== false, $case['label'] . ': /travel/search path');
    tc_url_assert(strpos($url, 'TravelType=0') !== false, $case['label'] . ': TravelType=0');

    if ($case['departure_id'] === '') {
        tc_url_assert(strpos($url, 'DepartureID=&') !== false || preg_match('/DepartureID=$/', $url) === 1, $case['label'] . ': DepartureID=');
    } else {
        tc_url_assert(strpos($url, 'DepartureID=' . $case['departure_id']) !== false, $case['label'] . ': DepartureID value');
    }
}

$multiConfig = [
    'enabled' => true,
    'tenant_sno' => TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO,
    'source_instance_keys' => ['dayitravel_tourcenter'],
];
$multiBuilder = new TravelBMultiSourceLinkBuilder($multiConfig);
$sno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;

$conditionTpe = GeminiDerivedSearchConditionFixtures::songshanTokyoLateJune();
$urlTpe = tc_tourcenter_url_from_multi($multiBuilder, $conditionTpe, $sno);
tc_url_assert($conditionTpe->getDepartureCity() === '松山', 'hybrid keeps departure_city 松山');
tc_url_assert(strpos($urlTpe, 'DepartureID=TPE') !== false, 'hybrid 松山 -> tourcenter DepartureID=TPE');

$conditionAll = GeminiDerivedSearchConditionFixtures::tokyoLateJuneOnly();
$urlAll = tc_tourcenter_url_from_multi($multiBuilder, $conditionAll, $sno);
tc_url_assert($conditionAll->getDepartureCity() === null, 'hybrid unlimited departure_city null');
tc_url_assert(strpos($urlAll, 'DepartureID=&') !== false || preg_match('/DepartureID=$/', $urlAll) === 1, 'hybrid unlimited -> DepartureID=');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_tourcenter_search_url\n");
exit(0);