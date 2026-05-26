<?php
declare(strict_types=1);

/**
 * Intent decision audit log for Hybrid Smart Search (Phase 2-C.1).
 */
final class TourIntentDecisionLogger
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'api_key',
        'userId',
        'user_id',
        'line_user_id',
        'email',
        'phone',
        'passport',
    ];

    /**
     * @param array<string, mixed> $entry
     */
    public static function log(array $entry, ?string $logPath = null): void
    {
        try {
            $line = json_encode(self::sanitize($entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                '[' . date('Y-m-d H:i:s') . '] intent_decision ' . $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // Never break LINE flow.
        }
    }

    public static function defaultLogPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'hybrid_search_intent_decision.log';
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
            if (in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $out;
    }

    public static function isLoggingEnabled(): bool
    {
        if (!function_exists('app_config_get')) {
            $bootstrap = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';
            if (is_file($bootstrap)) {
                require_once $bootstrap;
            }
        }

        if (!function_exists('app_config_get')) {
            return true;
        }

        $cfg = app_config_get('gateway.hybrid_search', []);
        if (!is_array($cfg)) {
            return true;
        }

        if (array_key_exists('intent_log_enabled', $cfg)) {
            return filter_var($cfg['intent_log_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($cfg['dry_run_log_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }
}
