<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';

/**
 * BATS publisher intermediary contract (Phase 9-B-16).
 *
 * Channel-agnostic publishable content derived from search results.
 * Must not contain LINE Flex JSON, Gemini prompts, HTML templates, or Telegram payloads.
 */
final class PublisherContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<int> */
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    /** @var list<string> */
    public const ACTION_TYPES = [
        'open_url',
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS = [
        'tenant_instance',
        'source_platform',
        'product_category',
        'title',
        'primary_url',
    ];

    /** @var list<string> */
    public const OPTIONAL_FIELDS = [
        'schema_version',
        'summary',
        'secondary_urls',
        'actions',
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

        foreach (['tenant_instance', 'source_platform', 'title'] as $field) {
            if (isset($out[$field]) && is_string($out[$field])) {
                $out[$field] = trim($out[$field]);
            }
        }

        if (isset($out['product_category']) && is_string($out['product_category'])) {
            $out['product_category'] = ProductCategoryContract::normalizeProductCategory($out['product_category']);
        }

        if (isset($out['summary']) && is_string($out['summary'])) {
            $trimmed = trim($out['summary']);
            $out['summary'] = $trimmed !== '' ? $trimmed : null;
        }

        if (isset($out['primary_url']) && is_string($out['primary_url'])) {
            $out['primary_url'] = trim($out['primary_url']);
        }

        if (!isset($out['secondary_urls']) || !is_array($out['secondary_urls'])) {
            $out['secondary_urls'] = [];
        } else {
            $out['secondary_urls'] = self::normalizeUrlList($out['secondary_urls']);
        }

        if (!isset($out['actions']) || !is_array($out['actions'])) {
            $out['actions'] = [];
        } else {
            $out['actions'] = self::normalizeActions($out['actions']);
        }

        if (!isset($out['metadata']) || !is_array($out['metadata'])) {
            $out['metadata'] = [];
        }

        return $out;
    }

    /**
     * @param list<mixed> $urls
     * @return list<string>
     */
    private static function normalizeUrlList(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            $trimmed = trim($url);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $actions
     * @return list<array{type: string, label: string, url: string}>
     */
    private static function normalizeActions(array $actions): array
    {
        $normalized = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = isset($action['type']) ? trim((string) $action['type']) : '';
            $label = isset($action['label']) ? trim((string) $action['label']) : '';
            $url = isset($action['url']) ? trim((string) $action['url']) : '';

            if ($type === '' && $label === '' && $url === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'label' => $label,
                'url' => $url,
            ];
        }

        return $normalized;
    }
}
