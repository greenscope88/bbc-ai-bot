<?php
declare(strict_types=1);

/**
 * Removes sensitive keys and redacts risky string values before audit row formatting.
 * Does not replace Phase 4 AuditLogRecordBuilder; this is an additional server-side gate for persistence.
 */
final class AuditLogSensitiveDataRedactor
{
    /** @var array<int, string> */
    private static $keyFragments = [
        'authorization',
        'cookie',
        'set-cookie',
        'set_cookie',
        'password',
        'passwd',
        'token',
        'secret',
        'apikey',
        'api_key',
        'bearer',
        'refresh_token',
        'client_secret',
        'raw_body',
        'request_body',
        'body_raw',
    ];

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function redactAssociative(array $record): array
    {
        $out = [];
        foreach ($record as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (self::keyMatchesSensitive($key)) {
                continue;
            }
            $out[$key] = self::redactValue($value);
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function redactValue($value)
    {
        if (is_array($value)) {
            $nested = [];
            foreach ($value as $k => $v) {
                if (!is_string($k) && !is_int($k)) {
                    continue;
                }
                $ks = is_string($k) ? $k : (string) $k;
                if (self::keyMatchesSensitive($ks)) {
                    continue;
                }
                $nested[$ks] = self::redactValue($v);
            }

            return $nested;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        return $value;
    }

    private static function keyMatchesSensitive(string $key): bool
    {
        $lower = strtolower($key);
        if ($lower === 'api_key_prefix') {
            return false;
        }
        foreach (self::$keyFragments as $frag) {
            if (strpos($lower, $frag) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function redactString(string $value): string
    {
        $trim = trim($value);
        if ($trim === '') {
            return $value;
        }
        if (stripos($trim, 'bearer ') === 0) {
            return '[redacted]';
        }
        if (stripos($trim, 'basic ') === 0) {
            return '[redacted]';
        }
        if (preg_match('/^[A-Za-z0-9\\-_]{20,}$/', $trim) === 1) {
            // Likely opaque token / API key material
            return '[redacted]';
        }

        return $value;
    }
}
