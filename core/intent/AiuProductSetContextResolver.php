<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuProductSetContext.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'ProductSourceRegistry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SearchConditionContract.php';

/**
 * B0-LINE-01D-3J-11: resolves the authoritative tenant-scoped AiuProductSetContext
 * from the existing product-source authority (ProductSourceRegistry) and the
 * existing executable-search-dimension authority (SearchConditionContract).
 *
 * Fail-closed: never catches/defaults on registry load failure, tenant mismatch,
 * or an empty resolved category union.
 */
final class AiuProductSetContextResolver
{
    private ?ProductSourceRegistry $registry;

    /** @var callable|null */
    private $registryFactory;

    /**
     * @param callable|null $registryFactory Optional test hook: (): ProductSourceRegistry
     */
    public function __construct(?ProductSourceRegistry $registry = null, ?callable $registryFactory = null)
    {
        $this->registry = $registry;
        $this->registryFactory = $registryFactory;
    }

    public function resolve(string $tenantSno): AiuProductSetContext
    {
        $trimmedTenantSno = trim($tenantSno);
        if ($trimmedTenantSno === '') {
            throw new \InvalidArgumentException('AiuProductSetContextResolver: tenant_sno must not be blank');
        }

        $registry = $this->obtainRegistry();
        if ($registry->getTenantSno() !== $trimmedTenantSno) {
            throw new \RuntimeException(
                'AiuProductSetContextResolver: tenant scope mismatch — requested tenant_sno does not match '
                . 'the loaded product-source registry tenant; refusing to borrow another tenant\'s product-set context'
            );
        }

        $categories = $this->resolveSearchableProductCategories($registry);
        $dimensions = array_values(SearchConditionContract::OPTIONAL_FIELDS);
        $searchDomain = $registry->getCatalogId() . ':' . $registry->getTenantKey();

        return new AiuProductSetContext(
            $trimmedTenantSno,
            $searchDomain,
            $categories,
            $dimensions,
            AiuProductSetContext::RESOLUTION_STATUS_RESOLVED
        );
    }

    /**
     * @return list<string>
     */
    private function resolveSearchableProductCategories(ProductSourceRegistry $registry): array
    {
        $categories = [];
        foreach ($registry->getEnabledSources() as $source) {
            foreach ($source->getProductCategories() as $category) {
                if (!in_array($category, $categories, true)) {
                    $categories[] = $category;
                }
            }
        }

        if ($categories === []) {
            throw new \RuntimeException(
                'AiuProductSetContextResolver: resolved zero searchable product categories for tenant'
            );
        }

        return $categories;
    }

    private function obtainRegistry(): ProductSourceRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        if ($this->registryFactory !== null) {
            $registry = ($this->registryFactory)();
            if (!$registry instanceof ProductSourceRegistry) {
                throw new \RuntimeException(
                    'AiuProductSetContextResolver: registryFactory must return a ProductSourceRegistry'
                );
            }

            return $registry;
        }

        return ProductSourceRegistry::fromLocalFiles();
    }
}
