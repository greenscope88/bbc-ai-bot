<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceDefinition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogValidationException.php';

/**
 * Loads platform product source catalog from local JSON (Phase 9-B-1a sample; no GCS).
 */
final class ProductSourceCatalogLoader
{
    public const DEFAULT_SAMPLE_PATH = 'docs/sample/product_source_catalog.sample.json';

    private ProductSourceCatalogValidator $validator;

    private bool $validateContract;

    public function __construct(?ProductSourceCatalogValidator $validator = null, bool $validateContract = true)
    {
        $this->validator = $validator ?? new ProductSourceCatalogValidator();
        $this->validateContract = $validateContract;
    }

    /**
     * @return array{
     *   schema_version: int,
     *   catalog_id: string,
     *   sources: array<string, ProductSourceDefinition>
     * }
     */
    public function load(?string $path = null): array
    {
        $resolvedPath = $this->resolvePath($path);
        if (!is_file($resolvedPath)) {
            throw new \RuntimeException('Product source catalog not found: ' . $resolvedPath);
        }

        $raw = file_get_contents($resolvedPath);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read product source catalog: ' . $resolvedPath);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Product source catalog must be valid JSON: ' . $resolvedPath);
        }

        if ($this->validateContract) {
            try {
                $this->validator->validate($decoded);
            } catch (ProductSourceCatalogValidationException $e) {
                throw new \RuntimeException(
                    'Product source catalog contract invalid: ' . $resolvedPath . ' — ' . $e->getMessage(),
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
        ];
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

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_SAMPLE_PATH);
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
