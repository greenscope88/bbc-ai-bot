<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'CatalogProviderFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LocalCatalogProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceDefinition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogProviderInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidationException.php';

/**
 * Loads platform product source catalog via provider abstraction (Phase 9-B-1a / 9-B-4).
 */
final class ProductSourceCatalogLoader
{
    public const DEFAULT_SAMPLE_PATH = LocalCatalogProvider::DEFAULT_SAMPLE_PATH;

    private ProductSourceCatalogProviderInterface $provider;

    private ProductSourceCatalogValidator $validator;

    private bool $validateContract;

    public function __construct(
        ?ProductSourceCatalogProviderInterface $provider = null,
        ?ProductSourceCatalogValidator $validator = null,
        bool $validateContract = true
    ) {
        $this->provider = $provider ?? CatalogProviderFactory::createDefault();
        $this->validator = $validator ?? new ProductSourceCatalogValidator();
        $this->validateContract = $validateContract;
    }

    public function getProvider(): ProductSourceCatalogProviderInterface
    {
        return $this->provider;
    }

    /**
     * @return array{
     *   schema_version: int,
     *   catalog_id: string,
     *   sources: array<string, ProductSourceDefinition>,
     *   provider_id: string
     * }
     */
    public function load(?string $path = null): array
    {
        $provider = $this->provider;
        if ($path !== null && trim($path) !== '') {
            $provider = new LocalCatalogProvider($path);
        }

        $decoded = $provider->fetchCatalogDocument();

        if ($this->validateContract) {
            try {
                $this->validator->validate($decoded);
            } catch (ProductSourceCatalogValidationException $e) {
                throw new \RuntimeException(
                    'Product source catalog contract invalid (provider=' . $provider->getProviderId() . '): '
                    . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $sources = $decoded['sources'] ?? [];
        if (!is_array($sources)) {
            throw new \RuntimeException('Product source catalog missing sources array.');
        }

        /** @var array<string, ProductSourceDefinition> $byId */
        $byId = [];
        foreach ($sources as $row) {
            if (!is_array($row)) {
                continue;
            }
            $definition = ProductSourceDefinition::fromCatalogRow($row);
            $byId[$definition->getSourceId()] = $definition;
        }

        if ($byId === []) {
            throw new \RuntimeException('Product source catalog contains no valid sources.');
        }

        return [
            'schema_version' => isset($decoded['schema_version']) ? (int) $decoded['schema_version'] : 0,
            'catalog_id' => isset($decoded['catalog_id']) ? trim((string) $decoded['catalog_id']) : '',
            'sources' => $byId,
            'provider_id' => $provider->getProviderId(),
        ];
    }
}
