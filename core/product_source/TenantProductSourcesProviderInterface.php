<?php
declare(strict_types=1);

/**
 * Supplies raw tenant product sources JSON document (Phase 9-B-5).
 *
 * @phpstan-type TenantProductSourcesDocument array{
 *   schema_version?: int,
 *   tenant_sno?: string,
 *   tenant_key?: string,
 *   default_category?: string,
 *   enabled_sources?: list<array<string, mixed>>
 * }
 */
interface TenantProductSourcesProviderInterface
{
    /**
     * Fetch tenant product sources root object (not yet normalized).
     *
     * @return TenantProductSourcesDocument
     */
    public function fetchTenantProductSourcesDocument(): array;

    /**
     * Provider identifier for logging / factory (e.g. local, gcs).
     */
    public function getProviderId(): string;
}
