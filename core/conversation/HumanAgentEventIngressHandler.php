<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventIngestProbe.php';

/**
 * Phase 2-C Step 2-C-3 — Backend Human Event Webhook Ingress Handler.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md CA-005（Automatic Human
 *       Takeover）；Phase 2-C Human Service Runtime Plan；Backend Human Event
 *       Contract（Freeze）。
 *
 * 唯一職責：作為 Backend / CRM Human Event Webhook 的**純資料層** ingress：
 * 解析 HTTP 請求 → 認證 → 驗證 → 沿用既有 Human Service Runtime 路徑：
 *
 *   Backend Human Event
 *     → BackendHumanConversationChannelParser
 *     → ConversationEventIngestProbe::runHuman()
 *     → ConversationEventAdapter
 *     → ConversationRuntimeFacade::handleHumanAgentMessage()
 *
 * 嚴格邊界（Additive Only / Protect Before Extend）：
 *   - **never-throw**：所有路徑 try/catch；失敗一律回結構化 envelope，不 500-crash。
 *   - **不新增第二套路徑**：dispatch 僅透過 runHuman()（既有 Runtime 入口）。
 *   - **不主動送 LINE**：絕不呼叫 LineService / Production Reply Flow。
 *   - **不修改** callback.php / callback_core.php / LineService / FinalReplyGate /
 *     ConversationStatusResolver / Customer Event Route。
 *   - **沿用既有 Human Feature Flags**（default OFF），不新增重複 flag。
 *   - **沿用 Freeze 的 Event Contract**，不擴充欄位。
 *
 * 認證（Minimal Safe Design）：
 *   - Token 由環境變數 HUMAN_AGENT_EVENT_TOKEN 提供（不硬編 Secret，不改 .env）。
 *   - Token 未設定（expected_token 為空）→ 一律拒收（fail-closed）。
 *   - 比對使用 hash_equals（constant-time）。
 *
 * Webhook payload 契約（top-level）：
 *   { "tenant_sno": "...", <flat human event fields> }
 *   或批次：{ "tenant_sno": "...", "events": [ {human event}, ... ] }
 *   （tenant_sno 為 ingress context，flag / tenant routing 之依據。）
 */
final class HumanAgentEventIngressHandler
{
    /** 環境變數 key（不硬編 Secret；由 ops 於 .env 設定後生效）。 */
    public const ENV_TOKEN_KEY = 'HUMAN_AGENT_EVENT_TOKEN';

    /**
     * 處理一次 ingress 請求，永不 throw。
     *
     *   $request = [
     *     'method'         => string  // HTTP method
     *     'content_type'   => string  // Content-Type header
     *     'raw_body'       => string  // php://input
     *     'provided_token' => string  // 請求帶入之 token（header）
     *     'expected_token' => string  // env 設定之 token（'' = 未設定）
     *     'trace_id'       => string
     *     'config'         => ?array  // 省略時自 bats_feature.php 載入（在 runHuman 內）
     *     'now'            => ?\DateTimeImmutable
     *   ]
     *
     * @param array<string, mixed> $request
     * @param callable|null $ingest fn(array $params): array  測試可注入（預設 runHuman）
     * @param callable|null $logger fn(string, array): void   測試可注入
     * @return array{status:int, body:array<string, mixed>}
     */
    public static function handle(array $request, ?callable $ingest = null, ?callable $logger = null): array
    {
        try {
            $method = strtoupper(trim((string) ($request['method'] ?? '')));
            if ($method !== 'POST') {
                return self::fail(405, 'method_not_allowed');
            }

            $expectedToken = trim((string) ($request['expected_token'] ?? ''));
            if ($expectedToken === '') {
                // Fail-closed：未設定 token 即拒收（不硬編、不改 .env）。
                return self::fail(503, 'auth_not_configured');
            }

            $providedToken = trim((string) ($request['provided_token'] ?? ''));
            if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                return self::fail(401, 'unauthorized');
            }

            $contentType = strtolower((string) ($request['content_type'] ?? ''));
            if (stripos($contentType, 'application/json') === false) {
                return self::fail(415, 'unsupported_media_type');
            }

            $rawBody = (string) ($request['raw_body'] ?? '');
            if (trim($rawBody) === '') {
                return self::fail(400, 'empty_body');
            }

            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                return self::fail(400, 'invalid_json');
            }

