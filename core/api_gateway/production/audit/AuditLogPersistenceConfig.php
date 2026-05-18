<?php
declare(strict_types=1);

/**
 * Reads gateway audit persistence mode from app config (env-backed in config.php).
 */
final class AuditLogPersistenceConfig
{
    /** @var string */
    private $mode;

    public function __construct(string $persistenceMode = AuditLogPersistenceMode::DISABLED)
    {
        $this->mode = AuditLogPersistenceMode::normalize($persistenceMode);
    }

    public static function fromAppConfig(): self
    {
        if (!function_exists('app_config_get')) {
            throw new RuntimeException('app_config_get is not available; load bootstrap.php first.');
        }

        $raw = (string) app_config_get('gateway.audit_log.persistence_mode', AuditLogPersistenceMode::DISABLED);

        return new self($raw);
    }

    public function getPersistenceMode(): string
    {
        return $this->mode;
    }

    public function isDisabled(): bool
    {
        return $this->mode === AuditLogPersistenceMode::DISABLED;
    }

    public function isDryRun(): bool
    {
        return $this->mode === AuditLogPersistenceMode::DRY_RUN;
    }

    public function isLive(): bool
    {
        return $this->mode === AuditLogPersistenceMode::LIVE;
    }
}
