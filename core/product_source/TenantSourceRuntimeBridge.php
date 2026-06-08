<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourceLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';

/**
 * Resolves tenant_sno → source_instance_keys for multi-source runtime (Phase 1C).
 *
 * Bridge-only keys may differ from legacy travel_b until tenant product sources
 * align with production multi-source platforms; resolveMultiSourceConfig() applies fallback.
 */
final class TenantSourceRuntimeBridge
{
    public const RESOLVER_ID = 'tenant_source_runtime_bridge_v1';

    public const TRAVEL_B_SNO = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;

    private const DEFAULT_CATALOG_RELATIVE_PATH = 'docs/sample/product_source_catalog.sample.json';

    private const DEFAULT_TRAVEL_B_TENANT_SOURCES_RELATIVE_PATH = 'docs/sample/travel_b_product_sources.sample.json';

    private const DEFAULT_SEARCH_REGISTRY_RELATIVE_PATH = 'config/product_source/travel_b_search_registry.php';

    private const DEFAULT_LEGACY_MULTI_SOURCE_RELATIVE_PATH = 'config/product_source/travel_b_multi_source_links.php';

    private ?string $catalogPath;

    private ?string $searchRegistryPath;

    private ?string $legacyMultiSourcePath;

    public function __construct(
        ?string $catalogPath = null,
        ?string $searchRegistryPath = null,
        ?string $legacyMultiSourcePath = null
    ) {
        $this->catalogPath = $catalogPath;
        $this->searchRegistryPath = $searchRegistryPath;
        $this->legacyMultiSourcePath = $legacyMultiSourcePath;
    }

