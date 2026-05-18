<?php
declare(strict_types=1);

/**
 * Safe column names for gateway audit persistence (Phase 6 Stage 4 baseline).
 * Only these keys may appear on the normalized persist row.
 */
final class AuditLogFieldWhitelist
{
    public const ALLOWED_KEYS = [
        'trace_id',
        'sno',
        'api_key_prefix',
        'service',
        'request_method',
        'request_path',
        'http_status',
        'error_code',
        'client_ip',
        'user_agent',
        'duration_ms',
        'created_at',
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
