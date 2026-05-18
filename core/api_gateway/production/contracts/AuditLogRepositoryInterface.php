<?php
declare(strict_types=1);

/**
 * Production contract: append-only writes to api_gateway_audit_log (SQL TBD).
 */
interface AuditLogRepositoryInterface
{
    /**
     * @param array<string, mixed> $record Normalized audit row (e.g. from AuditLogRecordBuilder).
     */
    public function append(array $record): void;
}