    /**
     * @return array{
     *   enabled: bool,
     *   tenant_sno: string,
     *   source_instance_keys: list<string>,
     *   resolver_id: string
     * }
     */
    public function resolveMultiSourceConfig(string $tenantSno): array
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            return $this->disabledConfig('');
        }

        $bridgeKeys = $this->resolveBridgeSourceInstanceKeys($tenantSno);
        if ($bridgeKeys !== []) {
            if ($this->isTravelBSno($tenantSno)) {
                $legacyKeys = $this->loadLegacyTravelBSourceInstanceKeys();
                if ($bridgeKeys === $legacyKeys) {
                    return $this->enabledConfig($tenantSno, $bridgeKeys);
                }

                return $this->legacyTravelBFallbackConfig($tenantSno);
            }

            return $this->enabledConfig($tenantSno, $bridgeKeys);
        }

        if ($this->isTravelBSno($tenantSno)) {
            return $this->legacyTravelBFallbackConfig($tenantSno);
        }

        return $this->disabledConfig($tenantSno);
    }

    /**
     * Bridge-only resolution (no legacy fallback). For tests and diagnostics.
     *
     * @return list<string>
     */
    public function resolveBridgeSourceInstanceKeys(string $tenantSno): array
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            return [];
        }

        $tenantSourcesPath = $this->resolveTenantSourcesPath($tenantSno);
        if ($tenantSourcesPath === null) {
            return [];
        }

        try {
            $registry = ProductSourceRegistry::fromLocalFiles(
                $this->resolveCatalogPath(),
                $tenantSourcesPath
            );
        } catch (\Throwable $e) {
            return [];
        }

        if ($registry->getTenantSno() !== $tenantSno) {
            return [];
        }

        return $this->mapEnabledSourcesToInstanceKeys($registry, $tenantSno);
    }

    /**
     * @return list<string>
     */
    public function loadLegacyTravelBSourceInstanceKeys(): array
    {
        $legacy = $this->loadLegacyTravelBMultiSourceConfig();

        $keys = $legacy['source_instance_keys'] ?? [];
        if (!is_array($keys)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $keys), static function (string $key): bool {
            return trim($key) !== '';
        }));
    }

    /**
     * @return array<string, mixed>
     */
    public function loadLegacyTravelBMultiSourceConfig(): array
    {
        $path = $this->resolveLegacyMultiSourcePath();
        if (!is_file($path)) {
            return [
                'enabled' => false,
                'tenant_sno' => self::TRAVEL_B_SNO,
                'source_instance_keys' => [],
            ];
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        return is_array($loaded) ? $loaded : [
            'enabled' => false,
            'tenant_sno' => self::TRAVEL_B_SNO,
            'source_instance_keys' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function mapEnabledSourcesToInstanceKeys(ProductSourceRegistry $registry, string $tenantSno): array
    {
        $instances = $this->loadSearchRegistryInstances();
        if ($instances === []) {
            return [];
        }

        $keys = [];
        foreach ($registry->getEnabledSourceIds() as $sourceId) {
            foreach ($this->mapSourceIdToPlatformIds($sourceId) as $platformId) {
                $instanceKey = $this->findTenantInstanceKey($instances, $tenantSno, $platformId);
                if ($instanceKey === null || in_array($instanceKey, $keys, true)) {
                    continue;
                }
                $keys[] = $instanceKey;
            }
        }

        return $keys;
    }

    /**
     * Maps catalog tenant source_id to search-registry platform_id values.
     *
     * @return list<string>
     */
    private function mapSourceIdToPlatformIds(string $sourceId): array
    {
        $normalized = trim($sourceId);
        if ($normalized === '') {
            return [];
        }

        // bbcshops is storefront (bonusmee); external multi-source block uses bbctravel platform.
        if ($normalized === 'bbcshops') {
            return [];
        }

        return [$normalized];
    }

    /**
     * @param array<string, array<string, mixed>> $instances
     */
    private function findTenantInstanceKey(array $instances, string $tenantSno, string $platformId): ?string
    {
        foreach ($instances as $mapKey => $instance) {
            if (!is_array($instance)) {
                continue;
            }

            $instanceSno = isset($instance['tenant_sno']) ? trim((string) $instance['tenant_sno']) : '';
            if ($instanceSno !== $tenantSno) {
                continue;
            }

            $instancePlatform = isset($instance['platform_id']) ? trim((string) $instance['platform_id']) : '';
            if ($instancePlatform !== $platformId) {
                continue;
            }

            if (isset($instance['enabled']) && (bool) $instance['enabled'] !== true) {
                continue;
            }

            if (isset($instance['tenant_instance_key']) && trim((string) $instance['tenant_instance_key']) !== '') {
                return trim((string) $instance['tenant_instance_key']);
            }

            if (is_string($mapKey) && trim($mapKey) !== '') {
                return trim($mapKey);
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadSearchRegistryInstances(): array
    {
        $path = $this->resolveSearchRegistryPath();
        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            return [];
        }

        $instances = $loaded['instances'] ?? [];
        if (!is_array($instances)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $out */
        $out = [];
        foreach ($instances as $key => $instance) {
            if (!is_string($key) || !is_array($instance)) {
                continue;
            }
            $out[$key] = $instance;
        }

        return $out;
    }

    private function resolveTenantSourcesPath(string $tenantSno): ?string
    {
        if ($this->isTravelBSno($tenantSno)) {
            return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                self::DEFAULT_TRAVEL_B_TENANT_SOURCES_RELATIVE_PATH
            );
        }

        return null;
    }

    private function resolveCatalogPath(): string
    {
        if ($this->catalogPath !== null && trim($this->catalogPath) !== '') {
            return $this->resolveAbsolutePath($this->catalogPath);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            self::DEFAULT_CATALOG_RELATIVE_PATH
        );
    }

    private function resolveSearchRegistryPath(): string
    {
        if ($this->searchRegistryPath !== null && trim($this->searchRegistryPath) !== '') {
            return $this->resolveAbsolutePath($this->searchRegistryPath);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            self::DEFAULT_SEARCH_REGISTRY_RELATIVE_PATH
        );
    }

    private function resolveLegacyMultiSourcePath(): string
    {
        if ($this->legacyMultiSourcePath !== null && trim($this->legacyMultiSourcePath) !== '') {
            return $this->resolveAbsolutePath($this->legacyMultiSourcePath);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            self::DEFAULT_LEGACY_MULTI_SOURCE_RELATIVE_PATH
        );
    }

    private function resolveAbsolutePath(string $path): string
    {
        $trimmed = trim($path);
        if ($this->isAbsolutePath($trimmed)) {
            return $trimmed;
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
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

        return strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\');
    }

    private function isTravelBSno(string $tenantSno): bool
    {
        return trim($tenantSno) === self::TRAVEL_B_SNO;
    }

    /**
     * @param list<string> $keys
     * @return array{enabled: bool, tenant_sno: string, source_instance_keys: list<string>, resolver_id: string}
     */
    private function enabledConfig(string $tenantSno, array $keys): array
    {
        return [
            'enabled' => true,
            'tenant_sno' => $tenantSno,
            'source_instance_keys' => $keys,
            'resolver_id' => self::RESOLVER_ID,
        ];
    }

    /**
     * @return array{enabled: bool, tenant_sno: string, source_instance_keys: list<string>, resolver_id: string}
     */
    private function legacyTravelBFallbackConfig(string $tenantSno): array
    {
        $legacy = $this->loadLegacyTravelBMultiSourceConfig();
        $keys = $this->loadLegacyTravelBSourceInstanceKeys();
        $enabled = isset($legacy['enabled']) && (bool) $legacy['enabled'] === true && $keys !== [];

        return [
            'enabled' => $enabled,
            'tenant_sno' => $tenantSno,
            'source_instance_keys' => $keys,
            'resolver_id' => self::RESOLVER_ID,
        ];
    }

    /**
     * @return array{enabled: bool, tenant_sno: string, source_instance_keys: list<string>, resolver_id: string}
     */
    private function disabledConfig(string $tenantSno): array
    {
        return [
            'enabled' => false,
            'tenant_sno' => $tenantSno,
            'source_instance_keys' => [],
            'resolver_id' => self::RESOLVER_ID,
        ];
    }
}
