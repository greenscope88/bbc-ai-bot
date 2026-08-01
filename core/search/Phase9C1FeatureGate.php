<?php

declare(strict_types=1);



require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TravelBMultiSourceLinkBuilder.php';



/**

 * Phase 9-C-1 BATS Runtime tenant gate (RD-003 subset).

 *

 * Enabled for allowlisted tenant_sno values. travel_b is the first production tenant.

 * Legacy pilot prefix (BATS測試) is optional and stripped when present.

 */

final class Phase9C1FeatureGate

{

    public const TRAVEL_B_SNO = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;



    public const MESSAGE_PREFIX = 'BATS測試';



    /** @var list<string> */

    private const BATS_ENABLED_TENANT_SNOS = [

        self::TRAVEL_B_SNO,

        '5fecdf66e9224bee', // travel_d

    ];



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



        $enabled = $tenantEnabled;

        $reason = 'disabled';

        if ($sno === '') {

            $reason = 'missing_tenant_sno';

        } elseif (!$tenantEnabled) {

            $reason = 'tenant_sno_not_allowlisted';

        } else {

            $reason = 'all_conditions_met';

        }



        return [

            'enabled' => $enabled,

            'reason' => $reason,

            'tenant_sno' => $sno,

            'bats_enabled_tenant' => $tenantEnabled,

            'bats_enabled_tenant_snos' => self::BATS_ENABLED_TENANT_SNOS,

            'message_prefix' => $prefix,

            'message_prefix_present' => $prefixPresent,

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



    public static function isBatsEnabledTenant(string $tenantSno): bool

    {

        $tenantSno = trim($tenantSno);

        if ($tenantSno === '') {

            return false;

        }



        return in_array($tenantSno, self::BATS_ENABLED_TENANT_SNOS, true);

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

