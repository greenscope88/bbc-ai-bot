<?php
declare(strict_types=1);

/**
 * AIU v2 P1 Patch 2 — Source Query Mapping regression (Cases 1–3).
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'ApiQueryMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'SearchUrlBuilderRegistry.php';

$failures = 0;

function sq_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * @return array{
 *   platforms: array<string, array<string, mixed>>,
 *   instances: array<string, array<string, mixed>>,
 *   templates: array<string, array<string, mixed>>
 * }
 */
function sq_load_registry(): array
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

// Case 1: Host B supports destination — destination + keyword sent independently.
$case1 = SearchCondition::empty('北海道 賞楓')->with([
    'destination' => '北海道',
    'keyword' => '賞楓',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-07',
]);
$apiParams = (new ApiQueryMapper())->toClientParams($case1);
sq_assert(($apiParams['destination'] ?? '') === '北海道', 'case1: api destination');
sq_assert(($apiParams['keyword'] ?? '') === '賞楓', 'case1: api keyword');
sq_assert(!array_key_exists('source_keyword_query', $apiParams), 'case1: api has no source_keyword_query');

// Case 2: keyword-only platform — source_keyword_query combines destination + keyword.
$case2Doc = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($case1);
sq_assert(($case2Doc['destination'] ?? '') === '北海道', 'case2: document destination');
sq_assert(($case2Doc['keyword'] ?? '') === '賞楓', 'case2: document keyword');
sq_assert(($case2Doc['source_keyword_query'] ?? '') === '北海道 賞楓', 'case2: source_keyword_query');

$registryData = sq_load_registry();
$registry = new SearchUrlBuilderRegistry(
    $registryData['platforms'],
    $registryData['instances'],
    $registryData['templates']
);
$grpUrl = (new ProductSourceSearchUrlBuilder($registry))->buildSearchUrl([
    'tenant_instance' => 'dayitravel_grp',
    'platform' => 'grp',
    'destination' => '北海道',
    'keyword' => '賞楓',
    'source_keyword_query' => '北海道 賞楓',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-07',
    'product_category' => 'group_tour',
])['search_url'];
$grpQuery = [];
parse_str((string) parse_url($grpUrl, PHP_URL_QUERY), $grpQuery);
sq_assert(
    strpos(rawurldecode((string) ($grpQuery['tp'] ?? '')), '北海道') !== false
    && strpos(rawurldecode((string) ($grpQuery['tp'] ?? '')), '賞楓') !== false,
    'case2: grp wire keyword contains destination + keyword'
);

$bonusmeeUrl = (new SearchUrlBuilder(false))->build('5f99b8d665e8444d', $case1);
$bonusmeeQuery = [];
parse_str((string) parse_url($bonusmeeUrl, PHP_URL_QUERY), $bonusmeeQuery);
sq_assert(
    strpos(rawurldecode((string) ($bonusmeeQuery['keyword'] ?? '')), '北海道') !== false
    && strpos(rawurldecode((string) ($bonusmeeQuery['keyword'] ?? '')), '賞楓') !== false,
    'case2: bonusmee storefront keyword contains destination + keyword'
);
sq_assert(!array_key_exists('destination', $bonusmeeQuery), 'case2: bonusmee storefront omits destination param');
sq_assert($case1->getDestination() === '北海道', 'case2: runtime destination unchanged');
sq_assert($case1->getKeyword() === '賞楓', 'case2: runtime keyword unchanged');

// Case 3: keyword-only platform, keyword null — source_keyword_query = destination only.
$case3 = SearchCondition::empty('北海道')->with([
    'destination' => '北海道',
    'keyword' => null,
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-07',
]);
$case3Doc = TravelBMultiSourceLinkBuilder::hybridConditionToSearchDocument($case3);
sq_assert(($case3Doc['destination'] ?? '') === '北海道', 'case3: document destination');
sq_assert(($case3Doc['keyword'] ?? '') === '', 'case3: document keyword empty');
sq_assert(($case3Doc['source_keyword_query'] ?? '') === '北海道', 'case3: source_keyword_query destination only');

$bbctravelUrl = (new ProductSourceSearchUrlBuilder($registry))->buildSearchUrl([
    'tenant_instance' => 'dayitravel_bbctravel',
    'platform' => 'bbctravel',
    'destination' => '北海道',
    'keyword' => '',
    'source_keyword_query' => '北海道',
    'departure_path_code' => 'all',
    'date_from' => '2026-10-01',
    'date_to' => '2026-10-07',
    'product_category' => 'group_tour',
])['search_url'];
$bbcQuery = [];
parse_str((string) parse_url($bbctravelUrl, PHP_URL_QUERY), $bbcQuery);
sq_assert(
    rawurldecode((string) ($bbcQuery['q'] ?? '')) === '北海道',
    'case3: bbctravel wire keyword is destination only'
);

if ($failures === 0) {
    echo "ALL PASS test_aiu_p1_source_query_mapping\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_p1_source_query_mapping\n");
exit(1);
