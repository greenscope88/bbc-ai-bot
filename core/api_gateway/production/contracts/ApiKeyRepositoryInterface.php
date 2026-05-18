<?php
declare(strict_types=1);

/**
 * Production contract: api_gateway_keys lookups (SQL TBD).
 */
interface ApiKeyRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByIncomingPlainKey(string $incomingPlain): ?array;

    public function hasPrefixButHashMismatch(string $incomingPlain): bool;
}
