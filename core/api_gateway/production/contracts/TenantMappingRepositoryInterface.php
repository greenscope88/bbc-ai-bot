<?php
declare(strict_types=1);

/**
 * Production contract: tenant mapping rows from api_gateway_tenant_mapping (SQL TBD).
 * Phase 6 Stage 2: interface only; Sql* implementation does not execute queries yet.
 */
interface TenantMappingRepositoryInterface
{
    /**
     * @return array<string, mixed>|null Row shape aligned with isolated TenantMappingRepository seed rows.
     */
    public function findBySno(string $sno): ?array;
}
