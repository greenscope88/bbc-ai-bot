<?php
declare(strict_types=1);

/**
 * Normalizes Host B JSON payloads for gateway clients: removes stack traces, SQL details, and raw URLs.
 */
final class HostBResponseNormalizer
{
    private const SENSITIVE_KEYS = [
        'stack', 'stacktrace', 'stackTrace', 'StackTrace',
        'innerexception', 'innerException', 'InnerException',
        'sql', 'sqlerror', 'sqlError', 'SqlException', 'query', 'Query',
        'connectionstring', 'connectionString', 'ConnectionString',
        'exception', 'Exception', 'details', 'Details',
        'upstreamurl', 'upstreamUrl', 'UpstreamUrl',
        'requesturl', 'requestUrl', 'RequestUrl',
        'href', 'url', 'Url', 'uri', 'Uri',
    ];

    /**
     * Decode JSON body string; on failure returns a safe error-shaped array (no upstream leakage).
     *
     * @return array<string, mixed>
     */
    public static function decodeJsonBody(string $rawBody, string $traceId): array
    {
        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return self::emptyEnvelope($traceId, 'EMPTY_BODY', 'Upstream returned an empty body.');
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return self::emptyEnvelope($traceId, 'INVALID_JSON', 'Upstream returned non-JSON content.');
        }

        return self::sanitizeValue($decoded, $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function sanitizeDecoded(array $payload, string $traceId): array
    {
        $sanitized = self::sanitizeValue($payload, $traceId);
        if (!is_array($sanitized)) {
            return self::emptyEnvelope($traceId, 'INVALID_SHAPE', 'Upstream payload could not be normalized.');
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyEnvelope(string $traceId, string $errorCode, string $message): array
    {
        return [
            'success' => false,
            'traceId' => $traceId,
            'errorCode' => $errorCode,
            'message' => $message,
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeValue($value, string $traceId)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $keyStr = is_string($key) ? $key : (string) $key;
                if (self::isSensitiveKey($keyStr)) {
                    continue;
                }
                $out[$keyStr] = self::sanitizeValue($child, $traceId);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $s) {
            if (strtolower($s) === $lower) {
                return true;
            }
        }

        return false;
    }

    private static function redactString(string $value): string
    {
        if (self::containsUrl($value) || self::looksLikeSqlError($value) || self::looksLikeStackLine($value)) {
            return '[redacted]';
        }

        return $value;
    }

    private static function containsUrl(string $value): bool
    {
        return stripos($value, 'http://') !== false || stripos($value, 'https://') !== false;
    }

    private static function looksLikeSqlError(string $value): bool
    {
        $v = strtolower($value);

        return strpos($v, 'sql server') !== false
            || strpos($v, 'sqlexception') !== false
            || strpos($v, 'invalid column') !== false
            || strpos($v, 'syntax error') !== false;
    }

    private static function looksLikeStackLine(string $value): bool
    {
        return strpos($value, ' at ') !== false && (strpos($value, '.php:') !== false || strpos($value, '.cs:line') !== false);
    }
}
