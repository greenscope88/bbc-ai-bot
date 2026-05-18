<?php
declare(strict_types=1);

/**
 * Read-only activation guard for controlled rollout preparation (Phase 6 Stage 7).
 * Does not change configuration, does not perform I/O beyond reading app config.
 */
final class RolloutActivationGuard
{
    private const MODE_LIVE = 'live';

    /**
     * Ensures audit/rate persistence are not in live mode and Host B HTTP transport stays off.
     * Call after bootstrap so app_config_get reflects loaded .env.
     *
     * @throws RuntimeException when the current configuration violates preparation constraints
     */
    public static function assertControlledPreparationPhase(): void
    {
        if (!function_exists('app_config_get')) {
            throw new RuntimeException('RolloutActivationGuard requires bootstrap (app_config_get missing).');
        }

        $audit = self::normalizeMode((string) app_config_get('gateway.audit_log.persistence_mode', 'disabled'));
        $rate = self::normalizeMode((string) app_config_get('gateway.rate_limit.persistence_mode', 'disabled'));
        $hostb = (bool) app_config_get('gateway.host_b.http_enabled', false);

        if ($audit === self::MODE_LIVE) {
            throw new RuntimeException(
                'Rollout guard: gateway.audit_log.persistence_mode must not be "live" during Phase 6 Stage 7 preparation.'
            );
        }

        if ($rate === self::MODE_LIVE) {
            throw new RuntimeException(
                'Rollout guard: gateway.rate_limit.persistence_mode must not be "live" during Phase 6 Stage 7 preparation.'
            );
        }

        if ($hostb === true) {
            throw new RuntimeException(
                'Rollout guard: gateway.host_b.http_enabled must remain false until an approved rollout enables outbound Host B HTTP.'
            );
        }
    }

    private static function normalizeMode(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === 'dry-run') {
            return 'dry_run';
        }

        return $v;
    }
}
