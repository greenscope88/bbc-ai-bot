<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GcsTenantProductSourcesProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LocalTenantProductSourcesProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourcesProviderFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourcesProviderInterface.php';

/**
 * Loads per-tenant enabled product sources via provider abstraction (Phase 9-B-1a / 9-B-5).
 */
final class TenantProductSourceLoader
{
    public const DEFAULT_TRAVEL_B_SAMPLE_PATH = LocalTenantProductSourcesProvider::DEFAULT_TRAVEL_B_SAMPLE_PATH;

    private TenantProductSourcesProviderInterface $provider;

    public function __construct(?TenantProductSourcesProviderInterface $provider = null)
    {
        $this->provider = $provider ?? TenantProductSourcesProviderFactory::createDefault();
    }

    public function getProvider(): TenantProductSourcesProviderInterface
    {
        return $this->provider;
    }

    /**
     * @return array{
     *   schema_version: int,
     *   tenant_sno: string,
     *   tenant_key: string,
     *   default_category: string,
     *   enabled_sources: list<array{source_id: string, priority: int, product_categories: list<string>}>,
     *   provider_id: string
     * }
     */
    public function load(?string $path = null): array
    {
        $provider = $this->provider;
        if ($path !== null && trim($path) !== '') {
            $provider = new LocalTenantProductSourcesProvider($path);
        }

        $decoded = $provider->fetchTenantProductSourcesDocument();

        return $this->normalizeDocument($decoded, $provider->getProviderId());
    }

    /**
     * Safe fallback: returns empty enabled_sources on provider or normalization errors.
     *
     * @return array{
     *   schema_version: int,
     *   tenant_sno: string,
     *   tenant_key: string,
     *   default_category: string,
     *   enabled_sources: list<array{source_id: string, priority: int, product_categories: list<string>}>,
     *   provider_id: string
     * }
     */
    public function loadSafe(?string $path = null): array
    {
        try {
            return $this->load($path);
        } catch (\Throwable $e) {
            $providerId = $this->provider->getProviderId();
            if ($path !== null && trim($path) !== '') {
                $providerId = 'local';
            }

            $tenantSno = '';
            if ($this->provider instanceof GcsTenantProductSourcesProvider) {
                $tenantSno = $this->provider->getTenantSno();
            }

            return [
                'schema_version' => 1,
                'tenant_sno' => $tenantSno,
                'tenant_key' => '',
                'default_category' => 'group_tour',
                'enabled_sources' => [],
                'provider_id' => $providerId,
            ];
        }
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{
     *   schema_version: int,
     *   tenant_sno: string,
     *   tenant_key: string,
     *   default_category: string,
     *   enabled_sources: list<array{source_id: string, priority: int, product_categories: list<string>}>,
     *   provider_id: string
     * }
     */
    private function normalizeDocument(array $decoded, string $providerId): array
    {
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
            'provider_id' => $providerId,
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
}
