<?php
declare(strict_types=1);

/**
 * Loads per-tenant enabled product sources from local JSON (Phase 9-B-1a sample; no GCS).
 */
final class TenantProductSourceLoader
{
    public const DEFAULT_TRAVEL_B_SAMPLE_PATH = 'docs/sample/travel_b_product_sources.sample.json';

    /**
     * @return array{
     *   schema_version: int,
     *   tenant_sno: string,
     *   tenant_key: string,
     *   default_category: string,
     *   enabled_sources: list<array{source_id: string, priority: int, product_categories: list<string>}>
     * }
     */
    public function load(?string $path = null): array
    {
        $resolvedPath = $this->resolvePath($path);
        if (!is_file($resolvedPath)) {
            throw new \RuntimeException('Tenant product sources config not found: ' . $resolvedPath);
        }

        $raw = file_get_contents($resolvedPath);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read tenant product sources: ' . $resolvedPath);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tenant product sources must be valid JSON: ' . $resolvedPath);
        }

        $tenantSno = isset($decoded['tenant_sno']) ? trim((string) $decoded['tenant_sno']) : '';
        if ($tenantSno === '') {
            throw new \RuntimeException('Tenant product sources missing tenant_sno.');
        }

        $enabled = $decoded['enabled_sources'] ?? [];
        if (!is_array($enabled)) {
            throw new \RuntimeException('Tenant product sources missing enabled_sources array.');
        }

        /** @var list<array{source_id: string, priority: int, product_categories: list<string>}> $normalized */
        $normalized = [];
        foreach ($enabled as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceId = isset($row['source_id']) ? trim((string) $row['source_id']) : '';
            if ($sourceId === '') {
                continue;
            }
            $normalized[] = [
                'source_id' => $sourceId,
                'priority' => isset($row['priority']) ? (int) $row['priority'] : 100,
                'product_categories' => $this->normalizeStringList($row['product_categories'] ?? []),
            ];
        }

        if ($normalized === []) {
            throw new \RuntimeException('Tenant product sources contains no enabled_sources entries.');
        }

        usort($normalized, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'];
        });

        return [
            'schema_version' => isset($decoded['schema_version']) ? (int) $decoded['schema_version'] : 0,
            'tenant_sno' => $tenantSno,
            'tenant_key' => isset($decoded['tenant_key']) ? trim((string) $decoded['tenant_key']) : '',
            'default_category' => isset($decoded['default_category']) ? trim((string) $decoded['default_category']) : 'group_tour',
            'enabled_sources' => $normalized,
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    private function resolvePath(?string $path): string
    {
        if ($path !== null && trim($path) !== '') {
            $trimmed = trim($path);
            if ($this->isAbsolutePath($trimmed)) {
                return $trimmed;
            }

            return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_TRAVEL_B_SAMPLE_PATH);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        if (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\')) {
            return true;
        }

        return false;
    }
}
