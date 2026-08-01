<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourcesProviderInterface.php';

/**
 * Reads tenant product sources JSON from local filesystem (Phase 9-B-5).
 */
final class LocalTenantProductSourcesProvider implements TenantProductSourcesProviderInterface
{
    /**
     * @deprecated No longer used as a fallback path. Kept only because
     * TenantProductSourceLoader::DEFAULT_TRAVEL_B_SAMPLE_PATH still references this
     * constant. The constructor now requires an explicit config_path (fail-closed).
     */
    public const DEFAULT_TRAVEL_B_SAMPLE_PATH = 'docs/sample/travel_b_product_sources.sample.json';

    /** @var string */
    private $configPath;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $this->resolvePath($configPath);
    }

    public function getProviderId(): string
    {
        return 'local';
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function fetchTenantProductSourcesDocument(): array
    {
        if (!is_file($this->configPath)) {
            throw new \RuntimeException('Tenant product sources config not found: ' . $this->configPath);
        }

        $raw = file_get_contents($this->configPath);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read tenant product sources: ' . $this->configPath);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tenant product sources must be valid JSON: ' . $this->configPath);
        }

        return $decoded;
    }

    private function resolvePath(?string $path): string
    {
        $trimmed = $path !== null ? trim($path) : '';
        if ($trimmed === '') {
            throw new \InvalidArgumentException(
                'LocalTenantProductSourcesProvider: config_path is required (no default tenant fallback).'
            );
        }

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
}
