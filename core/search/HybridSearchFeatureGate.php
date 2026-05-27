<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logger.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tour_prompt_feature_gate.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'TenantRegistryInterface.php';

/**
 * Phase 2-C: Hybrid Smart Search gate (config-driven, default OFF).
 * Phase 2A Stage 3: ConfigTenantRegistry features.hybrid_search when tenant resolves; config allowlist on miss.
 */
final class HybridSearchFeatureGate
{
    /**
     * @param array{sno?: string|null, channelId?: string|null} $context
     * @param array<string, mixed>|null $configOverride tests / dry-run injection (skips registry)
     */
    public static function isEnabled(
        array $context = [],
        ?array $configOverride = null,
        ?TenantRegistryInterface $registryOverride = null
    ): bool {
        try {
            $cfg = self::resolveConfig($configOverride);

            if (($cfg['enabled'] ?? false) !== true) {
                self::logHybridDecision($context, false, false, 'legacy', 'config_disabled', false);

                return false;
            }

            if ($configOverride === null && !TourPromptFeatureGate::isGlobalHybridSearchMasterEnabled($registryOverride)) {
                self::logHybridDecision($context, false, false, 'legacy', 'global_master_off', false);

                return false;
            }

            if ($configOverride === null) {
                $registryEnabled = TourPromptFeatureGate::tryRegistryFeature(
                    $context,
                    'hybrid_search',
                    $registryOverride
                );
                if ($registryEnabled !== null) {
                    self::logHybridDecision($context, true, $registryEnabled, 'registry', 'tenant_feature', $registryEnabled);

                    return $registryEnabled;
                }
            }

            $legacyEnabled = TourPromptFeatureGate::check(
                true,
                self::stringList($cfg['allowed_sno'] ?? []),
                self::stringList($cfg['allowed_channels'] ?? []),
                $context
            );
            self::logHybridDecision($context, false, $legacyEnabled, 'legacy', 'allowlist', $legacyEnabled);

            return $legacyEnabled;
        } catch (\Throwable $e) {
            self::logHybridDecision($context, false, false, 'legacy', 'exception', false, $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $configOverride
     * @return array{enabled: bool, allowed_sno: list<string>, allowed_channels: list<string>, dry_run_log_enabled: bool}
     */
    public static function resolveConfig(?array $configOverride = null): array
    {
        if ($configOverride !== null) {
            return self::normalize($configOverride);
        }

        if (!function_exists('app_config_get')) {
            $bootstrap = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';
            if (is_file($bootstrap)) {
                require_once $bootstrap;
            }
        }

        $cfg = function_exists('app_config_get') ? app_config_get('gateway.hybrid_search', []) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }

        return self::normalize($cfg);
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{enabled: bool, allowed_sno: list<string>, allowed_channels: list<string>, dry_run_log_enabled: bool}
     */
    public static function normalize(array $cfg): array
    {
        return [
            'enabled' => isset($cfg['enabled']) && filter_var($cfg['enabled'], FILTER_VALIDATE_BOOLEAN),
            'allowed_sno' => self::stringList($cfg['allowed_sno'] ?? []),
            'allowed_channels' => self::stringList($cfg['allowed_channels'] ?? []),
            'dry_run_log_enabled' => !array_key_exists('dry_run_log_enabled', $cfg)
                || filter_var($cfg['dry_run_log_enabled'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array{sno?: string|null, channelId?: string|null} $context
     */
    private static function logHybridDecision(
        array $context,
        bool $registryHit,
        bool $featureEnabled,
        string $featureSource,
        string $reason,
        bool $hybridEnabled,
        ?string $error = null
    ): void {
        $contextLog = [
            'registry_hit' => $registryHit,
            'feature_source' => $featureSource,
            'feature_enabled' => $featureEnabled,
            'hybrid_enabled' => $hybridEnabled,
            'reason' => $reason,
            'sno' => trim((string) ($context['sno'] ?? '')),
            'channel_id' => trim((string) ($context['channelId'] ?? '')),
        ];
        if ($error !== null && $error !== '') {
            $contextLog['error'] = $error;
        }

        Logger::log('saas_router.log', 'hybrid_search_feature_gate', $contextLog);
    }
}
