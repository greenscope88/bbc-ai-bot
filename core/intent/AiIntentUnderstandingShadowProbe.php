<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'KnowledgeIntentDetector.php';

/**
 * Phase 2-D Step 2-D-3-1 — AI Intent Understanding Shadow Probe.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md（AIU Runtime / AiIntentUnderstandingResult）；
 *       Phase 2-D-3 Runtime Integration Review（Shadow Strategy）.
 *
 * 唯一職責：在「shadow 模式」下平行執行 AI Intent Understanding Runtime，觀察其
 * 對某次客戶訊息的理解結果（intent / dispatch_plan / execution_hint / owner_snapshot），
 * 並與既有 legacy KnowledgeIntentDetector 的 intent_type 做 parity 比對後寫 log。
 *
 * 嚴格邊界（Step 2-D-3-1 Scope；完全比照 ConversationRuntimeShadowProbe）：
 *   - **永不改變實際回覆**：不產生 / 不回傳 reply text、不呼叫 LineService、
 *     不影響 final_route / transport / Knowledge / Product Runtime。
 *   - **永不 throw**：所有評估包在 try/catch；例外只記 log 後回傳 executed=false。
 *   - **flag default OFF**：flag 關閉或 tenant 未命中時直接短路，**不建立 Runtime、
 *     不讀取任何狀態**。
 *   - **唯讀 / Owner First**：AIU Runtime 僅讀取 Owner / Memory / State（不寫）；
 *     本 probe 亦不寫任何狀態，不觸碰 legacy resolver / FinalReplyGate / webhook。
 *   - 不含任何 Compat Shim / Alias / Bridge / Fallback / Legacy Mapping（僅 parity 比對）。
 */
final class AiIntentUnderstandingShadowProbe
{
    public const FLAG_ENABLED = 'intent_understanding_shadow_enabled';

    public const FLAG_TENANTS = 'intent_understanding_shadow_tenant_snos';

