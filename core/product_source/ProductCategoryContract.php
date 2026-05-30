<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContractException.php';

/**
 * Multi product source category contract (Phase 9-B-7).
 *
 * Canonical enum for product_category, source_category, and adapter mapping hints.
 * Extend via Registry / Config / Adapter — not by modifying core search flow.
 */
final class ProductCategoryContract
{
    /** @var list<string> */
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
    ];

    /**
     * Legacy aliases accepted for backward compatibility (Phase 9-B-1b catalog).
     *
     * @var array<string, string>
     */
    public const LEGACY_PRODUCT_CATEGORY_ALIASES = [
        'private_group' => 'mini_group',
    ];

    /** @var list<string> */
    public const SOURCE_CATEGORIES = [
        'tour',
        'transport',
        'accommodation',
        'service',
        'general',
    ];

    /**
     * Maps normalized product_category to source_category (business grouping).
     *
     * @var array<string, string>
     */
    public const PRODUCT_CATEGORY_TO_SOURCE_CATEGORY = [
        'group_tour' => 'tour',
        'fit' => 'tour',
        'mini_group' => 'tour',
        'flight' => 'transport',
        'car_rental' => 'transport',
        'cruise' => 'transport',
        'hotel' => 'accommodation',
        'visa' => 'service',
        'ticket' => 'service',
        'other' => 'general',
    ];

    /**
     * Default adapter class hints for onboarding / catalog authoring (not runtime wiring).
     *
     * @var array<string, string>
     */
    public const DEFAULT_ADAPTER_BY_PRODUCT_CATEGORY = [
        'group_tour' => 'BbcshopsAdapter',
        'fit' => 'BbcTravelAdapter',
        'flight' => 'StubProductSourceAdapter',
        'hotel' => 'StubProductSourceAdapter',
        'mini_group' => 'GrpAdapter',
        'car_rental' => 'StubProductSourceAdapter',
        'cruise' => 'StubProductSourceAdapter',
        'visa' => 'StubProductSourceAdapter',
        'ticket' => 'StubProductSourceAdapter',
        'other' => 'StubProductSourceAdapter',
    ];

    private function __construct()
    {
    }

    public static function normalizeProductCategory(string $category): string
    {
        $trimmed = trim($category);
        if ($trimmed === '') {
            return '';
        }

        if (isset(self::LEGACY_PRODUCT_CATEGORY_ALIASES[$trimmed])) {
            return self::LEGACY_PRODUCT_CATEGORY_ALIASES[$trimmed];
        }

        return $trimmed;
    }

    public static function isValidProductCategory(string $category): bool
    {
        $normalized = self::normalizeProductCategory($category);

        return $normalized !== '' && in_array($normalized, self::PRODUCT_CATEGORIES, true);
    }

    public static function isValidSourceCategory(string $sourceCategory): bool
    {
        return in_array(trim($sourceCategory), self::SOURCE_CATEGORIES, true);
    }

    public static function resolveSourceCategory(string $productCategory): string
    {
        $normalized = self::normalizeProductCategory($productCategory);
        if ($normalized === '' || !self::isValidProductCategory($normalized)) {
            throw new ProductCategoryContractException(
                'INVALID_PRODUCT_CATEGORY',
                'Unknown product_category: ' . $productCategory
            );
        }

        return self::PRODUCT_CATEGORY_TO_SOURCE_CATEGORY[$normalized];
    }

    public static function getDefaultAdapter(string $productCategory): ?string
    {
        $normalized = self::normalizeProductCategory($productCategory);
        if ($normalized === '' || !self::isValidProductCategory($normalized)) {
            return null;
        }

        return self::DEFAULT_ADAPTER_BY_PRODUCT_CATEGORY[$normalized] ?? null;
    }

    /**
     * @throws ProductCategoryContractException
     */
    public static function assertValidProductCategory(string $category): void
    {
        if (!self::isValidProductCategory($category)) {
            throw new ProductCategoryContractException(
                'INVALID_PRODUCT_CATEGORY',
                'Unknown product_category: ' . $category
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function allSupportedProductCategories(): array
    {
        return self::PRODUCT_CATEGORIES;
    }
}
