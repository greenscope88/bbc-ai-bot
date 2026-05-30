<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GcsCatalogProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LocalCatalogProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogProviderInterface.php';

/**
 * Creates catalog providers for local vs GCS (Phase 9-B-4).
 */
final class CatalogProviderFactory
{
    public const MODE_LOCAL = 'local';

    public const MODE_GCS = 'gcs';

    public static function create(string $mode, ?string $localCatalogPath = null, ?string $gcsObjectPath = null): ProductSourceCatalogProviderInterface
    {
        $normalized = strtolower(trim($mode));

        if ($normalized === self::MODE_LOCAL) {
            return new LocalCatalogProvider($localCatalogPath);
        }

        if ($normalized === self::MODE_GCS) {
            return new GcsCatalogProvider($gcsObjectPath);
        }

        throw new \InvalidArgumentException('Unknown catalog provider mode: ' . $mode);
    }

    /**
     * Default for Host A development: local sample JSON.
     */
    public static function createDefault(): ProductSourceCatalogProviderInterface
    {
        return self::create(self::MODE_LOCAL);
    }
}
