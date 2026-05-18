<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contracts' . DIRECTORY_SEPARATOR . 'RateLimitPolicyRepositoryInterface.php';

/**
 * SQL-backed rate limit policies (api_gateway_rate_limit_policy).
 * Phase 6 Stage 2: skeleton only — no queries, no PDO usage.
 */
final class SqlRateLimitPolicyRepository implements RateLimitPolicyRepositoryInterface
{
    public function resolvePolicy(string $sno, $apiKeyId, string $service): ?array
    {
        self::forbidSqlExecution();
    }

    /**
     * @return never
     */
    private static function forbidSqlExecution(): void
    {
        throw new \RuntimeException(
            'API Gateway Phase 6 Stage 2: SqlRateLimitPolicyRepository has no SQL execution (skeleton only).'
        );
    }
}
