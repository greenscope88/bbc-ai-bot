<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';

/**
 * Phase 2-D Step 2-D-3-1 — AI Intent Understanding Shadow Probe.
 *
 * B0 MC-6: AIU-only observability — parallel AIU Runtime understanding for shadow
 * rollout; no Legacy Understanding input, parity comparison, or Legacy log fields.
 *
 * 嚴格邊界（完全比照 ConversationRuntimeShadowProbe）：
 *   - **永不改變實際回覆**：不產生 / 不回傳 reply text、不呼叫 LineService、
 *     不影響 final_route / transport / Knowledge / Product Runtime。
 *   - **永不 throw**：所有評估包在 try/catch；例外只記 log 後回傳 executed=false。
 *   - **flag default OFF**：flag 關閉或 tenant 未命中時直接短路，**不建立 Runtime、
 *     不讀取任何狀態**。
 *   - **唯讀 / Owner First**：AIU Runtime 僅讀取 Owner / Memory / State（不寫）；
 *     本 probe 亦不寫任何狀態，不觸碰 FinalReplyGate / webhook。
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
     * 執行一次 AIU-only shadow understanding（永不 throw）。
     *
     * 僅消費已明確解析之事實／訊號：
     *   $params = [
     *     'tenant_sno'      => string
     *     'conversation_id' => string
     *     'message'         => string   // 客戶訊息（與 production queryText 相同來源）
     *     'trace_id'        => string
     *     'now'             => ?\DateTimeImmutable
     *     'reference_date'  => ?\DateTimeImmutable
     *     'config'          => ?array   // 省略時自 config/bats_feature.php 載入
     *   ]
     *
     * @param array<string, mixed>               $params
     * @param AiIntentUnderstandingRuntime|null  $runtime 測試可注入 in-memory runtime
     * @param callable|null                      $logger  fn(string $step, array $context): void（測試用）
     * @return array{
     *   executed: bool,
     *   reason: string,
     *   aiu_intent?: string,
     *   owner_snapshot?: string,
     *   clarification_required?: bool,
     *   clarification_reason?: string,
     *   message_hash?: string
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
            $ownerSnapshot = $result->getOwnerSnapshot();
            $clarificationRequired = $result->isClarificationRequired();
            $clarificationReason = $result->getClarificationReason();

            $logContext = [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'conversation_id' => $conversationId,
                'aiu_intent' => $aiuIntent,
                'owner_snapshot' => $ownerSnapshot,
                'clarification_required' => $clarificationRequired,
                'clarification_reason' => $clarificationReason,
                'message_hash' => self::messageHash($message),
                'shadow_mode' => true,
            ];

            self::emit('intent_understanding_shadow_probe', $logContext, $logger);

            return [
                'executed' => true,
                'reason' => 'evaluated',
                'aiu_intent' => $aiuIntent,
                'owner_snapshot' => $ownerSnapshot,
                'clarification_required' => $clarificationRequired,
                'clarification_reason' => $clarificationReason,
                'message_hash' => self::messageHash($message),
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
     * Privacy-safe, reproducible message identifier for triage.
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
