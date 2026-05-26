<?php
declare(strict_types=1);

/**
 * Phase 2-C: Hybrid search dry-run log (no PII / secrets).
 */
final class HybridSearchDryRunLogger
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'api_key',
        'apiKey',
        'userId',
        'user_id',
        'line_user_id',
        'channel_access_token',
        'channel_secret',
        'email',
        'phone',
        'passport',
        'id_number',
    ];

    /**
     * @param array<string, mixed> $entry
     */
    public static function log(array $entry, ?string $logPath = null): void
    {
        try {
            $sanitized = self::sanitize($entry);
            $line = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                return;
            }

            $path = $logPath ?? self::defaultLogPath();
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            @file_put_contents(
                $path,
                '[' . date('Y-m-d H:i:s') . '] hybrid_search_dry_run ' . $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // Never break LINE flow for logging failures.
        }
    }

    public static function defaultLogPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'hybrid_search_dry_run.log';
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function sanitize(array $entry): array
    {
        $out = [];
        foreach ($entry as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $lower = strtolower($key);
            if (in_array($lower, self::FORBIDDEN_KEYS, true)) {
                continue;
            }
            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                if (strpos($lower, strtolower($forbidden)) !== false) {
                    continue 2;
                }
            }

            $out[$key] = self::sanitizeValue($value);
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeValue($value)
    {
        if (is_array($value)) {
            $child = [];
            foreach ($value as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                $lk = strtolower($k);
                if (in_array($lk, self::FORBIDDEN_KEYS, true)) {
                    continue;
                }
                $child[$k] = self::sanitizeValue($v);
            }

            return $child;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return null;
    }
}
