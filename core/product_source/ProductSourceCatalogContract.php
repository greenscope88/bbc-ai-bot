<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';

/**
 * Product source catalog contract constants (Phase 9-B-1b / 9-B-7).
 */
final class ProductSourceCatalogContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<int> */
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    /**
     * Canonical list delegated to ProductCategoryContract (Phase 9-B-7).
     * Includes legacy private_group for backward compatibility with Phase 9-B-1b samples.
     *
     * @var list<string>
     */
    public const PRODUCT_CATEGORIES = [
        'group_tour',
        'fit',
        'flight',
        'hotel',
        'mini_group',
        'car_rental',
        'cruise',
        'visa',
        'ticket',
        'other',
        'private_group',
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
        return ProductCategoryContract::isValidProductCategory($category);
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
