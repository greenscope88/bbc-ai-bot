<?php
declare(strict_types=1);

/**
 * Host B HTTP client skeleton: disabled-by-default; no network I/O in Phase 6 Stage 3.
 */
final class HostBHttpClient implements HostBHttpClientInterface
{
    /** @var HostBGatewayHttpConfig */
    private $config;

    public function __construct(HostBGatewayHttpConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $absoluteUrl, array $headers, string $body): HostBHttpRawResponse
    {
        if (!$this->config->isHttpEnabled()) {
            throw new HostBHttpDisabledException(
                'Host B HTTP is disabled by default. Set GATEWAY_HOSTB_HTTP_ENABLED=true only after operations approval; Phase 6 Stage 3 does not perform outbound HTTP.'
            );
        }

        // Intentionally no network transport in this phase.
        throw new RuntimeException(
            'Phase 6 Stage 3 skeleton: Host B HTTP dispatch is not implemented; outbound requests are not permitted in this stage.'
        );
    }
}