    /**
     * flag on 且 tenant 命中時才視為啟用。
     *
     * @param array<string, mixed> $config
     */
    public static function isEnabled(array $config, string $tenantSno): bool
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
     * 執行一次 shadow understanding（永不 throw）。
     *
     * 僅消費已明確解析之事實／訊號：
     *   $params = [
     *     'tenant_sno'         => string
     *     'conversation_id'    => string
     *     'message'            => string   // 客戶訊息（與 legacy detect 相同來源 queryText）
     *     'legacy_intent_type' => string   // legacy KnowledgeIntentDetector intent_type（記錄用）
     *     'trace_id'           => string
     *     'now'                => ?\DateTimeImmutable
     *     'reference_date'     => ?\DateTimeImmutable
     *     'config'             => ?array   // 省略時自 config/bats_feature.php 載入
     *   ]
     *
     * @param array<string, mixed>               $params
     * @param AiIntentUnderstandingRuntime|null  $runtime 測試可注入 in-memory runtime
     * @param callable|null                      $logger  fn(string $step, array $context): void（測試用）
     * @return array{
     *   executed: bool,
     *   reason: string,
     *   aiu_intent?: string,
     *   legacy_intent_type?: string,
     *   dispatch_plan?: string,
     *   execution_hint?: string|null,
     *   owner_snapshot?: string,
     *   clarification_required?: bool,
     *   clarification_reason?: string,
     *   message_hash?: string,
     *   parity?: bool
     * }
     */
    public static function run(
        array $params,
        ?AiIntentUnderstandingRuntime $runtime = null,
        ?callable $logger = null
    ): array {
        try {
            $config = isset($params['config']) && is_array($params['config'])
                ? $params['config']
                : self::loadDefaultConfig();

            $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
            if (!self::isEnabled($config, $tenantSno)) {
                return ['executed' => false, 'reason' => 'shadow_disabled_or_tenant_unmatched'];
            }

            $conversationId = trim((string) ($params['conversation_id'] ?? ''));
            if ($conversationId === '') {
                return ['executed' => false, 'reason' => 'missing_conversation_id'];
            }

            $message = (string) ($params['message'] ?? '');
            $legacyIntentType = (string) ($params['legacy_intent_type'] ?? '');
            $traceId = (string) ($params['trace_id'] ?? '');
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

            $aiuIntent = $result->getIntent();
            $dispatchPlan = $result->getDispatchPlan();
            $executionHint = $result->getExecutionHint();
            $ownerSnapshot = $result->getOwnerSnapshot();
            $clarificationRequired = $result->isClarificationRequired();
            $clarificationReason = $result->getClarificationReason();
            $parity = self::isParity($legacyIntentType, $aiuIntent);

            $logContext = [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'conversation_id' => $conversationId,
                'legacy_intent_type' => $legacyIntentType,
                'aiu_intent' => $aiuIntent,
                'dispatch_plan' => $dispatchPlan,
                'execution_hint' => $executionHint,
                'owner_snapshot' => $ownerSnapshot,
                // Additive (Phase 2-D-3-2A): Routing / Clarification parity inputs.
                'clarification_required' => $clarificationRequired,
                'clarification_reason' => $clarificationReason,
                // Reproducible, privacy-safe message identifier (no raw content).
                'message_hash' => self::messageHash($message),
                'parity' => $parity,
                'shadow_mode' => true,
            ];

            self::emit('intent_understanding_shadow_probe', $logContext, $logger);

            return [
                'executed' => true,
                'reason' => 'evaluated',
                'aiu_intent' => $aiuIntent,
                'legacy_intent_type' => $legacyIntentType,
                'dispatch_plan' => $dispatchPlan,
                'execution_hint' => $executionHint,
                'owner_snapshot' => $ownerSnapshot,
                'clarification_required' => $clarificationRequired,
                'clarification_reason' => $clarificationReason,
                'message_hash' => self::messageHash($message),
                'parity' => $parity,
            ];
        } catch (\Throwable $e) {
            self::emit('intent_understanding_shadow_probe_error', [
                'trace_id' => (string) ($params['trace_id'] ?? ''),
                'message' => $e->getMessage(),
            ], $logger);

            return ['executed' => false, 'reason' => 'exception'];
        }
    }

    /**
     * Parity 比對：將 legacy intent_type 映射為 AIU intent category 後與 AIU 結果比較。
     *
     * 此為純觀察用之 parity 判定，**非** Runtime 路由邏輯；不影響任何 Runtime。
     */
    private static function isParity(string $legacyIntentType, string $aiuIntent): bool
    {
        switch ($legacyIntentType) {
            case KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH:
                $mapped = AiIntentCategory::PRODUCT_SEARCH;
                break;
            case KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY:
                $mapped = AiIntentCategory::KNOWLEDGE;
                break;
            case KnowledgeIntentDetector::INTENT_AMBIGUOUS:
                $mapped = AiIntentCategory::AMBIGUOUS;
                break;
            default:
                return false;
        }

        return $mapped === $aiuIntent;
    }

    /**
     * Privacy-safe, reproducible message identifier for mismatch triage.
     *
     * Returns a truncated SHA-256 hex of the raw message — never the raw content
     * (資料保護原則). Empty message → '' (stable, distinguishable).
     */
    private static function messageHash(string $message): string
    {
        if ($message === '') {
            return '';
        }

        return substr(hash('sha256', $message), 0, 16);
    }

    /**
     * Emit a shadow log line. Defensive: logging failures must never bubble out
     * of the probe (preserves the never-throw guarantee even in the catch path).
     *
     * @param array<string, mixed> $context
     */
    private static function emit(string $step, array $context, ?callable $logger): void
    {
        try {
            if ($logger !== null) {
                $logger($step, $context);

                return;
            }

            if (class_exists('Logger')) {
                \Logger::log('saas_router.log', $step, $context);
            }
        } catch (\Throwable $e) {
            // Swallow: a shadow log failure must not affect the main flow.
        }
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
