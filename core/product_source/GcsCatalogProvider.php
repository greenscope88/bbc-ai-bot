<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceCatalogProviderInterface.php';

/**
 * GCS catalog provider skeleton (Phase 9-B-4). No Google SDK or network I/O.
 */
final class GcsCatalogProvider implements ProductSourceCatalogProviderInterface
{
    public const DEFAULT_GCS_OBJECT_PATH = 'shared/config/product_source_catalog.json';

    /** @var string */
    private $objectPath;

    public function __construct(?string $objectPath = null)
    {
        $path = $objectPath !== null ? trim($objectPath) : '';
        $this->objectPath = $path !== '' ? $path : self::DEFAULT_GCS_OBJECT_PATH;
    }

    public function getProviderId(): string
    {
        return 'gcs';
    }

    public function getObjectPath(): string
    {
        return $this->objectPath;
    }

    public function fetchCatalogDocument(): array
    {
        throw new \RuntimeException(
            'GCS product source catalog provider is not implemented (Phase 9-B-4 skeleton). '
            . 'Object path: ' . $this->objectPath
        );
    }
}
