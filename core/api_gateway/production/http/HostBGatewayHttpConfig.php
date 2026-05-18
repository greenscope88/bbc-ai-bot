<?php
declare(strict_types=1);

/**
 * Server-side Host B HTTP settings (base URL and timeouts from env via config.php only).
 */
final class HostBGatewayHttpConfig
{
    /** @var bool */
    private $httpEnabled;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $connectTimeoutSec;

    /** @var int */
    private $readTimeoutSec;

    public function __construct(bool $httpEnabled = false, string $baseUrl = '', int $connectTimeoutSec = 3, int $readTimeoutSec = 10)
    {
        $this->httpEnabled = $httpEnabled;
        $this->baseUrl = $baseUrl;
        $this->connectTimeoutSec = $connectTimeoutSec;
        $this->readTimeoutSec = $readTimeoutSec;
    }

    public static function fromAppConfig(): self
    {
        if (!function_exists('app_config_get')) {
            throw new RuntimeException('app_config_get is not available; load bootstrap.php first.');
        }

        return new self(
            (bool) app_config_get('gateway.host_b.http_enabled', false),
            (string) app_config_get('gateway.host_b.base_url', ''),
            (int) app_config_get('gateway.host_b.connect_timeout_sec', 3),
            (int) app_config_get('gateway.host_b.read_timeout_sec', 10)
        );
    }

    public function isHttpEnabled(): bool
    {
        return $this->httpEnabled;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getConnectTimeoutSec(): int
    {
        return $this->connectTimeoutSec;
    }

    public function getReadTimeoutSec(): int
    {
        return $this->readTimeoutSec;
    }
}
