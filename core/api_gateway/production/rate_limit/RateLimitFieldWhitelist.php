<?php
declare(strict_types=1);

/**
 * Safe fields for persisted rate-limit policy + counter snapshots (Phase 6 Stage 5 baseline).
 */
final class RateLimitFieldWhitelist
{
    public const ALLOWED_KEYS = [
        'sno',
        'api_key_id',
        'api_key_prefix',
        'service',
        'client_ip',
        'limit_per_minute',
        'limit_per_hour',
        'limit_per_day',
        'burst_limit',
        'window_key',
        'current_count',
        'reset_at',
    ];

    /**
     * @return array<int, string>
     */
    public static function allowedKeys(): array
    {
        return self::ALLOWED_KEYS;
    }

    public static function isAllowedKey(string $key): bool
    {
        return in_array($key, self::ALLOWED_KEYS, true);
    }
}
