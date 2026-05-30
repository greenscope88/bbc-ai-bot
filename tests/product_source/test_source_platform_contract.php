<?php
declare(strict_types=1);

/**
 * Phase 9-B-8: Source platform contract.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourcePlatformContract.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$platformIds = [
    'agenttour',
    'grp',
    'bbctravel',
    'tourcenter',
    'trip_com',
    'custom_website',
];

foreach ($platformIds as $platformId) {
    test_assert(SourcePlatformContract::isKnownPlatformId($platformId), 'known platform_id: ' . $platformId);

    $platform = SourcePlatformContract::getKnownPlatform($platformId);
    test_assert(is_array($platform), 'getKnownPlatform returns array: ' . $platformId);

    if (!is_array($platform)) {
        continue;
    }

    test_assert($platform['platform_id'] === $platformId, 'platform_id matches: ' . $platformId);
    test_assert(isset($platform['platform_domain']) && trim((string) $platform['platform_domain']) !== '', 'platform_domain set: ' . $platformId);
    test_assert(SourcePlatformContract::isValidPlatformType((string) $platform['platform_type']), 'valid platform_type: ' . $platformId);
    test_assert(SourcePlatformContract::isValidIdentifierType((string) $platform['identifier_type']), 'valid identifier_type: ' . $platformId);
    test_assert(isset($platform['adapter']) && trim((string) $platform['adapter']) !== '', 'adapter set: ' . $platformId);
    test_assert(isset($platform['url_template_id']) && trim((string) $platform['url_template_id']) !== '', 'url_template_id set: ' . $platformId);
    test_assert(is_array($platform['supported_categories'] ?? null) && $platform['supported_categories'] !== [], 'supported_categories non-empty: ' . $platformId);

    try {
        SourcePlatformContract::validatePlatform($platform);
        test_assert(true, 'validatePlatform passes: ' . $platformId);
    } catch (ProductSourceContractException $e) {
        test_assert(false, 'validatePlatform should pass: ' . $platformId . ' (' . $e->getMessage() . ')');
    }
}

// Platform-specific expectations
$agenttour = SourcePlatformContract::getKnownPlatform('agenttour');
test_assert(is_array($agenttour) && $agenttour['platform_domain'] === 'agenttour.com.tw', 'agenttour domain');
test_assert(is_array($agenttour) && $agenttour['identifier_type'] === 'subdomain', 'agenttour identifier_type subdomain');

$grp = SourcePlatformContract::getKnownPlatform('grp');
test_assert(is_array($grp) && $grp['adapter'] === 'GrpAdapter', 'grp adapter');

$bbctravel = SourcePlatformContract::getKnownPlatform('bbctravel');
test_assert(is_array($bbctravel) && $bbctravel['adapter'] === 'BbcTravelAdapter', 'bbctravel adapter');

$tourcenter = SourcePlatformContract::getKnownPlatform('tourcenter');
test_assert(is_array($tourcenter) && $tourcenter['adapter'] === 'TourCenterAdapter', 'tourcenter adapter');

$tripCom = SourcePlatformContract::getKnownPlatform('trip_com');
test_assert(is_array($tripCom) && $tripCom['identifier_type'] === 'affiliate_param', 'trip_com affiliate_param');
test_assert(is_array($tripCom) && in_array('hotel', $tripCom['supported_categories'], true), 'trip_com supports hotel');

$customWebsite = SourcePlatformContract::getKnownPlatform('custom_website');
test_assert(is_array($customWebsite) && $customWebsite['platform_domain'] === 'xinxin.com.tw', 'custom_website domain');
test_assert(is_array($customWebsite) && $customWebsite['identifier_type'] === 'query_param', 'custom_website query_param');

// Identifier type enum
foreach (SourcePlatformContract::IDENTIFIER_TYPES as $identifierType) {
    test_assert(SourcePlatformContract::isValidIdentifierType($identifierType), 'valid identifier type enum: ' . $identifierType);
}
test_assert(!SourcePlatformContract::isValidIdentifierType('invalid_identifier_type'), 'invalid identifier type rejected');

// Unknown platform
test_assert(!SourcePlatformContract::isKnownPlatformId('unknown_platform_xyz'), 'unknown platform_id rejected');

try {
    SourcePlatformContract::validatePlatform([
        'platform_id' => 'bad id',
        'platform_name' => '',
    ]);
    test_assert(false, 'invalid platform document should throw');
} catch (ProductSourceContractException $e) {
    test_assert($e->getErrorCode() === 'SOURCE_PLATFORM_CONTRACT_INVALID', 'invalid platform error code');
}

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_source_platform_contract (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
