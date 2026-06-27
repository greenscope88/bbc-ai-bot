<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationOwner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStatus.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationMemoryRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStateRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationPolicyRuntime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationPolicy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationReplyGate.php';

/**
 * Phase 2-B Step 4-A — Conversation Runtime Facade.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §2 Pipeline、§4/§5/§12；
 *       AI 分流服務架構圖。
 *
 * 唯一職責：作為 Conversation Memory / State / Policy 三個 Runtime 的**單一整合
 * 入口**，依 SSOT Pipeline 順序編排，並以 Owner-based ConversationReplyGate
 * 產出「AI 是否可正式回覆」之結論。
 *
 * Pipeline（本 Facade 編排範圍）：
 *   Customer Message
 *     → Conversation Memory Runtime（更新 Customer Memory Card）
 *     → Conversation State Runtime（Owner / Status / Takeover / Resume）
 *     → Conversation Policy Layer（Lifecycle / Recommendation Eligibility）
 *     → ConversationReplyGate（Owner == AI?）
 *
 * 嚴格邊界（依本回合 Scope 與 SSOT）：
 *   - **只接收已明確傳入的事實與訊號**（intent、completion signals、tenant policy、
 *     是否真人訊息…），**不自行推測**、不查外部資料、不組句。
 *   - **不接入** saas_router / webhook / Product / Knowledge Runtime / 任何
 *     Production Working Path。
 *   - **不修改** 既有 Success Scope；不加入任何相容層。
 *   - State 仍由 Conversation State Runtime 唯一管理（CA-010）；本 Facade 僅編排，
 *     不自行持有狀態。
 */
final class ConversationRuntimeFacade
{
    private ConversationMemoryRuntime $memory;

    private ConversationStateRuntime $state;

    private ConversationPolicyRuntime $policy;

    public function __construct(
        ?ConversationMemoryRuntime $memory = null,
        ?ConversationStateRuntime $state = null,
        ?ConversationPolicyRuntime $policy = null
    ) {
        $this->memory = $memory ?? new ConversationMemoryRuntime();
        $this->state = $state ?? new ConversationStateRuntime();
        $this->policy = $policy ?? new ConversationPolicyRuntime();
    }

    /**
     * 全 in-memory 組裝（測試用）。
     */
    public static function createForTesting(): self
    {
        return new self(
            ConversationMemoryRuntime::createForTesting(),
            ConversationStateRuntime::createForTesting(),
            new ConversationPolicyRuntime()
        );
    }

    public function memory(): ConversationMemoryRuntime
    {
        return $this->memory;
    }

    public function state(): ConversationStateRuntime
    {
        return $this->state;
    }

    public function policy(): ConversationPolicyRuntime
    {
        return $this->policy;
    }

    /**
     * 處理一則「客戶訊息」並回傳整合決策（不送出任何回覆）。
     *
     * 僅消費明確傳入之事實／訊號：
     *   $facts = [
     *     'memory_changes'      => array<string,mixed>  // 傳給 Memory Runtime::remember
     *     'completion_signals'  => array<string,bool>   // 傳給 Policy::detectLifecycleStage
     *     'current_stage'       => string               // 目前 Lifecycle 階段（預設 Active）
     *     'intent_type'         => string               // 供 Recommendation Eligibility
     *     'tenant_policy'       => ConversationPolicy    // 供 Recommendation Eligibility
     *   ]
     *
     * @param array<string, mixed> $facts
     * @return array{
     *   conversation_id: string,
     *   owner: string,
     *   ai_resume_applied: bool,
     *   lifecycle_stage: string,
     *   requirement_completed: bool,
     *   recommendation: array<string, array{eligible: bool, reason: string, recommendation_type: string}>,
     *   reply_gate: array{allowed: bool, owner: string, block_reason: string}
     * }
     */
    public function handleCustomerMessage(
        string $conversationId,
        array $facts = [],
        ?\DateTimeImmutable $now = null
    ): array {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

        // ① State：記錄客戶訊息時間（AI Resume「Customer New Message」條件用）。
        $this->state->recordCustomerMessage($conversationId, $now);

        // ② State：AI Resume 三條件評估，成立則將 Owner 轉回 AI。
        $resumeApplied = $this->state->resumeToAi($conversationId, $now);

        // ③ 解析有效 Owner（CA-003）。
        $owner = $this->state->resolveEffectiveOwner($conversationId, $now);

        // ④ Memory：套用本輪 grounded 變更，並同步 Owner 鏡像至記憶卡。
        $memoryChanges = self::asArray($facts['memory_changes'] ?? []);
        $memoryChanges['human_handoff_status'] = $owner;
        $this->memory->remember($conversationId, $memoryChanges, $now);

        // ⑤ Policy：Completion Detection → Lifecycle 階段。
        $currentStage = isset($facts['current_stage']) && trim((string) $facts['current_stage']) !== ''
            ? (string) $facts['current_stage']
            : ConversationLifecycle::STAGE_ACTIVE;
        $completionSignals = self::asArray($facts['completion_signals'] ?? []);
        $lifecycleStage = $this->policy->detectLifecycleStage($currentStage, $completionSignals);
        $requirementCompleted = $this->policy->isRequirementCompleted($lifecycleStage);

        // ⑥ Policy：Recommendation Eligibility（CA-012），僅在提供 intent + tenant policy 時評估。
        $recommendation = [];
        $intentType = isset($facts['intent_type']) ? (string) $facts['intent_type'] : '';
        $tenantPolicy = $facts['tenant_policy'] ?? null;
        if ($intentType !== '' && $tenantPolicy instanceof ConversationPolicy) {
            $recommendation = $this->policy->evaluateRecommendationForStage(
                $intentType,
                $lifecycleStage,
                $tenantPolicy
            );
        }

        // ⑦ Reply Gate：Owner-based 判定 AI 是否可正式回覆。
        $replyGate = ConversationReplyGate::evaluate($owner);

        return [
            'conversation_id' => $conversationId,
            'owner' => $owner,
            'ai_resume_applied' => $resumeApplied,
            'lifecycle_stage' => $lifecycleStage,
            'requirement_completed' => $requirementCompleted,
            'recommendation' => $recommendation,
            'reply_gate' => $replyGate,
        ];
    }

    /**
     * 處理一則「真人客服訊息」：Human Takeover（CA-005）。
     * 委派 State Runtime 轉 Owner=HUMAN 並更新 last_human_message_at，同步記憶卡鏡像。
     *
     * @return array{owner: string, reply_gate: array{allowed: bool, owner: string, block_reason: string}}
     */
    public function handleHumanAgentMessage(
        string $conversationId,
        ?\DateTimeImmutable $now = null
    ): array {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

        $this->state->recordHumanAgentMessage($conversationId, $now);
        $owner = $this->state->resolveEffectiveOwner($conversationId, $now);
        $this->memory->syncConversationOwner($conversationId, $owner, $now);

        return [
            'owner' => $owner,
            'reply_gate' => ConversationReplyGate::evaluate($owner),
        ];
    }

    /**
     * 便捷查詢：目前是否允許 AI 正式回覆（Owner-based）。
     */
    public function mayAiReply(string $conversationId, ?\DateTimeImmutable $now = null): bool
    {
        $owner = $this->state->resolveEffectiveOwner($conversationId, $now);

        return ConversationReplyGate::mayAiReply($owner);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function asArray($value): array
    {
        return is_array($value) ? $value : [];
    }
}
