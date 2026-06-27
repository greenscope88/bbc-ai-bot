<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationOwner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationReplyGate.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStateRuntime.php';

/**
 * Phase 2-B Step 4-C-1 — Conversation Reply Gate Dual Compare Probe.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §7（Owner-based Reply Gate,
 *       CA-003）；Step 4-C Controlled Integration Review。
 *
 * 唯一職責：在某一個 gate 決策點，將既有 legacy FinalReplyGate 的結論
 * （由呼叫端傳入）與新的 Owner-based ConversationReplyGate 結論並行比較，
 * 並寫一筆 parity log。
 *
 * 嚴格邊界（Step 4-C-1 Scope）：
 *   - **Compare-only**：legacy FinalReplyGate 仍是唯一正式權威；本 probe
 *     不回傳任何會被用於 allow/block 的決策、不改變回覆。
 *   - **永不 throw**：全程 try/catch；emit() 亦防禦化。
 *   - **flag default OFF**：flag 關閉或 tenant 未命中即短路。
 *   - **唯讀 State**：僅以 resolveEffectiveOwner() 讀取 Owner（不寫入
 *     runtime/conversation_state）；完全不觸碰 legacy runtime/conversation_status。
 *   - 不修改 FinalReplyGate / ConversationStatusResolver / webhook。
 *   - 不含任何 Compat Shim / Alias / Bridge / Fallback / Legacy Mapping。
 */
final class ConversationReplyGateCompareProbe
{
    public const FLAG_ENABLED = 'conversation_reply_gate_compare_enabled';

    public const FLAG_TENANTS = 'conversation_reply_gate_compare_tenant_snos';

    public const GATE_POINT_EARLY_BLOCK = 'early_block';
    public const GATE_POINT_KNOWLEDGE_FINAL = 'knowledge_final';
    public const GATE_POINT_PRODUCT_PREFLIGHT = 'product_preflight';
    public const GATE_POINT_PRODUCT_FINAL = 'product_final';

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
     * 於單一 gate 決策點執行 dual compare（永不 throw）。
     *
     * 僅消費呼叫端已決定之 legacy 事實，並唯讀解析 new Owner：
     *   $params = [
     *     'tenant_sno'      => string
     *     'conversation_id' => string
     *     'gate_point'      => string  // 見 GATE_POINT_*
     *     'legacy_status'   => string  // ConversationStatusResolver 狀態
     *     'legacy_allowed'  => bool    // legacy FinalReplyGate 結論（權威）
     *     'trace_id'        => string
     *     'now'             => ?\DateTimeImmutable
     *     'config'          => ?array  // 省略時自 config/bats_feature.php 載入
     *   ]
     *
     * @param array<string, mixed>        $params
     * @param ConversationStateRuntime|null $stateRuntime 測試可注入 in-memory runtime
     * @param callable|null                 $logger fn(string $step, array $context): void（測試用）
     * @return array{
     *   executed: bool,
     *   reason: string,
     *   gate_point?: string,
     *   legacy_allowed?: bool,
     *   new_owner?: string,
     *   new_allowed?: bool,
     *   parity?: bool
     * }
     */
    public static function compare(
        array $params,
        ?ConversationStateRuntime $stateRuntime = null,
        ?callable $logger = null
    ): array {
        try {
            $config = isset($params['config']) && is_array($params['config'])
                ? $params['config']
                : self::loadDefaultConfig();

            $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
            if (!self::isEnabled($config, $tenantSno)) {
                return ['executed' => false, 'reason' => 'compare_disabled_or_tenant_unmatched'];
            }

            $conversationId = trim((string) ($params['conversation_id'] ?? ''));
            if ($conversationId === '') {
                return ['executed' => false, 'reason' => 'missing_conversation_id'];
            }

            $gatePoint = (string) ($params['gate_point'] ?? '');
            $legacyStatus = (string) ($params['legacy_status'] ?? '');
            $legacyAllowed = (bool) ($params['legacy_allowed'] ?? false);
            $traceId = (string) ($params['trace_id'] ?? '');
            $now = ($params['now'] ?? null) instanceof \DateTimeImmutable
                ? $params['now']
                : new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

            $stateRuntime = $stateRuntime ?? new ConversationStateRuntime();

            // 唯讀解析 new Owner（resolveEffectiveOwner 不寫入狀態）。
            $newOwner = $stateRuntime->resolveEffectiveOwner($conversationId, $now);
            $newGate = ConversationReplyGate::evaluate($newOwner);
            $newAllowed = (bool) ($newGate['allowed'] ?? false);
            $parity = $newAllowed === $legacyAllowed;

            self::emit('conversation_reply_gate_compare', [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'conversation_id' => $conversationId,
                'gate_point' => $gatePoint,
                'legacy_status' => $legacyStatus,
                'legacy_allowed' => $legacyAllowed,
                'new_owner' => $newOwner,
                'new_allowed' => $newAllowed,
                'parity' => $parity,
                'compare_mode' => true,
            ], $logger);

            return [
                'executed' => true,
                'reason' => 'compared',
                'gate_point' => $gatePoint,
                'legacy_allowed' => $legacyAllowed,
                'new_owner' => $newOwner,
                'new_allowed' => $newAllowed,
                'parity' => $parity,
            ];
        } catch (\Throwable $e) {
            self::emit('conversation_reply_gate_compare_error', [
                'trace_id' => (string) ($params['trace_id'] ?? ''),
                'gate_point' => (string) ($params['gate_point'] ?? ''),
                'message' => $e->getMessage(),
            ], $logger);

            return ['executed' => false, 'reason' => 'exception'];
        }
    }

    /**
     * Emit a compare log line. Defensive: logging failures must never bubble out
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
            // Swallow: a compare log failure must not affect the main flow.
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
