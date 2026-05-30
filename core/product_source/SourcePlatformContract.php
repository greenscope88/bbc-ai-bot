<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceContractException.php';

/**
 * Source platform contract (Phase 9-B-8).
 *
 * A platform is the shared technology/vendor layer (e.g. agenttour.com.tw, trip.com).
 * Tenant-specific routing is modeled separately in TenantSourceInstanceContract.
 */
final class SourcePlatformContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const PLATFORM_TYPES = [
        'storefront',
        'external_search',
        'affiliate_marketplace',
        'custom_website',
        'future',
    ];

    /** @var list<string> */
    public const IDENTIFIER_TYPES = [
        'subdomain',
        'query_param',
        'affiliate_param',
        'fixed_url',
        'custom',
    ];

    public const PLATFORM_ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * Reference platform definitions for onboarding / tests (not runtime GCS).
     *
     * @var array<string, array{
     *   platform_id: string,
     *   platform_name: string,
     *   platform_domain: string,
     *   platform_type: string,
     *   supported_categories: list<string>,
     *   identifier_type: string,
     *   adapter: string,
     *   url_template_id: string,
     *   identifier_param_keys?: list<string>
     * }>
     */
    public const KNOWN_PLATFORMS = [
        'agenttour' => [
            'platform_id' => 'agenttour',
            'platform_name' => 'AgentTour',
            'platform_domain' => 'agenttour.com.tw',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour', 'fit'],
            'identifier_type' => 'subdomain',
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'agenttour_search_v1',
        ],
        'grp' => [
            'platform_id' => 'grp',
            'platform_name' => 'GRP',
            'platform_domain' => 'grp.com.tw',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour'],
            'identifier_type' => 'subdomain',
            'adapter' => 'GrpAdapter',
            'url_template_id' => 'grp_search_v1',
        ],
        'bbctravel' => [
            'platform_id' => 'bbctravel',
            'platform_name' => 'BBC Travel',
            'platform_domain' => 'bbctravel.com.tw',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour', 'fit'],
            'identifier_type' => 'subdomain',
            'adapter' => 'BbcTravelAdapter',
            'url_template_id' => 'bbctravel_search_v1',
        ],
        'tourcenter' => [
            'platform_id' => 'tourcenter',
            'platform_name' => 'TourCenter',
            'platform_domain' => 'tourcenter.com.tw',
            'platform_type' => 'external_search',
            'supported_categories' => ['group_tour'],
            'identifier_type' => 'subdomain',
            'adapter' => 'TourCenterAdapter',
            'url_template_id' => 'tourcenter_search_v1',
        ],
        'trip_com' => [
            'platform_id' => 'trip_com',
            'platform_name' => 'Trip.com',
            'platform_domain' => 'trip.com',
            'platform_type' => 'affiliate_marketplace',
            'supported_categories' => ['hotel', 'ticket'],
            'identifier_type' => 'affiliate_param',
            'identifier_param_keys' => ['Allianceid', 'SID'],
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'trip_com_search_v1',
        ],
        'custom_website' => [
            'platform_id' => 'custom_website',
            'platform_name' => 'Custom Website (LIKEGO)',
            'platform_domain' => 'xinxin.com.tw',
            'platform_type' => 'custom_website',
            'supported_categories' => ['group_tour', 'other'],
            'identifier_type' => 'query_param',
            'identifier_param_keys' => ['GetStore'],
            'adapter' => 'StubProductSourceAdapter',
            'url_template_id' => 'custom_website_search_v1',
        ],
    ];

    private function __construct()
    {
    }

    public static function isValidPlatformType(string $platformType): bool
    {
        return in_array(trim($platformType), self::PLATFORM_TYPES, true);
    }

    public static function isValidIdentifierType(string $identifierType): bool
    {
        return in_array(trim($identifierType), self::IDENTIFIER_TYPES, true);
    }

    public static function isKnownPlatformId(string $platformId): bool
    {
        $key = trim($platformId);

        return $key !== '' && isset(self::KNOWN_PLATFORMS[$key]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getKnownPlatform(string $platformId): ?array
    {
        $key = trim($platformId);
        if ($key === '' || !isset(self::KNOWN_PLATFORMS[$key])) {
            return null;
        }

        return self::KNOWN_PLATFORMS[$key];
    }

    /**
     * @param array<string, mixed> $document
     * @throws ProductSourceContractException
     */
    public static function validatePlatform(array $document): void
    {
        $violations = self::collectPlatformViolations($document);
        if ($violations !== []) {
            throw new ProductSourceContractException(
                'SOURCE_PLATFORM_CONTRACT_INVALID',
                'Source platform contract invalid (' . count($violations) . ' issue(s)): ' . implode('; ', $violations)
            );
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public static function collectPlatformViolations(array $document): array
    {
        $violations = [];

        $platformId = isset($document['platform_id']) ? trim((string) $document['platform_id']) : '';
        if ($platformId === '') {
            $violations[] = 'missing platform_id';
        } elseif (!preg_match(self::PLATFORM_ID_PATTERN, $platformId)) {
            $violations[] = 'invalid platform_id format';
        }

        $platformName = isset($document['platform_name']) ? trim((string) $document['platform_name']) : '';
        if ($platformName === '') {
            $violations[] = 'missing platform_name';
        }

        $platformDomain = isset($document['platform_domain']) ? trim((string) $document['platform_domain']) : '';
        if ($platformDomain === '') {
            $violations[] = 'missing platform_domain';
        }

        $platformType = isset($document['platform_type']) ? trim((string) $document['platform_type']) : '';
        if ($platformType === '' || !self::isValidPlatformType($platformType)) {
            $violations[] = 'invalid platform_type';
        }

        $identifierType = isset($document['identifier_type']) ? trim((string) $document['identifier_type']) : '';
        if ($identifierType === '' || !self::isValidIdentifierType($identifierType)) {
            $violations[] = 'invalid identifier_type';
        }

        $adapter = isset($document['adapter']) ? trim((string) $document['adapter']) : '';
        if ($adapter === '') {
            $violations[] = 'missing adapter';
        }

        $urlTemplateId = isset($document['url_template_id']) ? trim((string) $document['url_template_id']) : '';
        if ($urlTemplateId === '') {
            $violations[] = 'missing url_template_id';
        }

        $categories = $document['supported_categories'] ?? null;
        if (!is_array($categories) || $categories === []) {
            $violations[] = 'supported_categories must be a non-empty array';
        } else {
            foreach ($categories as $category) {
                if (!is_string($category) || !ProductCategoryContract::isValidProductCategory($category)) {
                    $violations[] = 'invalid supported_category: ' . (string) $category;
                }
            }
        }

        return $violations;
    }
}
