<?php
declare(strict_types=1);

/**
 * Normalizes a loose audit record into a whitelist-only row safe for persistence planning.
 */
final class AuditLogRowFormatter
{
    private const MAX_TRACE_ID = 64;
    private const MAX_SNO = 64;
    private const MAX_PREFIX = 16;
    private const MAX_SERVICE = 128;
    private const MAX_METHOD = 16;
    private const MAX_PATH = 512;
    private const MAX_ERROR_CODE = 64;
    private const MAX_IP = 45;
    private const MAX_USER_AGENT = 256;

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function formatForPersistence(array $record): array
    {
        $clean = AuditLogSensitiveDataRedactor::redactAssociative($record);
        $row = [];
        foreach (AuditLogFieldWhitelist::allowedKeys() as $key) {
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

        if ($key === 'http_status' || $key === 'duration_ms') {
            if (is_numeric($value)) {
                return (int) $value;
            }

            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        switch ($key) {
            case 'trace_id':
                return self::truncate(self::stripControlChars($s), self::MAX_TRACE_ID);
            case 'sno':
                return self::truncate($s, self::MAX_SNO);
            case 'api_key_prefix':
                return self::truncate($s, self::MAX_PREFIX);
            case 'service':
                return self::truncate($s, self::MAX_SERVICE);
            case 'request_method':
                return self::truncate(strtoupper($s), self::MAX_METHOD);
            case 'request_path':
                return self::truncate($s, self::MAX_PATH);
            case 'error_code':
                return self::truncate($s, self::MAX_ERROR_CODE);
            case 'client_ip':
                return self::truncate($s, self::MAX_IP);
            case 'user_agent':
                return self::truncate($s, self::MAX_USER_AGENT);
            case 'created_at':
                return self::truncate($s, 32);
            default:
                return null;
        }
    }

    private static function stripControlChars(string $value): string
    {
        return preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $value) ?? '';
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}
