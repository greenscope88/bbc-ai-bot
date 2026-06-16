<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';

/**
 * Phase 9-C-1d-β1 pilot feature gate (RD-003 subset).
 *
 * Default OFF — enabled only for travel_b sno + BATS測試 message prefix.
 */
final class Phase9C1FeatureGate
{
    public const TRAVEL_B_SNO = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;

    public const MESSAGE_PREFIX = 'BATS測試';

    /**
     * @param array{sno?: string|null, userMessage?: string|null, message?: string|null} $context
     * @return array<string, mixed>
     */
    public static function evaluate(array $context = []): array
    {
        $sno = trim((string) ($context['sno'] ?? ''));
        $message = trim((string) ($context['userMessage'] ?? $context['message'] ?? ''));
        $prefix = self::MESSAGE_PREFIX;
        $prefixPassed = $prefix !== ''
            && $message !== ''
            && mb_substr($message, 0, mb_strlen($prefix, 'UTF-8'), 'UTF-8') === $prefix;
        $snoPassed = $sno !== '' && $sno === self::TRAVEL_B_SNO;

        $enabled = $snoPassed && $prefixPassed;
        $reason = 'disabled';
        if (!$snoPassed) {
            $reason = $sno === '' ? 'missing_tenant_sno' : 'tenant_sno_not_allowlisted';
        } elseif (!$prefixPassed) {
            $reason = 'message_prefix_mismatch';
        } else {
            $reason = 'all_conditions_met';
        }

        return [
            'enabled' => $enabled,
            'reason' => $reason,
            'tenant_sno' => $sno,
            'expected_tenant_sno' => self::TRAVEL_B_SNO,
            'message_prefix' => $prefix,
            'message_prefix_check_passed' => $prefixPassed,
            'final_route' => $enabled ? 'phase_9c1_structured_pilot' : 'legacy',
        ];
    }

    /**
     * @param array{sno?: string|null, userMessage?: string|null, message?: string|null} $context
     */
    public static function isEnabled(array $context = []): bool
    {
        return (bool) (self::evaluate($context)['enabled'] ?? false);
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
