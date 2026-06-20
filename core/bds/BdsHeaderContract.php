<?php
declare(strict_types=1);

/**
 * BDS Upload / Sheet header row contract (Phase 6A).
 *
 * @see docs/BATS_DATA_CONTRACT.md §7
 */
final class BdsHeaderContract
{
    /** @var array<string, list<string>> */
    public const ALLOWED_HEADERS = [
        'company_profile' => [
            'company_name',
            'summary',
            'phone',
            'address',
            'line_official',
            'email',
            'website',
            'business_hours',
        ],
        'qa' => [
            'question',
            'answer',
        ],
        'external_product_links' => [
            'name',
            'url',
        ],
        'service_items' => [
            'name',
        ],
        'special_prices' => [
            'item_name',
            'price_amount',
        ],
    ];

    /** @var array<string, list<string>> */
    public const REQUIRED_HEADERS = [
        'company_profile' => [
            'company_name',
            'summary',
            'phone',
            'address',
            'line_official',
            'email',
            'website',
            'business_hours',
        ],
        'qa' => [
            'question',
            'answer',
        ],
        'external_product_links' => [
            'name',
            'url',
        ],
        'service_items' => [
            'name',
        ],
        'special_prices' => [
            'item_name',
            'price_amount',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function allowedHeaders(string $tab): array
    {
        return self::ALLOWED_HEADERS[$tab] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function requiredHeaders(string $tab): array
    {
        return self::REQUIRED_HEADERS[$tab] ?? [];
    }
}
