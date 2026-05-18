<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GatewaySqlConnectionConfig.php';

/**
 * Future central factory for read-only Gateway DB handles.
 * Phase 6 Stage 2: does not instantiate PDO / does not connect.
 */
final class SqlGatewayConnectionFactory
{
    /** @var GatewaySqlConnectionConfig|null */
    private $config;

    public function __construct(?GatewaySqlConnectionConfig $config = null)
    {
        $this->config = $config;
    }

    public function getConfig(): ?GatewaySqlConnectionConfig
    {
        return $this->config;
    }

    /**
     * @return never
     */
    public function createReadOnlyPdo(): \PDO
    {
        throw new \RuntimeException(
            'API Gateway Phase 6 Stage 2: PDO creation disabled; no live DB connection or SQL execution.'
        );
    }
}
