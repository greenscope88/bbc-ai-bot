<?php
declare(strict_types=1);

/**
 * Pure dry-run decision for persisted counters (no I/O).
 */
final class RateLimitDryRunEvaluator
{
    /**
     * @param array<string, mixed> $row Whitelist-normalized row
     */
    public static function wouldReject(array $row): bool
    {
        $count = isset($row['current_count']) ? (int) $row['current_count'] : 0;
        $limit = self::limitForWindow($row);
        if ($limit === null || $limit <= 0) {
            return false;
        }

        return $count >= $limit;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function limitForWindow(array $row): ?int
    {
        $wk = isset($row['window_key']) ? strtolower((string) $row['window_key']) : '';
        if (strpos($wk, 'burst') !== false) {
            return self::positiveIntOrNull($row, 'burst_limit');
        }
        if (strpos($wk, 'minute') !== false || strpos($wk, 'min:') !== false) {
            return self::positiveIntOrNull($row, 'limit_per_minute');
        }
        if (strpos($wk, 'hour') !== false) {
            return self::positiveIntOrNull($row, 'limit_per_hour');
        }
        if (strpos($wk, 'day') !== false) {
            return self::positiveIntOrNull($row, 'limit_per_day');
        }

        foreach (['burst_limit', 'limit_per_minute', 'limit_per_hour', 'limit_per_day'] as $k) {
            $v = self::positiveIntOrNull($row, $k);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function positiveIntOrNull(array $row, string $key): ?int
    {
        if (!isset($row[$key])) {
            return null;
        }
        if (!is_int($row[$key]) && !is_numeric($row[$key])) {
            return null;
        }
        $v = (int) $row[$key];
        if ($v <= 0) {
            return null;
        }

        return $v;
    }
}
