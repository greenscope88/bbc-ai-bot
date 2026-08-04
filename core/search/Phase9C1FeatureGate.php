<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'TenantRegistryInterface.php';

/**
 * Phase 9-C-1 BATS Runtime tenant gate.
 *
 * Production membership: tenant_registry status in {staging,enabled}
 * AND features.bats_runtime === true. No hardcoded tenant sno allowlist.
 */
final class Phase9C1FeatureGate
{
    public const TRAVEL_B_SNO = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;

    public const MESSAGE_PREFIX = 'BATS測試';

    public const FEATURE_KEY = 'bats_runtime';

    /** @var TenantRegistryInterface|null */
    private static $registryOverride = null;

    public static function setRegistryOverrideForTesting(?TenantRegistryInterface $registry): void
    {
        self::$registryOverride = $registry;
    }

    /**
     * @param array{sno?: string|null, userMessage?: string|null, message?: string|null} $context
     * @return array<string, mixed>
     */
    public static function evaluate(array $context = []): array
    {
        $sno = trim((string) ($context['sno'] ?? ''));
        $message = trim((string) ($context['userMessage'] ?? $context['message'] ?? ''));
        $prefix = self::MESSAGE_PREFIX;
        $prefixPresent = $prefix !== ''
            && $message !== ''
            && mb_substr($message, 0, mb_strlen($prefix, 'UTF-8'), 'UTF-8') === $prefix;
        $tenantEnabled = self::isBatsEnabledTenant($sno);

        $reason = 'disabled';
        if ($sno === '') {
            $reason = 'missing_tenant_sno';
        } elseif (!$tenantEnabled) {
            $reason = 'tenant_activation_disabled';
        } else {
            $reason = 'all_conditions_met';
        }

        return [
            'enabled' => $tenantEnabled,
            'reason' => $reason,
            'tenant_sno' => $sno,
            'bats_enabled_tenant' => $tenantEnabled,
            'activation_feature' => self::FEATURE_KEY,
            'message_prefix' => $prefix,
            'message_prefix_present' => $prefixPresent,
            'final_route' => $tenantEnabled ? 'phase_9c1_structured_pilot' : 'legacy',
        ];
    }

    /**
     * @param array{sno?: string|null, userMessage?: string|null, message?: string|null} $context
     */
    public static function isEnabled(array $context = []): bool
    {
        return (bool) (self::evaluate($context)['enabled'] ?? false);
    }

    public static function isBatsEnabledTenant(string $tenantSno): bool
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            return false;
        }

        $registry = self::$registryOverride ?? new ConfigTenantRegistry();
        $tenant = $registry->resolveBySno($tenantSno);
        if ($tenant === null) {
            return false;
        }

        $status = $tenant->getStatus();
        if ($status !== 'staging' && $status !== 'enabled') {
            return false;
        }

        return $tenant->isFeatureEnabled(self::FEATURE_KEY);
    }

    public static function stripPilotQueryPrefix(string $message): string
    {
        $message = trim($message);
        $prefix = self::MESSAGE_PREFIX;
        if ($message === '' || mb_substr($message, 0, mb_strlen($prefix, 'UTF-8'), 'UTF-8') !== $prefix) {
            return $message;
        }

        $rest = trim(mb_substr($message, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));

        return $rest !== '' ? $rest : $message;
    }
}
