<?php
declare(strict_types=1);

/**
 * Orchestrates server-side URL assembly and delegates to {@see HostBHttpClientInterface}.
 * Caller must never pass a raw upstream URL; only gateway service ids are accepted here.
 */
final class HostBProxyService
{
    /** @var HostBGatewayHttpConfig */
    private $config;

    /** @var HostBHttpClientInterface */
    private $client;

    public function __construct(HostBGatewayHttpConfig $config, HostBHttpClientInterface $client)
    {
        $this->config = $config;
        $this->client = $client;
    }

    /**
     * @param array<string, string> $headers
     */
    public function forward(string $gatewayService, string $httpMethod, array $headers, string $body): HostBHttpRawResponse
    {
        $relative = HostBServiceEndpointMap::relativePath($gatewayService);

        if (!$this->config->isHttpEnabled()) {
            // No outbound I/O: client rejects before any real URL assembly or transport.
            return $this->client->send(
                strtoupper($httpMethod),
                'https://localhost/gateway-hostb-disabled-placeholder',
                $headers,
                $body
            );
        }

        $base = rtrim($this->config->getBaseUrl(), '/');
        if ($base === '') {
            throw new RuntimeException('Host B base URL is not configured (GATEWAY_HOSTB_BASE_URL).');
        }

        $absolute = $base . '/' . ltrim($relative, '/');

        return $this->client->send(strtoupper($httpMethod), $absolute, $headers, $body);
    }
}
