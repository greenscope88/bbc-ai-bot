<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DispatchPlan.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'KnowledgeIntentDetector.php';

/**
 * Phase 2-D Step 2-D-3-3 — AI Intent Understanding Runtime Selector.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §5 / §6（dispatch_plan 權威路由）;
 *       Phase 2-D-3 Runtime Integration Review（Authoritative Switch Strategy）.
 *
 * 唯一職責：依 Feature Flag 在 Legacy Intent Detection 與 AIU Runtime 之間
 * 做 **Runtime Selection**（僅選擇權威來源，不改變 Runtime 行為語意）。
 *
 * 嚴格邊界（Step 2-D-3-3 Scope）：
 *   - **flag default OFF**：未命中 tenant 時 100% Legacy（byte-identical selection）。
 *   - **永不 throw**：例外時 fallback Legacy，不影響 Production Reply Flow。
 *   - **不改 AIU Logic / Contract / Architecture**：僅消費 AIU 輸出並映射至
 *     既有 legacy `intent_type` 供 downstream 分支使用。
 *   - **Shadow 獨立**：本 Selector 不取代 Shadow Probe；caller 仍應以
 *     `legacy_intent_type` 餵 Shadow 做 parity 觀測。
 *   - Owner = HUMAN（dispatch_plan = human）時回傳 `human_blocked = true`，
 *     由 caller 執行與 Human Takeover 一致之 early block（不送 AI 回覆）。
 */
final class AiIntentUnderstandingRuntimeSelector
{
    public const FLAG_ENABLED = 'intent_understanding_authoritative_enabled';

    public const FLAG_TENANTS = 'intent_understanding_authoritative_tenant_snos';

    public const SOURCE_LEGACY = 'legacy';

    public const SOURCE_AIU = 'aiu';

    /**
     * flag on 且 tenant 命中時才視為 Authoritative 啟用。
     *
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
     * Resolve the authoritative intent selection for routing (never throw).
     *
     * Caller MUST supply `legacy_intent_type` from a prior KnowledgeIntentDetector::detect()
     * so Shadow Probe parity always compares against the true legacy outcome.
     *
     * $params = [
     *   'tenant_sno'         => string
     *   'conversation_id'    => string
     *   'message'            => string
     *   'legacy_intent_type' => string   // required — from legacy detect
     *   'now'                => ?\DateTimeImmutable
     *   'reference_date'     => ?\DateTimeImmutable
     *   'config'             => ?array   // omit → config/bats_feature.php
     * ]
     *
     * @param array<string, mixed>               $params
     * @param AiIntentUnderstandingRuntime|null  $runtime  test injection
     * @return array{
     *   intent_type: string,
     *   runtime_source: string,
     *   legacy_intent_type: string,
     *   human_blocked: bool,
     *   dispatch_plan?: string,
     *   execution_hint?: string|null,
     *   aiu_intent?: string
     * }
     */
    public static function resolve(array $params, ?AiIntentUnderstandingRuntime $runtime = null): array
    {
        $legacyIntentType = (string) ($params['legacy_intent_type'] ?? '');

        try {
            $config = isset($params['config']) && is_array($params['config'])
                ? $params['config']
                : self::loadDefaultConfig();

            $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));

            if (!self::isAuthoritativeEnabled($config, $tenantSno)) {
                return self::legacySelection($legacyIntentType);
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

            $result = $runtime->understand($message, $understandContext);
            $dispatchPlan = $result->getDispatchPlan();
            $aiuIntent = $result->getIntent();

            if ($dispatchPlan === DispatchPlan::HUMAN) {
                return [
                    'intent_type' => $legacyIntentType,
                    'runtime_source' => self::SOURCE_AIU,
                    'legacy_intent_type' => $legacyIntentType,
                    'human_blocked' => true,
                    'dispatch_plan' => $dispatchPlan,
                    'execution_hint' => $result->getExecutionHint(),
                    'aiu_intent' => $aiuIntent,
                ];
            }

            return [
                'intent_type' => self::mapToLegacyIntentType($result),
                'runtime_source' => self::SOURCE_AIU,
                'legacy_intent_type' => $legacyIntentType,
                'human_blocked' => false,
                'dispatch_plan' => $dispatchPlan,
                'execution_hint' => $result->getExecutionHint(),
                'aiu_intent' => $aiuIntent,
            ];
        } catch (\Throwable $e) {
            return self::legacySelection($legacyIntentType);
        }
    }

    /**
     * Map AIU dispatch_plan + intent to legacy intent_type for existing downstream branches.
     *
     * Mapping preserves the frozen Dispatch Contract:
     *   knowledge      → knowledge_query
     *   product        → product_search
     *   clarification  → product_search (product path) or ambiguous (ambiguous path)
     */
    public static function mapToLegacyIntentType(AiIntentUnderstandingResult $result): string
    {
        $dispatchPlan = $result->getDispatchPlan();
        $aiuIntent = $result->getIntent();

        if ($dispatchPlan === DispatchPlan::KNOWLEDGE) {
            return KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY;
        }

        if ($dispatchPlan === DispatchPlan::PRODUCT) {
            return KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH;
        }

        if ($dispatchPlan === DispatchPlan::CLARIFICATION) {
            if ($aiuIntent === AiIntentCategory::PRODUCT_SEARCH) {
                return KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH;
            }

            return KnowledgeIntentDetector::INTENT_AMBIGUOUS;
        }

        return KnowledgeIntentDetector::INTENT_AMBIGUOUS;
    }

    /**
     * @return array{
     *   intent_type: string,
     *   runtime_source: string,
     *   legacy_intent_type: string,
     *   human_blocked: bool
     * }
     */
    private static function legacySelection(string $legacyIntentType): array
    {
        return [
            'intent_type' => $legacyIntentType,
            'runtime_source' => self::SOURCE_LEGACY,
            'legacy_intent_type' => $legacyIntentType,
            'human_blocked' => false,
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
