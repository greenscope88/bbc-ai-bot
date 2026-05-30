<?php
declare(strict_types=1);

/**
 * Product source catalog contract constants (Phase 9-B-1b).
 */
final class ProductSourceCatalogContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<int> */
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    /** @var list<string> */
    public const PRODUCT_CATEGORIES = [
        'group_tour',
        'hotel',
        'car_rental',
        'fit',
        'private_group',
        'other',
    ];

    /** @var list<string> */
    public const SOURCE_TYPES = [
        'host_b',
        'catalog',
        'storefront',
        'external',
        'future',
    ];

    /**
     * Adapter class names registered for URL / search integration (Phase 9-B+).
     *
     * @var list<string>
     */
    public const KNOWN_ADAPTERS = [
        'BbcshopsAdapter',
        'GrpAdapter',
        'BbcTravelAdapter',
        'TourCenterAdapter',
        'HostBAdapter',
        'StubProductSourceAdapter',
    ];

    public const SOURCE_ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public const URL_TEMPLATE_ID_PATTERN = '/^[a-z][a-z0-9_]{0,127}$/';

    private function __construct()
    {
    }

    public static function isValidProductCategory(string $category): bool
    {
        return in_array(trim($category), self::PRODUCT_CATEGORIES, true);
    }

    public static function isValidSourceType(string $sourceType): bool
    {
        return in_array(trim($sourceType), self::SOURCE_TYPES, true);
    }

    public static function isKnownAdapter(string $adapter): bool
    {
        return in_array(trim($adapter), self::KNOWN_ADAPTERS, true);
    }
}
