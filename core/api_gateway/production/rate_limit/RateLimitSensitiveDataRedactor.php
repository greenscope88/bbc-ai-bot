<?php
declare(strict_types=1);

/**
 * Strips sensitive dimensions from loose rate-limit context before persistence planning.
 */
final class RateLimitSensitiveDataRedactor
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
        'raw_body',
        'request_body',
        'body_raw',
        'api_key',
    ];

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function redactAssociative(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
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
        if ($lower === 'api_key_id' || $lower === 'api_key_prefix') {
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
        if (stripos($trim, 'bearer ') === 0 || stripos($trim, 'basic ') === 0) {
            return '[redacted]';
        }

        return $value;
    }
}
