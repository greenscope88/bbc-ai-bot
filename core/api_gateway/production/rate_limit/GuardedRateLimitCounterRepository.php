<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitPersistenceMode.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitPersistenceConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitFieldWhitelist.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitSensitiveDataRedactor.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitPersistRowFormatter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitDryRunEvaluator.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contracts' . DIRECTORY_SEPARATOR . 'RateLimitCounterPersistenceInterface.php';

/**
 * Guarded persisted counter evaluation: disabled passes through, dry_run is pure, live is rejected in Stage 5.
 */
final class GuardedRateLimitCounterRepository implements RateLimitCounterPersistenceInterface
{
    /** @var RateLimitPersistenceConfig */
    private $config;

    public function __construct(RateLimitPersistenceConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function evaluateConsumption(array $context): RateLimitPersistenceResult
    {
        $row = RateLimitPersistRowFormatter::formatForPersistence($context);

        if ($this->config->isDisabled()) {
            return RateLimitPersistenceResult::disabledPassThrough($row);
        }

        if ($this->config->isDryRun()) {
            $reject = RateLimitDryRunEvaluator::wouldReject($row);

            return RateLimitPersistenceResult::dryRun($reject, $row);
        }

        if ($this->config->isLive()) {
            throw new RuntimeException(
                'API Gateway Phase 6 Stage 5: live rate-limit persistence is not implemented; no database or external counter connections are permitted in this stage.'
            );
        }

        throw new RuntimeException('API Gateway Phase 6 Stage 5: unknown rate-limit persistence mode.');
    }
}
