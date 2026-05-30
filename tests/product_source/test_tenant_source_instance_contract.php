<?php
declare(strict_types=1);

/**
 * Phase 9-B-8: Tenant source instance contract.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TenantSourceInstanceContract.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$travelBSno = '5f99b8d665e8444d';

// 1. subdomain instance (AgentTour / GRP style)
$agenttourPlatform = SourcePlatformContract::getKnownPlatform('agenttour');
$subdomainInstance = [
    'source_instance_id' => 'travel_b_agenttour_rechoice',
    'tenant_sno' => $travelBSno,
    'platform_id' => 'agenttour',
    'identifier_type' => 'subdomain',
    'identifier_values' => [
        'subdomain' => 'rechoice-travel',
    ],
    'enabled' => true,
    'priority' => 10,
];

try {
    TenantSourceInstanceContract::validateInstance($subdomainInstance, is_array($agenttourPlatform) ? $agenttourPlatform : null);
    test_assert(true, 'subdomain instance validates');
} catch (ProductSourceContractException $e) {
    test_assert(false, 'subdomain instance should pass: ' . $e->getMessage());
}

$grpPlatform = SourcePlatformContract::getKnownPlatform('grp');
$grpInstance = [
    'source_instance_id' => 'travel_b_grp_dayitravel',
    'tenant_sno' => $travelBSno,
    'platform_id' => 'grp',
    'identifier_type' => 'subdomain',
    'identifier_values' => [
        'subdomain' => 'dayitravel',
    ],
    'enabled' => true,
    'priority' => 20,
];

try {
    TenantSourceInstanceContract::validateInstance($grpInstance, is_array($grpPlatform) ? $grpPlatform : null);
    test_assert(true, 'grp subdomain instance validates');
} catch (ProductSourceContractException $e) {
    test_assert(false, 'grp subdomain instance should pass: ' . $e->getMessage());
}

// 2. query_param GetStore (custom website)
$customPlatform = SourcePlatformContract::getKnownPlatform('custom_website');
$getStoreInstance = [
    'source_instance_id' => 'travel_b_xinxin_xxn',
    'tenant_sno' => $travelBSno,
    'platform_id' => 'custom_website',
    'identifier_type' => 'query_param',
    'identifier_values' => [
        'GetStore' => 'XXN',
    ],
    'enabled' => true,
    'priority' => 30,
];

try {
    TenantSourceInstanceContract::validateInstance($getStoreInstance, is_array($customPlatform) ? $customPlatform : null);
    test_assert(true, 'GetStore query_param instance validates');
} catch (ProductSourceContractException $e) {
    test_assert(false, 'GetStore instance should pass: ' . $e->getMessage());
}

$missingGetStore = $getStoreInstance;
$missingGetStore['identifier_values'] = [];
try {
    TenantSourceInstanceContract::validateInstance($missingGetStore, is_array($customPlatform) ? $customPlatform : null);
    test_assert(false, 'missing GetStore should fail');
} catch (ProductSourceContractException $e) {
    test_assert($e->getErrorCode() === 'TENANT_SOURCE_INSTANCE_CONTRACT_INVALID', 'missing GetStore error code');
}

// 3. affiliate_param Allianceid + SID (Trip.com)
$tripPlatform = SourcePlatformContract::getKnownPlatform('trip_com');
$affiliateInstance = [
    'source_instance_id' => 'travel_b_trip_hotel',
    'tenant_sno' => $travelBSno,
    'platform_id' => 'trip_com',
    'identifier_type' => 'affiliate_param',
    'identifier_values' => [
        'Allianceid' => '1234567',
        'SID' => '8901234',
    ],
    'enabled' => true,
    'priority' => 40,
];

try {
    TenantSourceInstanceContract::validateInstance($affiliateInstance, is_array($tripPlatform) ? $tripPlatform : null);
    test_assert(true, 'Allianceid+SID affiliate instance validates');
} catch (ProductSourceContractException $e) {
    test_assert(false, 'affiliate instance should pass: ' . $e->getMessage());
}

$missingSid = $affiliateInstance;
unset($missingSid['identifier_values']['SID']);
try {
    TenantSourceInstanceContract::validateInstance($missingSid, is_array($tripPlatform) ? $tripPlatform : null);
    test_assert(false, 'missing SID should fail');
} catch (ProductSourceContractException $e) {
    test_assert(strpos($e->getMessage(), 'SID') !== false, 'missing SID error message');
}

// 4. fixed_url instance
$fixedUrlInstance = [
    'source_instance_id' => 'travel_b_custom_fixed',
    'tenant_sno' => $travelBSno,
    'platform_id' => 'custom_website',
    'identifier_type' => 'fixed_url',
    'identifier_values' => [
        'url' => 'https://www.xinxin.com.tw/LIKEGO/sort.php?GetStore=XXN',
    ],
    'enabled' => true,
    'priority' => 50,
];

$violations = TenantSourceInstanceContract::collectInstanceViolations(
    $fixedUrlInstance,
    is_array($customPlatform) ? $customPlatform : null
);
test_assert(
    in_array('identifier_type mismatch with platform definition', $violations, true),
    'fixed_url on query_param platform fails type mismatch'
);

$fixedUrlPlatform = is_array($customPlatform) ? $customPlatform : null;
if (is_array($fixedUrlPlatform)) {
    $fixedUrlPlatform['identifier_type'] = 'fixed_url';
}
try {
    TenantSourceInstanceContract::validateInstance($fixedUrlInstance, $fixedUrlPlatform);
    test_assert(true, 'fixed_url instance validates with matching platform type');
} catch (ProductSourceContractException $e) {
    test_assert(false, 'fixed_url instance should pass: ' . $e->getMessage());
}

$badFixedUrl = $fixedUrlInstance;
$badFixedUrl['identifier_values'] = ['url' => 'not-a-url'];
try {
    TenantSourceInstanceContract::validateInstance($badFixedUrl, $fixedUrlPlatform);
    test_assert(false, 'invalid fixed_url should fail');
} catch (ProductSourceContractException $e) {
    test_assert(strpos($e->getMessage(), 'url') !== false, 'invalid fixed_url error message');
}

// 5. identifier_type mismatch with platform
$badTypeInstance = $subdomainInstance;
$badTypeInstance['identifier_type'] = 'affiliate_param';
try {
    TenantSourceInstanceContract::validateInstance($badTypeInstance, is_array($agenttourPlatform) ? $agenttourPlatform : null);
    test_assert(false, 'identifier_type mismatch should fail');
} catch (ProductSourceContractException $e) {
    test_assert(strpos($e->getMessage(), 'identifier_type mismatch') !== false, 'identifier_type mismatch message');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_tenant_source_instance_contract (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
