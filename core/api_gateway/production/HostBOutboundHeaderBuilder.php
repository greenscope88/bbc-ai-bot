<?php
declare(strict_types=1);

/**
 * Builds a fixed, minimal header set for Host A → Host B outbound calls.
 * Does not send HTTP, log keys, or forward client/gateway admission headers.
 */
final class HostBOutboundHeaderBuilder
{
    /**
     * @param array<string, mixed> $config Must include non-empty string `api_key` (Host B internal key).
     * @return array<string, string> Headers keyed by canonical names (X-API-Key, X-BBC-Trace-Id only).
     */
    public static function build(array $config, string $traceId): array
    {
        $trace = trim($traceId);
        if ($trace === '') {
            throw new InvalidArgumentException('traceId must be a non-empty string.');
        }

        if (!array_key_exists('api_key', $config)) {
            throw new InvalidArgumentException('Missing api_key in config.');
        }

        $apiKey = $config['api_key'];
        if ($apiKey === null) {
            throw new InvalidArgumentException('api_key must be non-empty for Host B outbound headers.');
        }
        if (!is_string($apiKey)) {
            throw new InvalidArgumentException('api_key must be a string.');
        }
        $apiKeyTrim = trim($apiKey);
        if ($apiKeyTrim === '') {
            throw new InvalidArgumentException('api_key must be non-empty for Host B outbound headers.');
        }

        return [
            'X-API-Key' => $apiKeyTrim,
            'X-BBC-Trace-Id' => $trace,
        ];
    }
}
