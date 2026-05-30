<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';

/**
 * BATS unified cross-platform product search result contract (Phase 9-B-15).
 *
 * Platform- and channel-agnostic. Category-specific fields belong in metadata only.
 * Must not contain LINE Flex, Gemini prompts, HTML, or Telegram payloads.
 */
final class SourceSearchResultContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<int> */
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    /** @var list<string> */
    public const REQUIRED_FIELDS = [
        'source_platform',
        'tenant_instance',
        'product_category',
        'title',
        'search_url',
    ];

    /** @var list<string> */
    public const OPTIONAL_FIELDS = [
        'schema_version',
        'summary',
        'detail_url',
        'metadata',
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

        foreach (self::REQUIRED_FIELDS as $field) {
            if (array_key_exists($field, $document)) {
                $out[$field] = $document[$field];
            }
        }

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

        foreach (['source_platform', 'tenant_instance', 'title'] as $field) {
            if (isset($out[$field]) && is_string($out[$field])) {
                $out[$field] = trim($out[$field]);
            }
        }

        if (isset($out['product_category']) && is_string($out['product_category'])) {
            $out['product_category'] = ProductCategoryContract::normalizeProductCategory($out['product_category']);
        }

        foreach (['summary', 'detail_url', 'search_url'] as $field) {
            if (!isset($out[$field]) || !is_string($out[$field])) {
                continue;
            }
            $trimmed = trim($out[$field]);
            $out[$field] = $trimmed !== '' ? $trimmed : null;
        }

        if (!isset($out['metadata']) || !is_array($out['metadata'])) {
            $out['metadata'] = [];
        }

        return $out;
    }
}
