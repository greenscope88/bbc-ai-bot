<?php
declare(strict_types=1);

final class RateLimitPersistenceConfig
{
    /** @var string */
    private $mode;

    public function __construct(string $persistenceMode = RateLimitPersistenceMode::DISABLED)
    {
        $this->mode = RateLimitPersistenceMode::normalize($persistenceMode);
    }

    public static function fromAppConfig(): self
    {
        if (!function_exists('app_config_get')) {
            throw new RuntimeException('app_config_get is not available; load bootstrap.php first.');
        }

        $raw = (string) app_config_get('gateway.rate_limit.persistence_mode', RateLimitPersistenceMode::DISABLED);

        return new self($raw);
    }

    public function getPersistenceMode(): string
    {
        return $this->mode;
    }

    public function isDisabled(): bool
    {
        return $this->mode === RateLimitPersistenceMode::DISABLED;
    }

    public function isDryRun(): bool
    {
        return $this->mode === RateLimitPersistenceMode::DRY_RUN;
    }

    public function isLive(): bool
    {
        return $this->mode === RateLimitPersistenceMode::LIVE;
    }
}
