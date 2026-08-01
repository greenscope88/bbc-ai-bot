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
     * @deprecated No fixed default tenant. Kept only because TenantProductSourceLoader's
     * constructor default still references this method; it now fails closed (no
     * fallback to any tenant's document). Use createForTenant() instead.
     */
    public static function createDefault(): TenantProductSourcesProviderInterface
    {
        return self::create(self::MODE_LOCAL);
    }

    /**
     * Single Selection Owner: resolves the canonical local tenant product-sources
     * provider for the authoritative tenant_sno. No tenant-specific mapping, no
     * fallback to any other tenant's document.
     */
    public static function createForTenant(string $tenantSno): TenantProductSourcesProviderInterface
    {
        $trimmed = trim($tenantSno);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('tenant_sno is required for createForTenant().');
        }

        if (self::containsPathTraversal($trimmed)) {
            throw new \InvalidArgumentException('tenant_sno contains invalid path characters: ' . $tenantSno);
        }

        return new LocalTenantProductSourcesProvider(self::canonicalConfigPath($trimmed));
    }

    private static function canonicalConfigPath(string $tenantSno): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR
            . 'product_source' . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR
            . $tenantSno . DIRECTORY_SEPARATOR . 'product_sources.json';
    }

    private static function containsPathTraversal(string $value): bool
    {
        return strpos($value, '/') !== false
            || strpos($value, '\\') !== false
            || strpos($value, '..') !== false;
    }
}
