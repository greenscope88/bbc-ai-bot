<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'event'
    . DIRECTORY_SEPARATOR . 'ConversationEventInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'event'
    . DIRECTORY_SEPARATOR . 'ConversationEventType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'event'
    . DIRECTORY_SEPARATOR . 'ConversationEventNormalizer.php';

/**
 * Phase 2-B Step 4-D-1 — Conversation Event Adapter.
 *
 * SSOT: Step 4-D Human Service Runtime Architecture Review；
 *       Conversation Runtime Foundation（Facade 為唯一 Runtime Entry）。
 *
 * 唯一職責：作為未來所有 Conversation Event 的**統一 Adapter 入口**
 * （LINE / CRM / Web Chat / Telegram / Facebook / APP / Email…），將外部
 * 世界與 Conversation Runtime 隔離。
 *
 * 目標 Pipeline（未來 Step 4-D-2+）：
 *   External Channel
 *     → [Future Channel Parser]（本回合不存在）
 *     → ConversationEventNormalizer（canonical descriptor → Domain Event）
 *     → ConversationEventAdapter（本元件）
 *     → ConversationRuntimeFacade（唯一 Runtime Entry；本回合不接入）
 *
 * 嚴格邊界（Step 4-D-1 Scope）：
 *   - **Architecture only**：accept / acceptDescriptor 只驗證並回傳結構化
 *     envelope；**dispatched_to_runtime 恆為 false**。
 *   - **不接入** webhook / saas_router / Production Working Path。
 *   - **不修改、不呼叫** ConversationRuntimeFacade / State / Memory / Policy。
 *   - Runtime 不知道 LINE；Adapter 不知道 Gemini。
 *   - Owner First：本元件**絕不**直接修改 Owner。
 *
 * HumanAgentMessage 未來整合（僅 Architecture 保留，本回合不接線）：
 *   HumanAgentMessageEvent
 *     → Facade::handleHumanAgentMessage()
 *        → State Runtime（Owner=HUMAN）+ Memory 同步
 */
final class ConversationEventAdapter
{
    private ConversationEventNormalizer $normalizer;

    /** @var array<string, true> 架構用 idempotency 登記（測試 / 未來 dedup）。 */
    private array $seenIdempotencyKeys = [];

    public function __construct(?ConversationEventNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new ConversationEventNormalizer();
    }

    /**
     * 接受一則已建立的 Domain Event（本回合：只驗證 + envelope，不 dispatch）。
     *
     * @return array{
     *   accepted: bool,
     *   dispatched_to_runtime: bool,
     *   duplicate: bool,
     *   reason: string,
     *   event: array<string, mixed>
     * }
     */
    public function accept(ConversationEventInterface $event, ?string $idempotencyKey = null): array
    {
        if ($this->isDuplicate($idempotencyKey)) {
            return $this->envelope($event, true, 'duplicate_idempotency_key');
        }

        $this->rememberIdempotency($idempotencyKey);

        return $this->envelope($event, false, 'architecture_only_no_runtime_dispatch');
    }

    /**
     * 自 canonical descriptor 正規化並接受（本回合：不解析任何外部格式）。
     *
     * Descriptor 可選帶 idempotency_key（Adapter 層，不進 Domain Event payload）。
     *
     * @param array<string, mixed> $descriptor
     * @return array{
     *   accepted: bool,
     *   dispatched_to_runtime: bool,
     *   duplicate: bool,
     *   reason: string,
     *   event: array<string, mixed>
     * }
     */
    public function acceptDescriptor(array $descriptor): array
    {
        $idempotencyKey = isset($descriptor['idempotency_key'])
            ? trim((string) $descriptor['idempotency_key'])
            : null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        $event = $this->normalizer->normalize($descriptor);

        return $this->accept($event, $idempotencyKey);
    }

