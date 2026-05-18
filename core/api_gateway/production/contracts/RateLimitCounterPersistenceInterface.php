<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitPersistenceResult.php';

/**
 * Production contract: persisted rate-limit counter evaluation (SQL/Redis TBD).
 * Phase 6 Stage 5: disabled / dry_run / live guard only — no storage I/O.
 */
interface RateLimitCounterPersistenceInterface
{
    /**
     * @param array<string, mixed> $context Loose context; implementations must redact + whitelist before any store.
     */
    public function evaluateConsumption(array $context): RateLimitPersistenceResult;
}
