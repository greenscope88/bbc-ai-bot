<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'logger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ResolvedTenant.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'TenantRegistryInterface.php';

/**
 * Stage 1-B-18 safety gate.
 * Phase 2A Stage 3: ConfigTenantRegistry features.tour_prompt when tenant resolves; legacy allowlist on miss.
 */
final class TourPromptFeatureGate
{
    private const FEATURE_ENABLED = true;

    /** @var list<string> */
    private const ALLOWED_SNO = ['e1fd133c7e8e45a1'];

    /** @var list<string> */
    private const ALLOWED_CHANNEL_IDS = [];

  /**
   * @param array{sno?: string|null, channelId?: string|null, userId?: string|null} $context
   */
    public static function isEnabled(array $context = [], ?TenantRegistryInterface $registryOverride = null): bool
    {
        try {
            if (self::FEATURE_ENABLED !== true) {
                self::logFeatureDecision($context, false, false, 'legacy', 'master_constant_off');

                return false;
            }

            if (!self::isGlobalTourPromptMasterEnabled($registryOverride)) {
                self::logFeatureDecision($context, false, false, 'legacy', 'global_master_off');

                return false;
            }

            $registryEnabled = self::tryRegistryFeature($context, 'tour_prompt', $registryOverride);
            if ($registryEnabled !== null) {
                self::logFeatureDecision($context, true, $registryEnabled, 'registry', 'tenant_feature');

                return $registryEnabled;
            }

            $legacyEnabled = self::check(
                self::FEATURE_ENABLED,
                self::ALLOWED_SNO,
                self::ALLOWED_CHANNEL_IDS,
                $context
            );
            self::logFeatureDecision($context, false, $legacyEnabled, 'legacy', 'allowlist');

            return $legacyEnabled;
        } catch (\Throwable $e) {
            self::logFeatureDecision($context, false, false, 'legacy', 'exception', $e->getMessage());

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

    /**
     * Resolve tenant from registry by sno, then channelId. Null when not in registry.
     *
     * @param array{sno?: string|null, channelId?: string|null} $context
     */
    public static function resolveRegistryTenant(
        array $context,
        ?TenantRegistryInterface $registryOverride = null
    ): ?ResolvedTenant {
        $registry = $registryOverride ?? new ConfigTenantRegistry();

        $sno = trim((string) ($context['sno'] ?? ''));
        if ($sno !== '') {
            $bySno = $registry->resolveBySno($sno);
            if ($bySno !== null) {
                return $bySno;
            }
        }

        $channelId = trim((string) ($context['channelId'] ?? ''));
        if ($channelId !== '') {
            return $registry->resolveByChannel($channelId);
        }

        return null;
    }

    /**
     * @param array{sno?: string|null, channelId?: string|null} $context
     */
    public static function tryRegistryFeature(
        array $context,
        string $feature,
        ?TenantRegistryInterface $registryOverride = null
    ): ?bool {
        $tenant = self::resolveRegistryTenant($context, $registryOverride);
        if ($tenant === null) {
            return null;
        }

        if ($tenant->getStatus() === 'disabled') {
            return false;
        }

        return $tenant->isFeatureEnabled($feature);
    }

    public static function isGlobalTourPromptMasterEnabled(?TenantRegistryInterface $registryOverride = null): bool
    {
        $registry = $registryOverride ?? new ConfigTenantRegistry();
        $global = $registry->getGlobalConfig();
        if (!array_key_exists('tour_prompt_master_enabled', $global)) {
            return true;
        }

        return ($global['tour_prompt_master_enabled'] ?? false) === true;
    }

    public static function isGlobalHybridSearchMasterEnabled(?TenantRegistryInterface $registryOverride = null): bool
    {
        $registry = $registryOverride ?? new ConfigTenantRegistry();
        $global = $registry->getGlobalConfig();
        if (!array_key_exists('hybrid_search_master_enabled', $global)) {
            return true;
        }

        return ($global['hybrid_search_master_enabled'] ?? false) === true;
    }

    /**
     * @param array{sno?: string|null, channelId?: string|null} $context
     */
    private static function logFeatureDecision(
        array $context,
        bool $registryHit,
        bool $featureEnabled,
        string $featureSource,
        string $reason,
        ?string $error = null
    ): void {
        $contextLog = [
            'registry_hit' => $registryHit,
            'feature_source' => $featureSource,
            'feature_enabled' => $featureEnabled,
            'reason' => $reason,
            'sno' => trim((string) ($context['sno'] ?? '')),
            'channel_id' => trim((string) ($context['channelId'] ?? '')),
        ];
        if ($error !== null && $error !== '') {
            $contextLog['error'] = $error;
        }

        Logger::log('saas_router.log', 'tour_prompt_feature_gate', $contextLog);
    }
}
