<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidationException.php';

/**
 * Validates product source catalog JSON against Phase 9-B-1b contract.
 */
final class ProductSourceCatalogValidator
{
    private const REQUIRED_SOURCE_FIELDS = [
        'source_id',
        'source_name',
        'source_type',
        'product_categories',
        'adapter',
        'url_template_id',
        'enabled',
        'priority',
        'short_url_enabled',
        'short_url_domain',
        'domain_namespace',
        'supports_search',
        'supports_detail',
        'supports_inventory',
        'supports_registration_flow',
        'supports_google_sheet_registration',
    ];

    /**
     * @param array<string, mixed> $document Decoded catalog root object.
     */
    public function validate(array $document): void
    {
        $violations = [];

        if (!isset($document['schema_version'])) {
            $violations[] = 'Missing required field: schema_version';
        } else {
            $version = (int) $document['schema_version'];
            if (!in_array($version, ProductSourceCatalogContract::SUPPORTED_SCHEMA_VERSIONS, true)) {
                $violations[] = 'Unsupported schema_version: ' . $version;
            }
        }

        if (!isset($document['catalog_id']) || trim((string) $document['catalog_id']) === '') {
            $violations[] = 'Missing or empty required field: catalog_id';
        }

        $sources = $document['sources'] ?? null;
        if (!is_array($sources) || $sources === []) {
            $violations[] = 'Missing or empty required field: sources';
        }

        if ($violations !== []) {
            $this->throwViolations($violations);
        }

        /** @var array<int, mixed> $sources */
        $seenIds = [];
        foreach ($sources as $index => $row) {
            $path = 'sources[' . $index . ']';
            if (!is_array($row)) {
                $violations[] = $path . ': entry must be an object';
                continue;
            }

            $violations = array_merge($violations, $this->validateSourceRow($row, $path, $seenIds));
        }

        if ($violations !== []) {
            $this->throwViolations($violations);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, true> $seenIds
     * @return list<string>
     */
    private function validateSourceRow(array $row, string $path, array &$seenIds): array
    {
        $violations = [];

        foreach (self::REQUIRED_SOURCE_FIELDS as $field) {
            if ($field === 'source_name' && isset($row['source_name'])) {
                continue;
            }
            if ($field === 'source_name' && isset($row['display_name']) && trim((string) $row['display_name']) !== '') {
                continue;
            }
            if ($field === 'product_categories' && isset($row['product_category']) && trim((string) $row['product_category']) !== '') {
                continue;
            }
            if (!array_key_exists($field, $row)) {
                $violations[] = $path . ': missing required field ' . $field;
            }
        }

        $sourceId = isset($row['source_id']) ? trim((string) $row['source_id']) : '';
        if ($sourceId === '') {
            $violations[] = $path . ': source_id must be non-empty';
        } elseif (!preg_match(ProductSourceCatalogContract::SOURCE_ID_PATTERN, $sourceId)) {
            $violations[] = $path . ': invalid source_id format "' . $sourceId . '"';
        } elseif (isset($seenIds[$sourceId])) {
            $violations[] = $path . ': duplicate source_id "' . $sourceId . '"';
        } else {
            $seenIds[$sourceId] = true;
        }

        $sourceName = '';
        if (isset($row['source_name'])) {
            $sourceName = trim((string) $row['source_name']);
        } elseif (isset($row['display_name'])) {
            $sourceName = trim((string) $row['display_name']);
        }
        if ($sourceName === '') {
            $violations[] = $path . ': source_name (or display_name) must be non-empty';
        }

        $sourceType = isset($row['source_type']) ? trim((string) $row['source_type']) : '';
        if ($sourceType === '' || !ProductSourceCatalogContract::isValidSourceType($sourceType)) {
            $violations[] = $path . ': invalid source_type "' . $sourceType . '"';
        }

        $categories = $this->resolveProductCategories($row);
        if ($categories === []) {
            $violations[] = $path . ': product_categories must contain at least one category';
        } else {
            foreach ($categories as $cat) {
                if (!ProductSourceCatalogContract::isValidProductCategory($cat)) {
                    $violations[] = $path . ': invalid product_category "' . $cat . '"';
                }
            }
        }

        $adapter = isset($row['adapter']) ? trim((string) $row['adapter']) : '';
        if ($adapter === '') {
            $violations[] = $path . ': adapter must be non-empty';
        } elseif (!ProductSourceCatalogContract::isKnownAdapter($adapter)) {
            $violations[] = $path . ': unknown adapter "' . $adapter . '"';
        }

        $templateId = isset($row['url_template_id']) ? trim((string) $row['url_template_id']) : '';
        if ($templateId === '' || !preg_match(ProductSourceCatalogContract::URL_TEMPLATE_ID_PATTERN, $templateId)) {
            $violations[] = $path . ': invalid url_template_id "' . $templateId . '"';
        }

        if (!isset($row['enabled']) || !is_bool($row['enabled'])) {
            $violations[] = $path . ': enabled must be boolean';
        }

        if (!isset($row['priority']) || !is_int($row['priority'])) {
            $violations[] = $path . ': priority must be integer';
        } elseif ((int) $row['priority'] < 0 || (int) $row['priority'] > 9999) {
            $violations[] = $path . ': priority out of range (0-9999)';
        }

        $violations = array_merge($violations, $this->validateCapabilityBooleans($row, $path));
        $violations = array_merge($violations, $this->validateShortUrlFields($row, $path));

        if (
            isset($row['supports_google_sheet_registration'])
            && $row['supports_google_sheet_registration'] === true
            && $sourceType !== ''
            && $sourceType !== 'storefront'
        ) {
            $violations[] = $path . ': supports_google_sheet_registration only allowed for source_type storefront';
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function resolveProductCategories(array $row): array
    {
        if (isset($row['product_categories']) && is_array($row['product_categories'])) {
            $out = [];
            foreach ($row['product_categories'] as $item) {
                if (is_string($item)) {
                    $trimmed = trim($item);
                    if ($trimmed !== '' && !in_array($trimmed, $out, true)) {
                        $out[] = $trimmed;
                    }
                }
            }

            return $out;
        }

        if (isset($row['product_category']) && is_string($row['product_category'])) {
            $single = trim($row['product_category']);

            return $single !== '' ? [$single] : [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function validateCapabilityBooleans(array $row, string $path): array
    {
        $violations = [];
        foreach (
            [
                'supports_search',
                'supports_detail',
                'supports_inventory',
                'supports_registration_flow',
                'supports_google_sheet_registration',
            ] as $field
        ) {
            if (!isset($row[$field]) || !is_bool($row[$field])) {
                $violations[] = $path . ': ' . $field . ' must be boolean';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function validateShortUrlFields(array $row, string $path): array
    {
        $violations = [];

        if (!isset($row['short_url_enabled']) || !is_bool($row['short_url_enabled'])) {
            $violations[] = $path . ': short_url_enabled must be boolean';

            return $violations;
        }

        $enabled = (bool) $row['short_url_enabled'];
        $domain = isset($row['short_url_domain']) ? trim((string) $row['short_url_domain']) : '';
        $namespace = isset($row['domain_namespace']) ? trim((string) $row['domain_namespace']) : '';

        if ($enabled) {
            if ($domain === '') {
                $violations[] = $path . ': short_url_domain required when short_url_enabled is true';
            }
            if ($namespace === '') {
                $violations[] = $path . ': domain_namespace required when short_url_enabled is true';
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $violations
     */
    private function throwViolations(array $violations): void
    {
        throw new ProductSourceCatalogValidationException(
            'CATALOG_CONTRACT_INVALID',
            'Product source catalog failed contract validation (' . count($violations) . ' issue(s)).',
            $violations
        );
    }
}
