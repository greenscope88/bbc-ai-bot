<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationPolicy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationLifecycle.php';

/**
 * Phase 2-B Step 4-B — Conversation Runtime Shadow Probe.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md（Conversation Runtime Pipeline /
 *       Owner-based Reply Gate）；Step 4-B Controlled Integration Proposal。
 *
 * 唯一職責：在「shadow 模式」下觀察 ConversationRuntimeFacade 對某次客戶訊息的
 * 決策（Owner / Reply Gate / Lifecycle），並與既有 legacy FinalReplyGate 的
 * 結論做 parity 比對後寫 log。
 *
 * 嚴格邊界（Step 4-B Scope）：
 *   - **永不改變實際回覆**：不產生 / 不回傳 reply text、不呼叫 LineService、
 *     不影響 final_route / transport。
 *   - **永不 throw**：所有評估包在 try/catch；例外只記 log 後回傳 executed=false。
 *   - **flag default OFF**：flag 關閉或 tenant 未命中時直接短路，**不建立 Facade、
 *     不寫任何 conversation 狀態**。
 *   - **不觸碰 legacy**：不寫 runtime/conversation_status，不修改
 *     ConversationStatusResolver / FinalReplyGate / webhook。
 *   - 不含任何 Compat Shim / Alias / Bridge / Fallback / Legacy Mapping。
 *
 * Facade 啟用後使用其自有、與 legacy 隔離的儲存
 * （runtime/conversation_state、runtime/conversation_memory）。
 */
final class ConversationRuntimeShadowProbe
{
    public const FLAG_ENABLED = 'conversation_runtime_shadow_enabled';

    public const FLAG_TENANTS = 'conversation_runtime_shadow_tenant_snos';

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
     * 執行一次 shadow evaluation（永不 throw）。
     *
     * 僅消費已明確解析之事實／訊號：
     *   $params = [
     *     'tenant_sno'                => string
     *     'conversation_id'           => string
     *     'intent_type'               => string
     *     'legacy_conversation_status'=> string  // legacy resolver 結果（記錄用）
     *     'legacy_allowed'            => bool    // legacy FinalReplyGate 結論
     *     'trace_id'                  => string
     *     'now'                       => ?\DateTimeImmutable
     *     'config'                    => ?array  // 省略時自 config/bats_feature.php 載入
     *   ]
     *
     * @param array<string, mixed>      $params
     * @param ConversationRuntimeFacade $facade 測試可注入 in-memory facade
     * @param callable|null             $logger fn(string $step, array $context): void（測試用）
     * @return array{
     *   executed: bool,
     *   reason: string,
     *   owner?: string,
     *   shadow_allowed?: bool,
     *   legacy_allowed?: bool,
     *   parity?: bool,
     *   lifecycle_stage?: string
     * }
     */
    public static function run(
        array $params,
        ?ConversationRuntimeFacade $facade = null,
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

            $intentType = (string) ($params['intent_type'] ?? '');
            $legacyAllowed = (bool) ($params['legacy_allowed'] ?? false);
            $legacyStatus = (string) ($params['legacy_conversation_status'] ?? '');
            $traceId = (string) ($params['trace_id'] ?? '');
            $now = ($params['now'] ?? null) instanceof \DateTimeImmutable
                ? $params['now']
                : new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

            $facade = $facade ?? new ConversationRuntimeFacade();

            $decision = $facade->handleCustomerMessage(
                $conversationId,
                [
                    'intent_type' => $intentType,
                    'current_stage' => ConversationLifecycle::STAGE_ACTIVE,
                    'tenant_policy' => ConversationPolicy::create(),
                ],
                $now
            );

            $shadowAllowed = (bool) ($decision['reply_gate']['allowed'] ?? false);
            $owner = (string) ($decision['owner'] ?? '');
            $parity = $shadowAllowed === $legacyAllowed;

            $logContext = [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'conversation_id' => $conversationId,
                'intent_type' => $intentType,
                'legacy_conversation_status' => $legacyStatus,
                'legacy_allowed' => $legacyAllowed,
                'shadow_owner' => $owner,
                'shadow_allowed' => $shadowAllowed,
                'shadow_lifecycle_stage' => (string) ($decision['lifecycle_stage'] ?? ''),
                'shadow_ai_resume_applied' => (bool) ($decision['ai_resume_applied'] ?? false),
                'parity' => $parity,
                'shadow_mode' => true,
            ];

            self::emit('conversation_runtime_shadow_probe', $logContext, $logger);

            return [
                'executed' => true,
                'reason' => 'evaluated',
                'owner' => $owner,
                'shadow_allowed' => $shadowAllowed,
                'legacy_allowed' => $legacyAllowed,
                'parity' => $parity,
                'lifecycle_stage' => (string) ($decision['lifecycle_stage'] ?? ''),
            ];
        } catch (\Throwable $e) {
            self::emit('conversation_runtime_shadow_probe_error', [
                'trace_id' => (string) ($params['trace_id'] ?? ''),
                'message' => $e->getMessage(),
            ], $logger);

            return ['executed' => false, 'reason' => 'exception'];
        }
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
