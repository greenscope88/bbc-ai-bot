<?php
declare(strict_types=1);

/**
 * Read-only DTO for future PDO/sqlsrv DSN assembly. Does not open connections.
 */
final class GatewaySqlConnectionConfig
{
    private string $host;

    private string $databaseName;

    public function __construct(string $host, string $databaseName)
    {
        $this->host = $host;
        $this->databaseName = $databaseName;
    }

    /**
     * Build config from app_config() without connecting to SQL Server.
     */
    public static function fromAppConfig(): self
    {
        if (!function_exists('app_config_get')) {
            require_once dirname(__DIR__, 3) . '/bootstrap.php';
        }

        return new self(
            (string) app_config_get('database.host', ''),
            (string) app_config_get('database.name', '')
        );
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }
}
