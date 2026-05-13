<?php
declare(strict_types=1);

final class TraceIdMiddleware
{
    private const HEADER_CANDIDATES = [
        'HTTP_X_TRACE_ID',
        'X-Trace-Id',
        'x-trace-id',
    ];

    private const MAX_TRACE_ID_LENGTH = 64;

    /**
     * Ensures $requestContext['traceId'] is set from X-Trace-Id or a new UUID v4.
     *
     * @param array<string, mixed> $requestContext
     * @param array<string, mixed>|null $serverOrHeaders When null, reads from $_SERVER (typical PHP request).
     */
    public static function apply(array &$requestContext, ?array $serverOrHeaders = null): void
    {
        $headers = $serverOrHeaders ?? $_SERVER;
        $incoming = self::readTraceIdHeader($headers);
        if ($incoming === null || $incoming === '') {
            $incoming = self::generateUuidV4();
        } elseif (!self::isValidIncomingTraceId($incoming)) {
            $incoming = self::generateUuidV4();
        }
        $requestContext['traceId'] = $incoming;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function readTraceIdHeader(array $headers): ?string
    {
        foreach (self::HEADER_CANDIDATES as $key) {
            if (!array_key_exists($key, $headers)) {
                continue;
            }
            $value = $headers[$key];
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private static function isValidIncomingTraceId(string $value): bool
    {
        if (strlen($value) > self::MAX_TRACE_ID_LENGTH) {
            return false;
        }

        return !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
