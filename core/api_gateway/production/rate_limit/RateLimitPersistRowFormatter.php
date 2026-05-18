<?php
declare(strict_types=1);

/**
 * Normalizes loose context into a whitelist-only row for persistence planning.
 */
final class RateLimitPersistRowFormatter
{
    private const MAX_SNO = 64;
    private const MAX_PREFIX = 16;
    private const MAX_SERVICE = 128;
    private const MAX_IP = 45;
    private const MAX_WINDOW_KEY = 256;
    private const MAX_RESET_AT = 32;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function formatForPersistence(array $context): array
    {
        $clean = RateLimitSensitiveDataRedactor::redactAssociative($context);
        $row = [];
        foreach (RateLimitFieldWhitelist::allowedKeys() as $key) {
            if (!array_key_exists($key, $clean)) {
                continue;
            }
            $coerced = self::coerceField($key, $clean[$key]);
            if ($coerced === null) {
                continue;
            }
            $row[$key] = $coerced;
        }

        return $row;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function coerceField(string $key, $value)
    {
        if ($value === null) {
            return null;
        }

        if ($key === 'api_key_id' || $key === 'limit_per_minute' || $key === 'limit_per_hour' || $key === 'limit_per_day' || $key === 'burst_limit' || $key === 'current_count') {
            if (is_numeric($value)) {
                return (int) $value;
            }

            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '' && $key !== 'reset_at') {
            return null;
        }

        switch ($key) {
            case 'sno':
                return self::truncate($s, self::MAX_SNO);
            case 'api_key_prefix':
                return self::truncate($s, self::MAX_PREFIX);
            case 'service':
                return self::truncate($s, self::MAX_SERVICE);
            case 'client_ip':
                return self::truncate($s, self::MAX_IP);
            case 'window_key':
                return self::truncate($s, self::MAX_WINDOW_KEY);
            case 'reset_at':
                return self::truncate($s, self::MAX_RESET_AT);
            default:
                return null;
        }
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}
