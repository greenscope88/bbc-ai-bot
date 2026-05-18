<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contracts' . DIRECTORY_SEPARATOR . 'ApiKeyRepositoryInterface.php';

/**
 * SQL-backed API keys (api_gateway_keys).
 * Phase 6 Stage 2: skeleton only — no queries, no PDO usage.
 */
final class SqlApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function findByIncomingPlainKey(string $incomingPlain): ?array
    {
        self::forbidSqlExecution();
    }

    public function hasPrefixButHashMismatch(string $incomingPlain): bool
    {
        self::forbidSqlExecution();
    }

    /**
     * @return never
     */
    private static function forbidSqlExecution(): void
    {
        throw new \RuntimeException(
            'API Gateway Phase 6 Stage 2: SqlApiKeyRepository has no SQL execution (skeleton only).'
        );
    }
}
