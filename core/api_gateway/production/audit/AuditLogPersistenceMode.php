<?php
declare(strict_types=1);

/**
 * Gateway audit log persistence modes (Phase 6 Stage 4).
 */
final class AuditLogPersistenceMode
{
    public const DISABLED = 'disabled';
    public const DRY_RUN = 'dry_run';
    public const LIVE = 'live';

    /**
     * @return string one of self::*
     */
    public static function normalize(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === self::DRY_RUN || $v === 'dry-run') {
            return self::DRY_RUN;
        }
        if ($v === self::LIVE) {
            return self::LIVE;
        }

        return self::DISABLED;
    }
}
