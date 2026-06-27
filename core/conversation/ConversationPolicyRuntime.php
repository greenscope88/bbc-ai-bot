<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationOwner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationLifecycle.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationPolicy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationCompletionPolicy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RecommendationEligibilityEvaluator.php';

/**
 * Phase 2-B Step 3 — Conversation Policy Runtime.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §12（Conversation Policy Layer,
 *       CA-011 / CA-012），位於 State Runtime 之後、Runtime Dispatch 之前。
 *
 * 唯一職責：對話生命週期最後階段之「政策判定」——
 *   - Completion Detection（Lifecycle 階段判定）
 *   - Recommendation Eligibility（CA-012）
 *   - Human Handoff Policy（§8 之政策視角）
 *   - AI Resume Policy（§10 之政策視角）
 *
 * 嚴格邊界（依 SSOT §12 與本回合 Scope）：
 *   - **不管理 Conversation State**（State 由 §5 State Runtime 唯一管理，CA-010）。
 *     因此本 Runtime **無自有持久化 / Repository**（SSOT 未要求 Policy Layer 持有
 *     狀態；Lifecycle 對齊 Customer Memory Card 之 Stage）。
 *   - **不直接組句**（組句由 Grounded Response Composer，§14）。
 *   - **不查詢 / 不修改** 其他 Runtime；所有輸入（Owner / Status / Stage / Intent /
 *     timer 結果）由呼叫端以參數傳入（純函式式決策）。
 *   - **Human Handoff / AI Resume 的計時與狀態轉移機制屬 State Runtime**；本層僅
 *     就「已計算之事實」做政策決策，不重複實作計時，不變更任何狀態。
 */
final class ConversationPolicyRuntime
{
    private ConversationCompletionPolicy $completionPolicy;

    private RecommendationEligibilityEvaluator $eligibilityEvaluator;

    public function __construct(
        ?ConversationCompletionPolicy $completionPolicy = null,
        ?RecommendationEligibilityEvaluator $eligibilityEvaluator = null
    ) {
        $this->completionPolicy = $completionPolicy ?? new ConversationCompletionPolicy();
        $this->eligibilityEvaluator = $eligibilityEvaluator ?? new RecommendationEligibilityEvaluator();
    }

    // --- Completion Detection（§12.2） ---------------------------------------

    /**
     * 依訊號偵測 Lifecycle 階段。
     *
     * @param array<string, bool> $signals 見 ConversationCompletionPolicy::detectStage
     */
    public function detectLifecycleStage(string $currentStage, array $signals): string
    {
        return $this->completionPolicy->detectStage($currentStage, $signals);
    }

    public function isRequirementCompleted(string $stage): bool
    {
        return $this->completionPolicy->isRequirementCompleted($stage);
    }

    // --- Recommendation Eligibility（§12.5 / CA-012） ------------------------

    /**
     * @return array{eligible: bool, reason: string, recommendation_type: string}
     */
    public function evaluateRecommendation(
        string $intentType,
        bool $conversationCompleted,
        ConversationPolicy $tenantPolicy,
        string $recommendationType
    ): array {
        return $this->eligibilityEvaluator->evaluate(
            $intentType,
            $conversationCompleted,
            $tenantPolicy,
            $recommendationType
        );
    }

    /**
     * 依 Lifecycle 階段自動帶入「需求是否完成」維度，評估所有推薦類型。
     *
     * @return array<string, array{eligible: bool, reason: string, recommendation_type: string}>
     */
    public function evaluateRecommendationForStage(
        string $intentType,
        string $lifecycleStage,
        ConversationPolicy $tenantPolicy
    ): array {
        $completed = $this->completionPolicy->isRequirementCompleted($lifecycleStage);

        return $this->eligibilityEvaluator->evaluateAll($intentType, $completed, $tenantPolicy);
    }

    // --- Human Handoff Policy（§8 政策視角） ---------------------------------

    /**
     * Human Handoff Policy：真人客服送出訊息即自動接手（CA-005）。
     * 本方法僅回報「政策結論」，不變更任何狀態（狀態轉移由 State Runtime 執行）。
     *
     * @return array{owner_should_be: string, ai_may_send_official_reply: bool, reason: string}
     */
    public function evaluateHumanHandoffPolicy(bool $humanAgentDidSendMessage): array
    {
        if ($humanAgentDidSendMessage) {
            return [
                'owner_should_be' => ConversationOwner::HUMAN,
                'ai_may_send_official_reply' => false,
                'reason' => 'human_agent_message_detected',
            ];
        }

        return [
            'owner_should_be' => ConversationOwner::AI,
            'ai_may_send_official_reply' => true,
            'reason' => 'no_human_takeover',
        ];
    }

    // --- AI Resume Policy（§10 政策視角 / CA-006） ---------------------------

    /**
     * AI Resume Policy：三條件政策判定（CA-006）。
     *
     *   1. currentOwner = HUMAN
     *   2. customerHasNewMessageAfterHuman = true（客戶於真人最後訊息後再發話）
     *   3. humanHoldExpired = true（now − last_human_message_at > 3 分鐘）
     *
     * 計時（條件 3）由 State Runtime 計算後以布林傳入；本層僅就事實做政策決策，
     * 不重複實作 3 分鐘計時、不變更 Owner。
     *
     * @return array{resume_permitted: bool, reason: string}
     */
    public function evaluateAiResumePolicy(
        string $currentOwner,
        bool $customerHasNewMessageAfterHuman,
        bool $humanHoldExpired
    ): array {
        ConversationOwner::assertValid($currentOwner);

        if ($currentOwner !== ConversationOwner::HUMAN) {
            return ['resume_permitted' => false, 'reason' => 'owner_not_human'];
        }
        if (!$customerHasNewMessageAfterHuman) {
            return ['resume_permitted' => false, 'reason' => 'no_customer_new_message'];
        }
        if (!$humanHoldExpired) {
            return ['resume_permitted' => false, 'reason' => 'within_human_hold_window'];
        }

        return ['resume_permitted' => true, 'reason' => 'all_conditions_met'];
    }
}
