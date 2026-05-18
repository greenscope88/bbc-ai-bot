<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contracts' . DIRECTORY_SEPARATOR . 'TenantMappingRepositoryInterface.php';

/**
 * SQL-backed tenant mapping (api_gateway_tenant_mapping).
 * Phase 6 Stage 2: skeleton only — no queries, no PDO usage.
 */
final class SqlTenantMappingRepository implements TenantMappingRepositoryInterface
{
    public function findBySno(string $sno): ?array
    {
        self::forbidSqlExecution();
    }

    /**
     * @return never
     */
    private static function forbidSqlExecution(): void
    {
        throw new \RuntimeException(
            'API Gateway Phase 6 Stage 2: SqlTenantMappingRepository has no SQL execution (skeleton only).'
        );
    }
}
