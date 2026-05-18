<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contracts' . DIRECTORY_SEPARATOR . 'ServicePermissionRepositoryInterface.php';

/**
 * SQL-backed service permissions (api_gateway_service_permissions or facade).
 * Phase 6 Stage 2: skeleton only — no queries, no PDO usage.
 */
final class SqlServicePermissionRepository implements ServicePermissionRepositoryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listRulesForTenantAndKey(string $sno, $apiKeyId): array
    {
        self::forbidSqlExecution();
    }

    /**
     * @return never
     */
    private static function forbidSqlExecution(): void
    {
        throw new \RuntimeException(
            'API Gateway Phase 6 Stage 2: SqlServicePermissionRepository has no SQL execution (skeleton only).'
        );
    }
}
