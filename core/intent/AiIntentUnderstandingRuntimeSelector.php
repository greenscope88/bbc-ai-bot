<?php

declare(strict_types=1);



require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiRuntimeIntentTranslator.php';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logger.php';



/**

 * Phase 2-D Step 2-D-3-3 — AI Intent Understanding Runtime Selector.

 *

 * B0: Authoritative source selection (AIU success or fail-closed).

 * Runtime Intent translation delegated to AiRuntimeIntentTranslator.

 */

final class AiIntentUnderstandingRuntimeSelector

{

    public const FLAG_ENABLED = 'intent_understanding_authoritative_enabled';



    public const FLAG_TENANTS = 'intent_understanding_authoritative_tenant_snos';



    public const SOURCE_AIU = 'aiu';



    public const SOURCE_FAIL_CLOSED = 'fail_closed';



    public const FAILURE_REASON_RUNTIME = 'AIU_RUNTIME_FAILURE';



    public const FAILURE_REASON_AUTHORITY_DISABLED = 'AIU_AUTHORITY_DISABLED';



    public const UNDERSTANDING_GEMINI_AIU = 'gemini_aiu';



    /**

     * @param array<string, mixed> $config

     */

    public static function isAuthoritativeEnabled(array $config, string $tenantSno): bool

    {

        $enabled = isset($config[self::FLAG_ENABLED]) && (bool) $config[self::FLAG_ENABLED];

        if (!$enabled) {

            return false;

        }



        $tenantSno = trim($tenantSno);

        if ($tenantSno === '') {

            return false;

        }



        $allow = isset($config[self::FLAG_TENANTS]) && is_array($config[self::FLAG_TENANTS])

            ? array_values(array_filter(array_map('strval', $config[self::FLAG_TENANTS])))

            : [];



        return in_array($tenantSno, $allow, true);

    }



    /**

     * @param array<string, mixed>              $params

     * @param AiIntentUnderstandingRuntime|null $runtime

     * @return array<string, mixed>

     */

    public static function resolve(array $params, ?AiIntentUnderstandingRuntime $runtime = null): array

    {

        try {

            $config = isset($params['config']) && is_array($params['config'])

                ? $params['config']

                : self::loadDefaultConfig();



            $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));



            if (!self::isAuthoritativeEnabled($config, $tenantSno)) {

                return self::failClosedResult(self::FAILURE_REASON_AUTHORITY_DISABLED);

            }



            $conversationId = trim((string) ($params['conversation_id'] ?? ''));

            $message = (string) ($params['message'] ?? '');

            $now = ($params['now'] ?? null) instanceof \DateTimeImmutable

                ? $params['now']

                : new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));



            $runtime = $runtime ?? new AiIntentUnderstandingRuntime();



            $understandContext = [

                'conversation_id' => $conversationId,

                'now' => $now,

                'tenant_sno' => $tenantSno,

            ];

            if (($params['reference_date'] ?? null) instanceof \DateTimeImmutable) {

                $understandContext['reference_date'] = $params['reference_date'];

            }

            foreach ([
                'channel',
                'channel_id',
                'line_user_id',
                'webhook_event_id',
                'trace_id',
                'request_id',
            ] as $contextKey) {
                if (!array_key_exists($contextKey, $params)) {
                    continue;
                }
                $value = trim((string) $params[$contextKey]);
                if ($value !== '') {
                    $understandContext[$contextKey] = $value;
                }
            }



            $result = $runtime->understand($message, $understandContext);

            $aiuIntent = $result->getIntent();

            $owner = $result->getOwnerSnapshot();

            $intentType = AiRuntimeIntentTranslator::fromUnderstandingResult($result);



            if ($owner === ConversationOwner::HUMAN) {

                return [

                    'intent_type' => $intentType,

                    'runtime_source' => self::SOURCE_AIU,

                    'human_blocked' => true,

                    'aiu_intent' => $aiuIntent,

                    'aiu_result' => $result,

                ];

            }



            return [

                'intent_type' => $intentType,

                'runtime_source' => self::SOURCE_AIU,

                'human_blocked' => false,

                'aiu_intent' => $aiuIntent,

                'aiu_result' => $result,

            ];

        } catch (\Throwable $e) {

            Logger::log('saas_router.log', 'aiu_selector_fail_closed', [

                'tenant_sno' => trim((string) ($params['tenant_sno'] ?? '')),

                'conversation_id' => trim((string) ($params['conversation_id'] ?? '')),

                'failure_reason' => self::FAILURE_REASON_RUNTIME,

                'error' => $e->getMessage(),

            ]);



            return self::failClosedResult(self::FAILURE_REASON_RUNTIME);

        }

    }



    /**

     * @return array{runtime_source: string, failure_reason: string}

     */

    private static function failClosedResult(string $failureReason): array

    {

        return [

            'runtime_source' => self::SOURCE_FAIL_CLOSED,

            'failure_reason' => $failureReason,

        ];

    }



    /**

     * @param array<string, mixed> $intentSelection

     * @return array{understanding_source: string}

     */

    public static function resolveProductUnderstandingTrace(

        array $intentSelection,

        ?BatsSearchIntent $authoritativeProductIntent,

        string $tenantSno,

        ?array $config = null

    ): array {

        unset($tenantSno, $config);



        if (($intentSelection['runtime_source'] ?? '') === self::SOURCE_AIU) {

            return [

                'understanding_source' => self::UNDERSTANDING_GEMINI_AIU,

            ];

        }



        return [

            'understanding_source' => self::UNDERSTANDING_GEMINI_AIU,

        ];

    }



    /**

     * @return array<string, mixed>

     */

    private static function loadDefaultConfig(): array

    {

        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bats_feature.php';

        if (!is_file($path)) {

            return [];

        }



        $loaded = require $path;



        return is_array($loaded) ? $loaded : [];

    }

}