    /**
     * Phase 2-B Step 2-B-2 — additive dispatch capability.
     *
     * 將「已建立之 Domain Event」dispatch 進 Conversation Runtime（透過 Facade）：
     *   - customer_message     → ConversationRuntimeFacade::handleCustomerMessage()
     *   - human_agent_message  → 預留（本步不主動產生，亦不由 parsed event 翻轉 Owner）
     *   - system               → 不 dispatch
     * 重複 idempotency_key 不重複 dispatch。
     *
     * 嚴格邊界：**不改變** accept() / acceptDescriptor() 既有語意；呼叫端需明確
     * 在 feature flag 控制下 opt-in。Adapter 仍不知道 LINE / Gemini。
     *
     * @param array<string, mixed> $facts 供 Facade 消費之 grounded 事實（可空）
     * @return array{
     *   accepted: bool,
     *   dispatched_to_runtime: bool,
     *   duplicate: bool,
     *   reason: string,
     *   event: array<string, mixed>,
     *   runtime_result: array<string, mixed>|null
     * }
     */
    public function dispatch(
        ConversationEventInterface $event,
        ConversationRuntimeFacade $facade,
        ?string $idempotencyKey = null,
        array $facts = [],
        ?\DateTimeImmutable $now = null
    ): array {
        if ($this->isDuplicate($idempotencyKey)) {
            return $this->dispatchEnvelope($event, false, true, 'duplicate_idempotency_key', null);
        }

        $this->rememberIdempotency($idempotencyKey);

        switch ($event->getType()) {
            case ConversationEventType::CUSTOMER_MESSAGE:
                $runtimeResult = $facade->handleCustomerMessage($event->getConversationId(), $facts, $now);

                return $this->dispatchEnvelope($event, true, false, 'dispatched_customer_message', $runtimeResult);
            case ConversationEventType::HUMAN_AGENT_MESSAGE:
                // Phase 2-C Step 2-C-2: Human Takeover (CA-005). The Facade delegates
                // Owner transfer to ConversationStateRuntime (Owner First); the Adapter
                // never sets Owner itself. Caller opts in via the human dispatch flag.
                $runtimeResult = $facade->handleHumanAgentMessage($event->getConversationId(), $now);

                return $this->dispatchEnvelope($event, true, false, 'dispatched_human_agent_message', $runtimeResult);
            case ConversationEventType::SYSTEM:
                return $this->dispatchEnvelope($event, false, false, 'system_event_not_dispatched', null);
            default:
                return $this->dispatchEnvelope($event, false, false, 'unsupported_type_not_dispatched', null);
        }
    }

    /**
     * 自 canonical descriptor 正規化後 dispatch（Phase 2-B Step 2-B-2）。
     *
     * @param array<string, mixed> $descriptor
     * @param array<string, mixed> $facts
     * @return array{
     *   accepted: bool,
     *   dispatched_to_runtime: bool,
     *   duplicate: bool,
     *   reason: string,
     *   event: array<string, mixed>,
     *   runtime_result: array<string, mixed>|null
     * }
     */
    public function dispatchDescriptor(
        array $descriptor,
        ConversationRuntimeFacade $facade,
        array $facts = [],
        ?\DateTimeImmutable $now = null
    ): array {
        $idempotencyKey = isset($descriptor['idempotency_key'])
            ? trim((string) $descriptor['idempotency_key'])
            : null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        $event = $this->normalizer->normalize($descriptor);

        return $this->dispatch($event, $facade, $idempotencyKey, $facts, $now);
    }

    /**
     * 測試用：清空 idempotency 登記。
     */
    public function resetIdempotency(): void
    {
        $this->seenIdempotencyKeys = [];
    }

    /**
     * @return array{
     *   accepted: bool,
     *   dispatched_to_runtime: bool,
     *   duplicate: bool,
     *   reason: string,
     *   event: array<string, mixed>
     * }
     */
    private function envelope(ConversationEventInterface $event, bool $duplicate, string $reason): array
    {
        return [
            'accepted' => true,
            'dispatched_to_runtime' => false,
            'duplicate' => $duplicate,
            'reason' => $reason,
            'event' => $event->toArray(),
        ];
    }

    /**
     * @param array<string, mixed>|null $runtimeResult
     * @return array{
     *   accepted: bool,
     *   dispatched_to_runtime: bool,
     *   duplicate: bool,
     *   reason: string,
     *   event: array<string, mixed>,
     *   runtime_result: array<string, mixed>|null
     * }
     */
    private function dispatchEnvelope(
        ConversationEventInterface $event,
        bool $dispatched,
        bool $duplicate,
        string $reason,
        ?array $runtimeResult
    ): array {
        return [
            'accepted' => true,
            'dispatched_to_runtime' => $dispatched,
            'duplicate' => $duplicate,
            'reason' => $reason,
            'event' => $event->toArray(),
            'runtime_result' => $runtimeResult,
        ];
    }

    private function isDuplicate(?string $idempotencyKey): bool
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return false;
        }

        return isset($this->seenIdempotencyKeys[$idempotencyKey]);
    }

    private function rememberIdempotency(?string $idempotencyKey): void
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return;
        }

        $this->seenIdempotencyKeys[$idempotencyKey] = true;
    }
}
