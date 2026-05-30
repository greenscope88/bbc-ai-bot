<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GcsTenantProductSourcesProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LocalTenantProductSourcesProvider.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourcesProviderInterface.php';

/**
 * Creates tenant product sources providers for local vs GCS (Phase 9-B-5).
 */
final class TenantProductSourcesProviderFactory
{
    public const MODE_LOCAL = 'local';

    public const MODE_GCS = 'gcs';

    public static function create(
        string $mode,
        ?string $localConfigPath = null,
        ?string $tenantSno = null,
        ?string $gcsObjectPath = null
    ): TenantProductSourcesProviderInterface {
        $normalized = strtolower(trim($mode));

        if ($normalized === self::MODE_LOCAL) {
            return new LocalTenantProductSourcesProvider($localConfigPath);
        }

        if ($normalized === self::MODE_GCS) {
            if ($tenantSno === null || trim($tenantSno) === '') {
                throw new \InvalidArgumentException('tenant_sno is required for GCS tenant product sources provider mode.');
            }

            return new GcsTenantProductSourcesProvider($tenantSno, $gcsObjectPath);
        }

        throw new \InvalidArgumentException('Unknown tenant product sources provider mode: ' . $mode);
    }

    /**
     * Default for Host A development: local travel_b sample JSON.
     */
    public static function createDefault(): TenantProductSourcesProviderInterface
    {
        return self::create(self::MODE_LOCAL);
    }
}
