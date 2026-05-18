<?php
declare(strict_types=1);

/**
 * Production contract: api_gateway_rate_limit_policy resolution (SQL TBD).
 */
interface RateLimitPolicyRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function resolvePolicy(string $sno, $apiKeyId, string $service): ?array;
}
