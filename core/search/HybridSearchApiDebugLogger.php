<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'HybridSearchFilterCapability.php';

/**
 * Phase 2-C.2: API param + response sample debug log (no PII/secrets).
 */
final class HybridSearchApiDebugLogger
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'api_key',
        'userId',
        'user_id',
        'line_user_id',
        'email',
        'phone',
    ];

    /**
     * @param array<string, mixed> $entry
     */
    public static function log(array $entry, ?string $logPath = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

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
                '[' . date('Y-m-d H:i:s') . '] hybrid_search_api_debug ' . $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // Never break LINE flow.
        }
    }

    /**
     * @param array<string, mixed> $searchConditionArray
     * @param array<string, string|int> $apiParams
     * @param array<string, mixed> $apiResult TourSearchApiClient result
     */
    public static function logSearchRoundtrip(
        string $traceId,
        string $sno,
        array $searchConditionArray,
        array $apiParams,
        string $requestUrl,
        array $apiResult
    ): void {
        $items = isset($apiResult['items']) && is_array($apiResult['items']) ? $apiResult['items'] : [];

        self::log([
            'trace_id' => $traceId !== '' ? $traceId : null,
            'sno' => $sno,
            'search_condition' => $searchConditionArray,
            'api_params' => $apiParams,
            'request_url' => $requestUrl,
            'allowlist_report' => HybridSearchFilterCapability::allowlistReport(),
            'params_on_wire' => HybridSearchFilterCapability::paramsOnWire($apiParams),
            'response_item_count' => count($items),
            'response_sample' => HybridSearchFilterCapability::sampleItems($items, 5),
            'filter_violations' => HybridSearchFilterCapability::detectViolations($items, $searchConditionArray),
            'filter_likely_effective' => HybridSearchFilterCapability::filterLikelyEffective(
                $items,
                $searchConditionArray
            ),
        ]);
    }

    public static function defaultLogPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'hybrid_search_api_debug.log';
    }

    public static function isEnabled(): bool
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

        if (array_key_exists('api_debug_log_enabled', $cfg)) {
            return filter_var($cfg['api_debug_log_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($cfg['dry_run_log_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
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
}
