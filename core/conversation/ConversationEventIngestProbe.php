<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventAdapter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'event'
    . DIRECTORY_SEPARATOR . 'ConversationChannelParserInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'event'
    . DIRECTORY_SEPARATOR . 'LineConversationChannelParser.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'event'
    . DIRECTORY_SEPARATOR . 'BackendHumanConversationChannelParser.php';

/**
 * Phase 2-B Step 2-B-2 — Conversation Event Ingest Probe.
 *
 * SSOT: Step 4-D-2 Event Source Integration Review；Conversation Runtime
 *       Foundation（Facade 為唯一 Runtime Entry，Runtime 不知道 LINE）。
 *
 * 唯一職責：作為 saas_router pilot 內的**安全接點**，把某通路原始事件依下列
 * pipeline 接入 Conversation Runtime，並全程受 feature flag 控制：
 *
 *   raw envelope
 *     → ConversationChannelParser（CEP：channel-aware → canonical descriptor）
 *     → [Parse flag] 到此為止＝Parse-only（只記 log，不 dispatch）
 *     → ConversationEventAdapter（normalize + idempotency + dispatch）
 *     → [Dispatch flag] ConversationRuntimeFacade（寫 runtime state/memory）
 *
 * 嚴格邊界（Step 2-B-2 Scope）：
 *   - **永不改變實際回覆**：不產生 reply text、不呼叫 LineService、不影響
 *     final_route / transport / Product / Knowledge Runtime。
 *   - **永不 throw**：全程 try/catch；emit() 亦防禦化。
 *   - **flag default OFF**：parse flag 關閉或 tenant 未命中即短路。
 *   - **dispatch 依賴 parse**：dispatch 僅在 parse 已通過後才評估。
 *   - **不觸碰 legacy**：不寫 runtime/conversation_status，不改
 *     ConversationStatusResolver / FinalReplyGate / webhook。
 *   - 不含任何 Compat Shim / Alias / Bridge / Fallback / Legacy Mapping。
 *   - 本步**不**完成 Human Takeover：human_agent_message 僅預留，parsed event
 *     不翻轉 Owner（Owner 維持 AI）。
 */
final class ConversationEventIngestProbe
{
    public const FLAG_PARSE_ENABLED = 'conversation_event_parse_enabled';
    public const FLAG_PARSE_TENANTS = 'conversation_event_parse_tenant_snos';
    public const FLAG_DISPATCH_ENABLED = 'conversation_event_dispatch_enabled';
    public const FLAG_DISPATCH_TENANTS = 'conversation_event_dispatch_tenant_snos';

    // Phase 2-C Step 2-C-2: Human Service Runtime flags (independent of customer flags).
    public const FLAG_HUMAN_PARSE_ENABLED = 'conversation_human_event_parse_enabled';
    public const FLAG_HUMAN_PARSE_TENANTS = 'conversation_human_event_parse_tenant_snos';
    public const FLAG_HUMAN_DISPATCH_ENABLED = 'conversation_human_event_dispatch_enabled';
    public const FLAG_HUMAN_DISPATCH_TENANTS = 'conversation_human_event_dispatch_tenant_snos';

    public const CHANNEL_LINE = 'line';
    public const CHANNEL_BACKEND_HUMAN = 'backend_human';

