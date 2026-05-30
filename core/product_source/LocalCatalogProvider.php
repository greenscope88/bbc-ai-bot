<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogProviderInterface.php';

/**
 * Reads catalog JSON from local filesystem (Phase 9-B-4).
 */
final class LocalCatalogProvider implements ProductSourceCatalogProviderInterface
{
    public const DEFAULT_SAMPLE_PATH = 'docs/sample/product_source_catalog.sample.json';

    /** @var string */
    private $catalogPath;

    public function __construct(?string $catalogPath = null)
    {
        $this->catalogPath = $this->resolvePath($catalogPath);
    }

    public function getProviderId(): string
    {
        return 'local';
    }

    public function fetchCatalogDocument(): array
    {
        if (!is_file($this->catalogPath)) {
            throw new \RuntimeException('Product source catalog not found: ' . $this->catalogPath);
        }

        $raw = file_get_contents($this->catalogPath);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read product source catalog: ' . $this->catalogPath);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Product source catalog must be valid JSON: ' . $this->catalogPath);
        }

        return $decoded;
    }

    public function getCatalogPath(): string
    {
        return $this->catalogPath;
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

        return strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\');
    }
}
