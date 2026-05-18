<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contracts' . DIRECTORY_SEPARATOR . 'AuditLogRepositoryInterface.php';

/**
 * Persistence guard: default disabled (safe exception), dry_run normalizes only, live not implemented in Stage 4.
 * Never performs SQL or network I/O.
 */
final class GuardedAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var AuditLogPersistenceConfig */
    private $config;

    public function __construct(AuditLogPersistenceConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function append(array $record): void
    {
        if ($this->config->isDisabled()) {
            throw new AuditLogPersistenceDisabledException(
                'Gateway audit log persistence is disabled by default. Set GATEWAY_AUDIT_LOG_PERSISTENCE_MODE=dry_run for validation-only, or wait for an approved rollout for live mode.'
            );
        }

        $normalized = AuditLogRowFormatter::formatForPersistence($record);

        if ($this->config->isDryRun()) {
            return;
        }

        if ($this->config->isLive()) {
            throw new RuntimeException(
                'API Gateway Phase 6 Stage 4: live audit persistence is not implemented; no database writes are permitted in this stage.'
            );
        }

        throw new RuntimeException('API Gateway Phase 6 Stage 4: unknown audit persistence mode.');
    }
}
