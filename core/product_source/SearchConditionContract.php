<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';

/**
 * BATS unified search condition contract constants (Phase 9-B-12).
 *
 * Platform-agnostic document shape for SearchUrlBuilder / adapters / future Host B bridge.
 */
final class SearchConditionContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<int> */
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    public const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** @var list<string> */
    public const OPTIONAL_FIELDS = [
        'schema_version',
        'keyword',
        'product_category',
        'destination',
        'region_code',
        'region_name',
        'area',
        'departure_city',
        'date_from',
        'date_to',
        'budget_min',
        'budget_max',
        'travel_style',
        'special_tags',
        'free_text',
        'platform',
        'tenant_instance',
        'confidence',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function normalize(array $document): array
    {
        $out = [];
        foreach (self::OPTIONAL_FIELDS as $field) {
            if (!array_key_exists($field, $document)) {
                continue;
            }
            $out[$field] = $document[$field];
        }

        if (isset($out['schema_version'])) {
            $out['schema_version'] = (int) $out['schema_version'];
        } else {
            $out['schema_version'] = self::SCHEMA_VERSION;
        }

        if (isset($out['keyword']) && is_string($out['keyword'])) {
            $out['keyword'] = trim($out['keyword']);
        }

        if (isset($out['product_category']) && is_string($out['product_category'])) {
            $out['product_category'] = ProductCategoryContract::normalizeProductCategory($out['product_category']);
        }

        foreach (['destination', 'region_code', 'region_name', 'area', 'departure_city', 'date_from', 'date_to', 'free_text', 'platform', 'tenant_instance'] as $field) {
            if (isset($out[$field]) && is_string($out[$field])) {
                $trimmed = trim($out[$field]);
                $out[$field] = $trimmed !== '' ? $trimmed : null;
            }
        }

        if (isset($out['budget_min']) && $out['budget_min'] !== null && $out['budget_min'] !== '') {
            $out['budget_min'] = (int) $out['budget_min'];
        }
        if (isset($out['budget_max']) && $out['budget_max'] !== null && $out['budget_max'] !== '') {
            $out['budget_max'] = (int) $out['budget_max'];
        }

        if (isset($out['confidence'])) {
            $out['confidence'] = (float) $out['confidence'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function hasSearchCriterion(array $document): bool
    {
        $textFields = ['keyword', 'destination', 'region_code', 'area', 'free_text'];
        foreach ($textFields as $field) {
            if (isset($document[$field]) && is_string($document[$field]) && trim($document[$field]) !== '') {
                return true;
            }
        }

        if (isset($document['date_from']) && trim((string) $document['date_from']) !== '') {
            return true;
        }
        if (isset($document['date_to']) && trim((string) $document['date_to']) !== '') {
            return true;
        }
        if (isset($document['budget_min']) && $document['budget_min'] !== null && $document['budget_min'] !== '') {
            return true;
        }
        if (isset($document['budget_max']) && $document['budget_max'] !== null && $document['budget_max'] !== '') {
            return true;
        }

        return false;
    }
}
