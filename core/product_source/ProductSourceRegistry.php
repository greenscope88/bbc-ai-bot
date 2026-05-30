<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourceLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceDefinition.php';

/**
 * Read-only registry: platform catalog + tenant enabled sources (Phase 9-B-1a MVP).
 *
 * No GCS, Host B, Hybrid, LINE, or SearchUrlBuilder integration.
 */
final class ProductSourceRegistry
{
    /** @var array<string, ProductSourceDefinition> */
    private $catalogById = [];

    private int $catalogSchemaVersion = 0;

    /** @var string */
    private $catalogId = '';

    /** @var string */
    private $tenantSno = '';

    /** @var string */
    private $tenantKey = '';

    /** @var string */
    private $defaultCategory = 'group_tour';

    /** @var list<array{source_id: string, priority: int, product_categories: list<string>}> */
    private $tenantEnabledRows = [];

    /**
     * @param array<string, ProductSourceDefinition> $catalogById
     * @param list<array{source_id: string, priority: int, product_categories: list<string>}> $tenantEnabledRows
     */
    private function __construct(
        array $catalogById,
        int $catalogSchemaVersion,
        string $catalogId,
        string $tenantSno,
        string $tenantKey,
        string $defaultCategory,
        array $tenantEnabledRows
    ) {
        $this->catalogById = $catalogById;
        $this->catalogSchemaVersion = $catalogSchemaVersion;
        $this->catalogId = $catalogId;
        $this->tenantSno = $tenantSno;
        $this->tenantKey = $tenantKey;
        $this->defaultCategory = $defaultCategory;
        $this->tenantEnabledRows = $tenantEnabledRows;
    }

    /**
     * Load registry from sample / local JSON paths.
     */
    public static function fromLocalFiles(?string $catalogPath = null, ?string $tenantSourcesPath = null): self
    {
        $catalogLoader = new ProductSourceCatalogLoader();
        $tenantLoader = new TenantProductSourceLoader();

        $catalog = $catalogLoader->load($catalogPath);
        $tenant = $tenantLoader->load($tenantSourcesPath);

        return new self(
            $catalog['sources'],
            $catalog['schema_version'],
            $catalog['catalog_id'],
            $tenant['tenant_sno'],
            $tenant['tenant_key'],
            $tenant['default_category'],
            $tenant['enabled_sources']
        );
    }

    public function getCatalogSchemaVersion(): int
    {
        return $this->catalogSchemaVersion;
    }

    public function getCatalogId(): string
    {
        return $this->catalogId;
    }

    public function getTenantSno(): string
    {
        return $this->tenantSno;
    }

    public function getTenantKey(): string
    {
        return $this->tenantKey;
    }

    public function getDefaultCategory(): string
    {
        return $this->defaultCategory;
    }

    /**
     * All sources defined in platform catalog (including catalog-disabled).
     *
     * @return list<ProductSourceDefinition>
     */
    public function getAllCatalogSources(): array
    {
        return array_values($this->catalogById);
    }

    /**
     * Catalog entry by source_id.
     */
    public function getCatalogSource(string $sourceId): ?ProductSourceDefinition
    {
        $key = trim($sourceId);
        if ($key === '') {
            return null;
        }

        return $this->catalogById[$key] ?? null;
    }

    /**
     * Tenant-enabled sources merged with catalog definitions (sorted by priority).
     *
     * @return list<ProductSourceDefinition>
     */
    public function getEnabledSources(): array
    {
        $out = [];
        foreach ($this->tenantEnabledRows as $row) {
            $definition = $this->catalogById[$row['source_id']] ?? null;
            if ($definition === null) {
                continue;
            }
            if (!$definition->isCatalogEnabled()) {
                continue;
            }
            if (!$definition->supportsSearch()) {
                continue;
            }
            $out[] = $definition;
        }

        return $out;
    }

    /**
     * Enabled sources for tenant that support the given product category.
     *
     * @return list<ProductSourceDefinition>
     */
    public function getEnabledSourcesByCategory(string $category): array
    {
        $needle = trim($category);
        if ($needle === '') {
            return [];
        }

        $enabled = [];
        foreach ($this->tenantEnabledRows as $row) {
            $tenantCategories = $row['product_categories'];
            $definition = $this->catalogById[$row['source_id']] ?? null;
            if ($definition === null || !$definition->isCatalogEnabled() || !$definition->supportsSearch()) {
                continue;
            }

            $matchesTenant = $tenantCategories === [] || in_array($needle, $tenantCategories, true);
            $matchesCatalog = $definition->supportsProductCategory($needle);
            if ($matchesTenant && $matchesCatalog) {
                $enabled[] = $definition;
            }
        }

        return $enabled;
    }

    /**
     * Single enabled catalog source by id (null if not enabled for tenant).
     */
    public function getEnabledSource(string $sourceId): ?ProductSourceDefinition
    {
        $key = trim($sourceId);
        if ($key === '') {
            return null;
        }

        foreach ($this->getEnabledSources() as $definition) {
            if ($definition->getSourceId() === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getEnabledSourceIds(): array
    {
        $ids = [];
        foreach ($this->getEnabledSources() as $definition) {
            $ids[] = $definition->getSourceId();
        }

        return $ids;
    }
}
