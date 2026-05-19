<?php
declare(strict_types=1);

/**
 * Stage 1-B-18 safety gate.
 * Default OFF.
 * Do not enable in production without explicit approval.
 */
final class TourPromptFeatureGate
{
    private const FEATURE_ENABLED = false;

    /** @var list<string> */
    private const ALLOWED_SNO = [];

    /** @var list<string> */
    private const ALLOWED_CHANNEL_IDS = [];

    /**
     * @param array{sno?: string|null, channelId?: string|null, userId?: string|null} $context
     */
    public static function isEnabled(array $context = []): bool
    {
        try {
            return self::check(self::FEATURE_ENABLED, self::ALLOWED_SNO, self::ALLOWED_CHANNEL_IDS, $context);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param list<string> $allowedSno
     * @param list<string> $allowedChannelIds
     * @param array{sno?: string|null, channelId?: string|null, userId?: string|null} $context
     */
    public static function check(bool $featureEnabled, array $allowedSno, array $allowedChannelIds, array $context): bool
    {
        if ($featureEnabled !== true) {
            return false;
        }

        $sno = trim((string) ($context['sno'] ?? ''));
        if ($sno === '') {
            return false;
        }

        if ($allowedSno === []) {
            return false;
        }

        if (!in_array($sno, $allowedSno, true)) {
            return false;
        }

        if ($allowedChannelIds !== []) {
            $channelId = trim((string) ($context['channelId'] ?? ''));
            if ($channelId === '') {
                return false;
            }

            if (!in_array($channelId, $allowedChannelIds, true)) {
                return false;
            }
        }

        return true;
    }
}
