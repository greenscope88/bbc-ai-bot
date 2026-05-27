<?php
declare(strict_types=1);

/**
 * Tenant registry contract (Phase 2A). Implementations: ConfigTenantRegistry, future DbTenantRegistry.
 */
interface TenantRegistryInterface
{
    public function resolveByChannel(string $channelId): ?ResolvedTenant;

    public function resolveBySno(string $sno): ?ResolvedTenant;

    /**
     * @return list<ResolvedTenant>
     */
    public function getAllTenants(): array;
}