            $tenantSno = isset($payload['tenant_sno']) ? trim((string) $payload['tenant_sno']) : '';
            if ($tenantSno === '') {
                return self::fail(400, 'missing_tenant_sno');
            }

            $traceId = (string) ($request['trace_id'] ?? '');
            $now = ($request['now'] ?? null) instanceof \DateTimeImmutable ? $request['now'] : null;

            $params = [
                'tenant_sno' => $tenantSno,
                'raw_envelope' => $payload,
                'trace_id' => $traceId,
            ];
            if ($now !== null) {
                $params['now'] = $now;
            }
            if (isset($request['config']) && is_array($request['config'])) {
                $params['config'] = $request['config'];
            }

            $ingest = $ingest ?? static function (array $p): array {
                return ConversationEventIngestProbe::runHuman($p);
            };
            $result = $ingest($params);
            if (!is_array($result)) {
                $result = ['executed' => false, 'reason' => 'ingest_no_result'];
            }

            $response = self::mapIngestResult($result);

            self::emit('human_agent_event_webhook', [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'status' => $response['status'],
                'accepted' => $response['body']['accepted'] ?? null,
                'reason' => $response['body']['reason'] ?? null,
            ], $logger);

            return $response;
        } catch (\Throwable $e) {
            self::emit('human_agent_event_webhook_error', [
                'trace_id' => (string) ($request['trace_id'] ?? ''),
                'message' => $e->getMessage(),
            ], $logger);

            return self::fail(500, 'exception');
        }
    }

    /**
     * 將 runHuman 結果映射為 Webhook Response 契約。
     *
     * @param array<string, mixed> $result
     * @return array{status:int, body:array<string, mixed>}
     */
    private static function mapIngestResult(array $result): array
    {
        $executed = (bool) ($result['executed'] ?? false);

        if (!$executed) {
            $reason = (string) ($result['reason'] ?? 'not_executed');
            if ($reason === 'exception' || $reason === 'ingest_no_result') {
                return self::fail(500, $reason);
            }

            // Flag OFF / tenant 未命中 / 無可解析事件 → accepted:false（非錯誤）。
            return self::skipped($reason);
        }

        $parsed = (int) ($result['parsed'] ?? 0);
        $dispatched = (int) ($result['dispatched'] ?? 0);
        $dispatchEnabled = (bool) ($result['dispatch_enabled'] ?? false);
        $anyDuplicate = self::anyDuplicate($result['results'] ?? null);

        if ($parsed === 0) {
            return self::skipped('no_parsable_events');
        }
        if ($dispatched >= 1) {
            return self::accepted(false);
        }
        if ($anyDuplicate) {
            return self::accepted(true);
        }
        if (!$dispatchEnabled) {
            return self::skipped('dispatch_disabled');
        }

        return self::skipped('not_dispatched');
    }

    /**
     * @param mixed $results
     */
    private static function anyDuplicate($results): bool
    {
        if (!is_array($results)) {
            return false;
        }
        foreach ($results as $row) {
            if (is_array($row) && !empty($row['duplicate'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    private static function accepted(bool $duplicate): array
    {
        $body = ['ok' => true, 'accepted' => true];
        if ($duplicate) {
            $body['duplicate'] = true;
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    private static function skipped(string $reason): array
    {
        return ['status' => 200, 'body' => ['ok' => true, 'accepted' => false, 'reason' => $reason]];
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    private static function fail(int $status, string $reason): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'reason' => $reason]];
    }

    /**
     * Defensive emit：log 失敗不得破壞 never-throw。
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
            // swallow
        }
    }
}
