<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contracts' . DIRECTORY_SEPARATOR . 'AuditLogRepositoryInterface.php';

/**
 * SQL-backed audit log append (api_gateway_audit_log).
 * Phase 6 Stage 2: skeleton only — no INSERT, no PDO usage.
 */
final class SqlAuditLogRepository implements AuditLogRepositoryInterface
{
    /**
     * @param array<string, mixed> $record
     */
    public function append(array $record): void
    {
        self::forbidSqlExecution();
    }

    /**
     * @return never
     */
    private static function forbidSqlExecution(): void
    {
        throw new \RuntimeException(
            'API Gateway Phase 6 Stage 2: SqlAuditLogRepository has no SQL execution (skeleton only).'
        );
    }
}
