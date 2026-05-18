<?php
declare(strict_types=1);

/**
 * Host B HTTP transport contract (Phase 6 Stage 3 skeleton).
 * Implementations must not accept caller-controlled upstream hosts; URLs are built server-side only.
 */

interface HostBHttpClientInterface
{
    /**
     * Execute a single HTTP request to Host B (when enabled and implemented).
     *
     * @param array<string, string> $headers Header names must be normalized by the caller.
     */
    public function send(string $method, string $absoluteUrl, array $headers, string $body): HostBHttpRawResponse;
}
