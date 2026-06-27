<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationPolicy.php';

/**
 * Phase 2-B Step 3 — Recommendation Eligibility Evaluator（CA-012）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §12.5；
 *       對齊 BATS_AI_PERSONA.md Golden Rule #3「Complete Naturally, Never Force Marketing」。
 *
 * **Conversation Completed 不代表一定推薦。** 推薦資格須同時滿足三維度：
 *   1. Intent Type        — 本次意圖與推薦類型相容
 *   2. Conversation Completion — 客戶當前需求已先完成
 *   3. Tenant Policy      — 租戶已啟用該推薦類型
 *
 * 本層只做政策判定，不組句、不查 Runtime、不發送任何推薦。
 *
 * Intent Type 以字串表示（與 KnowledgeIntentDetector 之值一致），本檔不依賴
 * 該類別以維持鬆耦合（No Bridge / No Legacy Mapping）。
 */
final class RecommendationEligibilityEvaluator
{
    public const INTENT_PRODUCT_SEARCH = 'product_search';
    public const INTENT_KNOWLEDGE_QUERY = 'knowledge_query';
    public const INTENT_AMBIGUOUS = 'ambiguous';

    /**
     * Intent → 相容之推薦類型白名單。
     *
     * - product_search：完成後可推薦商品 / 活動 / 優惠 / 評論邀請。
     * - knowledge_query：純知識問題「未必適合推薦」（SSOT §12.5）；僅允許評論邀請
     *   （服務性收尾），不主動推銷商品 / 活動 / 優惠。
     * - ambiguous：意圖不明，必須先 Clarification，不具任何推薦資格。
     *
     * @var array<string, list<string>>
     */
    private const INTENT_COMPATIBILITY = [
        self::INTENT_PRODUCT_SEARCH => [
            ConversationPolicy::RECOMMENDATION_PRODUCT,
            ConversationPolicy::RECOMMENDATION_CAMPAIGN,
            ConversationPolicy::RECOMMENDATION_COUPON,
            ConversationPolicy::RECOMMENDATION_REVIEW_INVITE,
        ],
        self::INTENT_KNOWLEDGE_QUERY => [
            ConversationPolicy::RECOMMENDATION_REVIEW_INVITE,
        ],
        self::INTENT_AMBIGUOUS => [],
    ];

    /**
     * 單一推薦類型之資格判定。
     *
     * @return array{eligible: bool, reason: string, recommendation_type: string}
     */
    public function evaluate(
        string $intentType,
        bool $conversationCompleted,
        ConversationPolicy $tenantPolicy,
        string $recommendationType
    ): array {
        ConversationPolicy::assertValidType($recommendationType);

        // 維度 2：先完成需求，再判斷推薦。
        if (!$conversationCompleted) {
            return self::deny($recommendationType, 'requirement_not_completed');
        }

        // 維度 1：Intent 與推薦類型相容性。
        $compatible = self::INTENT_COMPATIBILITY[$intentType] ?? [];
        if (!in_array($recommendationType, $compatible, true)) {
            return self::deny($recommendationType, 'intent_incompatible');
        }

        // 維度 3：Tenant Policy。
        if (!$tenantPolicy->isRecommendationEnabled($recommendationType)) {
            return self::deny($recommendationType, 'tenant_policy_disabled');
        }

        return [
            'eligible' => true,
            'reason' => '',
            'recommendation_type' => $recommendationType,
        ];
    }

    /**
     * 一次評估所有推薦類型。
     *
     * @return array<string, array{eligible: bool, reason: string, recommendation_type: string}>
     */
    public function evaluateAll(
        string $intentType,
        bool $conversationCompleted,
        ConversationPolicy $tenantPolicy
    ): array {
        $result = [];
        foreach (ConversationPolicy::allTypes() as $type) {
            $result[$type] = $this->evaluate($intentType, $conversationCompleted, $tenantPolicy, $type);
        }

        return $result;
    }

    /**
     * 是否至少有一種推薦類型符合資格。
     */
    public function hasAnyEligible(
        string $intentType,
        bool $conversationCompleted,
        ConversationPolicy $tenantPolicy
    ): bool {
        foreach ($this->evaluateAll($intentType, $conversationCompleted, $tenantPolicy) as $decision) {
            if ($decision['eligible'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{eligible: bool, reason: string, recommendation_type: string}
     */
    private static function deny(string $recommendationType, string $reason): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'recommendation_type' => $recommendationType,
        ];
    }
}