    /**
     * @param array<string, mixed> $config
     */
    public static function isParseEnabled(array $config, string $tenantSno): bool
    {
        return self::flagOnForTenant($config, self::FLAG_PARSE_ENABLED, self::FLAG_PARSE_TENANTS, $tenantSno);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function isDispatchEnabled(array $config, string $tenantSno): bool
    {
        return self::flagOnForTenant($config, self::FLAG_DISPATCH_ENABLED, self::FLAG_DISPATCH_TENANTS, $tenantSno);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function isHumanParseEnabled(array $config, string $tenantSno): bool
    {
        return self::flagOnForTenant($config, self::FLAG_HUMAN_PARSE_ENABLED, self::FLAG_HUMAN_PARSE_TENANTS, $tenantSno);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function isHumanDispatchEnabled(array $config, string $tenantSno): bool
    {
        return self::flagOnForTenant($config, self::FLAG_HUMAN_DISPATCH_ENABLED, self::FLAG_HUMAN_DISPATCH_TENANTS, $tenantSno);
    }

    /**
     * 執行一次 parse（+ 視 flag 而定的 dispatch），永不 throw。
     *
     *   $params = [
     *     'tenant_sno'   => string
     *     'raw_envelope' => array   // 通路原始事件（如 LINE webhook body）
     *     'channel'      => string  // 預設 'line'
     *     'trace_id'     => string
     *     'now'          => ?\DateTimeImmutable
     *     'config'       => ?array  // 省略時自 config/bats_feature.php 載入
     *   ]
     *
     * @param array<string, mixed>               $params
     * @param ConversationChannelParserInterface|null $parser  測試可注入
     * @param ConversationEventAdapter|null      $adapter      測試可注入
     * @param ConversationRuntimeFacade|null     $facade       測試可注入（dispatch 用）
     * @param callable|null                      $logger       fn(string, array): void（測試用）
     * @return array{
     *   executed: bool,
     *   reason: string,
     *   parse_enabled?: bool,
     *   dispatch_enabled?: bool,
     *   parsed?: int,
     *   dispatched?: int,
     *   results?: list<array<string, mixed>>
     * }
     */
    public static function run(
        array $params,
        ?ConversationChannelParserInterface $parser = null,
        ?ConversationEventAdapter $adapter = null,
        ?ConversationRuntimeFacade $facade = null,
        ?callable $logger = null
    ): array {
        try {
            $config = isset($params['config']) && is_array($params['config'])
                ? $params['config']
                : self::loadDefaultConfig();

            $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
            if (!self::isParseEnabled($config, $tenantSno)) {
                return ['executed' => false, 'reason' => 'parse_disabled_or_tenant_unmatched'];
            }

            $rawEnvelope = (isset($params['raw_envelope']) && is_array($params['raw_envelope']))
                ? $params['raw_envelope']
                : [];
            if ($rawEnvelope === []) {
                return ['executed' => false, 'reason' => 'missing_raw_envelope'];
            }

            $channel = trim((string) ($params['channel'] ?? self::CHANNEL_LINE));
            if ($channel === '') {
                $channel = self::CHANNEL_LINE;
            }
            $traceId = (string) ($params['trace_id'] ?? '');
            $now = ($params['now'] ?? null) instanceof \DateTimeImmutable
                ? $params['now']
                : new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

            $parser = $parser ?? self::resolveParser($channel);
            if ($parser === null) {
                return ['executed' => false, 'reason' => 'unsupported_channel'];
            }

            $descriptors = $parser->parse($rawEnvelope, [
                'tenant_sno' => $tenantSno,
                'trace_id' => $traceId,
            ]);

            $dispatchEnabled = self::isDispatchEnabled($config, $tenantSno);
            $adapter = $adapter ?? new ConversationEventAdapter();
            if ($dispatchEnabled && $facade === null) {
                $facade = new ConversationRuntimeFacade();
            }

            $parsed = 0;
            $dispatched = 0;
            $results = [];
            foreach ($descriptors as $descriptor) {
                if (!is_array($descriptor)) {
                    continue;
                }
                ++$parsed;

                if ($dispatchEnabled && $facade !== null) {
                    $envelope = $adapter->dispatchDescriptor($descriptor, $facade, [], $now);
                } else {
                    $envelope = $adapter->acceptDescriptor($descriptor);
                }

                if (!empty($envelope['dispatched_to_runtime'])) {
                    ++$dispatched;
                }

                $results[] = [
                    'type' => (string) ($descriptor['type'] ?? ''),
                    'conversation_id' => (string) ($descriptor['conversation_id'] ?? ''),
                    'duplicate' => (bool) ($envelope['duplicate'] ?? false),
                    'dispatched_to_runtime' => (bool) ($envelope['dispatched_to_runtime'] ?? false),
                    'reason' => (string) ($envelope['reason'] ?? ''),
                ];
            }

            self::emit('conversation_event_ingest', [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'channel' => $channel,
                'parse_enabled' => true,
                'dispatch_enabled' => $dispatchEnabled,
                'parsed' => $parsed,
                'dispatched' => $dispatched,
                'results' => $results,
            ], $logger);

            return [
                'executed' => true,
                'reason' => 'ingested',
                'parse_enabled' => true,
                'dispatch_enabled' => $dispatchEnabled,
                'parsed' => $parsed,
                'dispatched' => $dispatched,
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            self::emit('conversation_event_ingest_error', [
                'trace_id' => (string) ($params['trace_id'] ?? ''),
                'message' => $e->getMessage(),
            ], $logger);

            return ['executed' => false, 'reason' => 'exception'];
        }
    }

    /**
     * Phase 2-C Step 2-C-2 — Human Service Runtime ingest（parse + optional dispatch），永不 throw.
     *
     * 與 run()（customer route）對稱但**完全獨立**：使用 human flags 與
     * BackendHumanConversationChannelParser，dispatch 走 human_agent_message →
     * ConversationRuntimeFacade::handleHumanAgentMessage()（Owner First：Owner 轉移
     * 由 State Runtime 執行）。本方法**不改變** customer route 任何行為。
     *
     *   $params = [
     *     'tenant_sno'   => string
     *     'raw_envelope' => array   // 後台 / CRM human event（扁平或 { events: [...] }）
     *     'trace_id'     => string
     *     'now'          => ?\DateTimeImmutable
     *     'config'       => ?array
     *   ]
     *
     * @param array<string, mixed>                    $params
     * @param ConversationChannelParserInterface|null $parser  測試可注入
     * @param ConversationEventAdapter|null           $adapter 測試可注入
     * @param ConversationRuntimeFacade|null          $facade  測試可注入（dispatch 用）
     * @param callable|null                           $logger  fn(string, array): void（測試用）
     * @return array{
     *   executed: bool,
     *   reason: string,
     *   parse_enabled?: bool,
     *   dispatch_enabled?: bool,
     *   parsed?: int,
     *   dispatched?: int,
     *   results?: list<array<string, mixed>>
     * }
     */
    public static function runHuman(
        array $params,
        ?ConversationChannelParserInterface $parser = null,
        ?ConversationEventAdapter $adapter = null,
        ?ConversationRuntimeFacade $facade = null,
        ?callable $logger = null
    ): array {
        try {
            $config = isset($params['config']) && is_array($params['config'])
                ? $params['config']
                : self::loadDefaultConfig();

            $tenantSno = trim((string) ($params['tenant_sno'] ?? ''));
            if (!self::isHumanParseEnabled($config, $tenantSno)) {
                return ['executed' => false, 'reason' => 'human_parse_disabled_or_tenant_unmatched'];
            }

            $rawEnvelope = (isset($params['raw_envelope']) && is_array($params['raw_envelope']))
                ? $params['raw_envelope']
                : [];
            if ($rawEnvelope === []) {
                return ['executed' => false, 'reason' => 'missing_raw_envelope'];
            }

            $traceId = (string) ($params['trace_id'] ?? '');
            $now = ($params['now'] ?? null) instanceof \DateTimeImmutable
                ? $params['now']
                : new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

            $parser = $parser ?? new BackendHumanConversationChannelParser();

            $descriptors = $parser->parse($rawEnvelope, [
                'tenant_sno' => $tenantSno,
                'trace_id' => $traceId,
            ]);

            $dispatchEnabled = self::isHumanDispatchEnabled($config, $tenantSno);
            $adapter = $adapter ?? new ConversationEventAdapter();
            if ($dispatchEnabled && $facade === null) {
                $facade = new ConversationRuntimeFacade();
            }

            $parsed = 0;
            $dispatched = 0;
            $results = [];
            foreach ($descriptors as $descriptor) {
                if (!is_array($descriptor)) {
                    continue;
                }
                ++$parsed;

                if ($dispatchEnabled && $facade !== null) {
                    $envelope = $adapter->dispatchDescriptor($descriptor, $facade, [], $now);
                } else {
                    $envelope = $adapter->acceptDescriptor($descriptor);
                }

                if (!empty($envelope['dispatched_to_runtime'])) {
                    ++$dispatched;
                }

                $results[] = [
                    'type' => (string) ($descriptor['type'] ?? ''),
                    'conversation_id' => (string) ($descriptor['conversation_id'] ?? ''),
                    'duplicate' => (bool) ($envelope['duplicate'] ?? false),
                    'dispatched_to_runtime' => (bool) ($envelope['dispatched_to_runtime'] ?? false),
                    'reason' => (string) ($envelope['reason'] ?? ''),
                ];
            }

            self::emit('conversation_human_event_ingest', [
                'trace_id' => $traceId,
                'tenant_sno' => $tenantSno,
                'channel' => self::CHANNEL_BACKEND_HUMAN,
                'parse_enabled' => true,
                'dispatch_enabled' => $dispatchEnabled,
                'parsed' => $parsed,
                'dispatched' => $dispatched,
                'results' => $results,
            ], $logger);

            return [
                'executed' => true,
                'reason' => 'ingested',
                'parse_enabled' => true,
                'dispatch_enabled' => $dispatchEnabled,
                'parsed' => $parsed,
                'dispatched' => $dispatched,
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            self::emit('conversation_human_event_ingest_error', [
                'trace_id' => (string) ($params['trace_id'] ?? ''),
                'message' => $e->getMessage(),
            ], $logger);

            return ['executed' => false, 'reason' => 'exception'];
        }
    }

    private static function resolveParser(string $channel): ?ConversationChannelParserInterface
    {
        if ($channel === self::CHANNEL_LINE) {
            return new LineConversationChannelParser();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function flagOnForTenant(
        array $config,
        string $flagKey,
        string $tenantsKey,
        string $tenantSno
    ): bool {
        $enabled = isset($config[$flagKey]) && (bool) $config[$flagKey];
        if (!$enabled) {
            return false;
        }

        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            return false;
        }

        $allow = isset($config[$tenantsKey]) && is_array($config[$tenantsKey])
            ? array_values(array_filter(array_map('strval', $config[$tenantsKey])))
            : [];

        return in_array($tenantSno, $allow, true);
    }

    /**
     * Emit an ingest log line. Defensive: logging failures must never bubble out
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
            // Swallow: an ingest log failure must not affect the main flow.
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
