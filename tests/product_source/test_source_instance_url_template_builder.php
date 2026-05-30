<?php
declare(strict_types=1);

/**
 * Phase 9-B-9: Source instance URL template builder (identifier_type-driven).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceInstanceUrlTemplateBuilder.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function fixture_platform(
    string $platformId,
    string $platformDomain,
    string $identifierType,
    array $extra = []
): array {
    return array_merge([
        'platform_id' => $platformId,
        'platform_name' => ucfirst($platformId),
        'platform_domain' => $platformDomain,
        'platform_type' => 'external_search',
        'supported_categories' => ['group_tour'],
        'identifier_type' => $identifierType,
        'adapter' => 'StubProductSourceAdapter',
        'url_template_id' => $platformId . '_entry_v1',
    ], $extra);
}

function fixture_instance(
    string $instanceId,
    string $platformId,
    string $identifierType,
    array $identifierValues,
    string $tenantSno = '5f99b8d665e8444d'
): array {
    return [
        'source_instance_id' => $instanceId,
        'tenant_sno' => $tenantSno,
        'platform_id' => $platformId,
        'identifier_type' => $identifierType,
        'identifier_values' => $identifierValues,
        'enabled' => true,
        'priority' => 10,
    ];
}

$builder = new SourceInstanceUrlTemplateBuilder();
$travelBSno = '5f99b8d665e8444d';

$subdomainTemplate = [
    'template_id' => 'subdomain_https_root_v1',
    'identifier_type' => 'subdomain',
    'scheme' => 'https',
    'host_pattern' => '{subdomain}.{platform_domain}',
    'path' => '/',
];

$queryParamTemplate = [
    'template_id' => 'query_param_path_v1',
    'identifier_type' => 'query_param',
    'scheme' => 'https',
    'host_pattern' => 'www.{platform_domain}',
    'path' => '/LIKEGO/sort.php',
    'query_param_keys' => ['GetStore'],
];

$affiliateHotelTemplate = [
    'template_id' => 'affiliate_hotel_entry_v1',
    'identifier_type' => 'affiliate_param',
    'base_url' => 'https://www.trip.com/hotels',
    'query_param_keys' => ['Allianceid', 'SID'],
];

$affiliateThingsTemplate = [
    'template_id' => 'affiliate_things_entry_v1',
    'identifier_type' => 'affiliate_param',
    'base_url' => 'https://www.trip.com/things-to-do',
    'query_param_keys' => ['Allianceid', 'SID'],
];

$fixedUrlTemplate = [
    'template_id' => 'fixed_url_pass_through_v1',
    'identifier_type' => 'fixed_url',
];

$customSubdomainTemplate = [
    'template_id' => 'custom_subdomain_pattern_v1',
    'identifier_type' => 'custom',
    'url_pattern' => 'https://{tenant_slug}.{platform_domain}/welcome',
];

// 1. subdomain fixtures (reference platforms — config only, not in builder core)
$subdomainCases = [
    ['agenttour', 'agenttour.com.tw', 'rechoice-travel', 'https://rechoice-travel.agenttour.com.tw/'],
    ['grp', 'grp.com.tw', 'dayitravel', 'https://dayitravel.grp.com.tw/'],
    ['bbctravel', 'bbctravel.com.tw', 'dayitravel', 'https://dayitravel.bbctravel.com.tw/'],
    ['tourcenter', 'tourcenter.com.tw', 'dayitravel', 'https://dayitravel.tourcenter.com.tw/'],
];

foreach ($subdomainCases as [$platformId, $domain, $subdomain, $expectedUrl]) {
    $platform = fixture_platform($platformId, $domain, 'subdomain');
    $instance = fixture_instance(
        'inst_' . $platformId,
        $platformId,
        'subdomain',
        ['subdomain' => $subdomain],
        $travelBSno
    );
    $result = $builder->build($platform, $instance, $subdomainTemplate);
    test_assert($result['entry_url'] === $expectedUrl, 'subdomain entry url: ' . $platformId);
    test_assert($result['identifier_type'] === 'subdomain', 'subdomain identifier_type: ' . $platformId);
}

// 2. query_param GetStore (custom website reference)
$customPlatform = fixture_platform('custom_website', 'xinxin.com.tw', 'query_param', [
    'platform_type' => 'custom_website',
    'identifier_param_keys' => ['GetStore'],
]);
$getStoreInstance = fixture_instance(
    'inst_xinxin_xxn',
    'custom_website',
    'query_param',
    ['GetStore' => 'XXN'],
    $travelBSno
);
$getStoreResult = $builder->build($customPlatform, $getStoreInstance, $queryParamTemplate);
test_assert(
    $getStoreResult['entry_url'] === 'https://www.xinxin.com.tw/LIKEGO/sort.php?GetStore=XXN',
    'query_param GetStore entry url'
);

// 3. affiliate_param Trip.com Hotel / Things To Do
$tripPlatform = fixture_platform('trip_com', 'trip.com', 'affiliate_param', [
    'platform_type' => 'affiliate_marketplace',
    'supported_categories' => ['hotel', 'ticket'],
    'identifier_param_keys' => ['Allianceid', 'SID'],
]);
$affiliateValues = [
    'Allianceid' => '7921306',
    'SID' => '297627198',
];

$hotelInstance = fixture_instance('inst_trip_hotel', 'trip_com', 'affiliate_param', $affiliateValues, $travelBSno);
$hotelResult = $builder->build($tripPlatform, $hotelInstance, $affiliateHotelTemplate);
test_assert(
    $hotelResult['entry_url'] === 'https://www.trip.com/hotels?Allianceid=7921306&SID=297627198',
    'trip.com hotel affiliate entry url'
);

$thingsInstance = fixture_instance('inst_trip_things', 'trip_com', 'affiliate_param', $affiliateValues, $travelBSno);
$thingsResult = $builder->build($tripPlatform, $thingsInstance, $affiliateThingsTemplate);
test_assert(
    $thingsResult['entry_url'] === 'https://www.trip.com/things-to-do?Allianceid=7921306&SID=297627198',
    'trip.com things-to-do affiliate entry url'
);

// 4. fixed_url pass-through
$fixedUrl = 'https://partner.example.com/search/entry';
$fixedPlatform = fixture_platform('custom_website', 'partner.example.com', 'fixed_url', [
    'platform_type' => 'custom_website',
]);
$fixedInstance = fixture_instance(
    'inst_fixed_url',
    'custom_website',
    'fixed_url',
    ['url' => $fixedUrl],
    $travelBSno
);
$fixedResult = $builder->build($fixedPlatform, $fixedInstance, $fixedUrlTemplate);
test_assert($fixedResult['entry_url'] === $fixedUrl, 'fixed_url pass-through');

// 5. future extension: new platform without builder core change
$newPlatform = fixture_platform('newplatform', 'newplatform.example.com', 'subdomain', [
    'platform_name' => 'New Platform',
    'platform_type' => 'future',
]);
$newInstance = fixture_instance(
    'inst_newplatform_tenant',
    'newplatform',
    'subdomain',
    ['subdomain' => 'newtenant'],
    $travelBSno
);
$newResult = $builder->build($newPlatform, $newInstance, $subdomainTemplate);
test_assert(
    $newResult['entry_url'] === 'https://newtenant.newplatform.example.com/',
    'future platform via template only'
);

// 6. custom url_pattern extension
$customPlatform = fixture_platform('pattern_platform', 'pattern.example.com', 'custom', [
    'platform_type' => 'future',
]);
$customInstance = fixture_instance(
    'inst_custom_pattern',
    'pattern_platform',
    'custom',
    ['tenant_slug' => 'agency-a'],
    $travelBSno
);
$customResult = $builder->build($customPlatform, $customInstance, $customSubdomainTemplate);
test_assert(
    $customResult['entry_url'] === 'https://agency-a.pattern.example.com/welcome',
    'custom url_pattern extension'
);

// 7. builder core must not hardcode reference platform ids (static check)
$builderSource = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceInstanceUrlTemplateBuilder.php');
if (is_string($builderSource)) {
    foreach (['agenttour', 'grp.com.tw', 'bbctravel', 'tourcenter', 'trip_com', 'xinxin.com.tw'] as $needle) {
        test_assert(strpos($builderSource, $needle) === false, 'builder core must not contain: ' . $needle);
    }
}

// 8. identifier_type mismatch fails safely
try {
    $builder->build(
        fixture_platform('mismatch', 'example.com', 'subdomain'),
        fixture_instance('inst_mismatch', 'mismatch', 'subdomain', ['subdomain' => 'a'], $travelBSno),
        $queryParamTemplate
    );
    test_assert(false, 'template identifier_type mismatch should throw');
} catch (ProductSourceContractException $e) {
    test_assert($e->getErrorCode() === 'URL_TEMPLATE_IDENTIFIER_MISMATCH', 'mismatch error code');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_source_instance_url_template_builder (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
