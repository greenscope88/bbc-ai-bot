<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationLifecycle.php';

/**
 * Phase 2-B Step 3 — Conversation Completion Policy（Completion Detection）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §12.2.
 *
 * 唯一職責：偵測對話應處於哪個 Lifecycle 階段（Active → Resolved → Closing →
 * Completed → Closed）。訊號來源（State / Memory / Intent）由呼叫端彙整為
 * 布林訊號傳入；本層只做「政策判定」，不查 Runtime、不管 State、不組句。
 *
 * 訊號 → 階段 之優先序（高 → 低）：
 *   1. customerEndedConversation 或 timedOutNoInteraction → Closed
 *   2. serviceAcknowledged（收尾完成 / 客戶確認服務達成）      → Completed
 *   3. closingInitiated 且 requirementResolved              → Closing
 *   4. requirementResolved                                  → Resolved
 *   5. 其餘（服務進行中）                                     → Active
 *
 * 終態保護：若 currentStage 已為終態（Completed / Closed），維持不變。
 * 不回退保護：偵測結果不得早於 currentStage（除非進入終態 Closed）。
 */
final class ConversationCompletionPolicy
{
    /**
     * @param array<string, bool> $signals 可含：
     *   - requirement_resolved
     *   - closing_initiated
     *   - service_acknowledged
     *   - customer_ended_conversation
     *   - timed_out_no_interaction
     * @return string Lifecycle 階段（ConversationLifecycle::STAGE_*）
     */
    public function detectStage(string $currentStage, array $signals): string
    {
        ConversationLifecycle::assertValid($currentStage);

        // 終態保護：已收尾的對話不再變動。
        if (ConversationLifecycle::isTerminal($currentStage)) {
            return $currentStage;
        }

        $requirementResolved = (bool) ($signals['requirement_resolved'] ?? false);
        $closingInitiated = (bool) ($signals['closing_initiated'] ?? false);
        $serviceAcknowledged = (bool) ($signals['service_acknowledged'] ?? false);
        $customerEnded = (bool) ($signals['customer_ended_conversation'] ?? false);
        $timedOut = (bool) ($signals['timed_out_no_interaction'] ?? false);

        if ($customerEnded || $timedOut) {
            return ConversationLifecycle::STAGE_CLOSED;
        }

        if ($serviceAcknowledged) {
            return ConversationLifecycle::STAGE_COMPLETED;
        }

        if ($closingInitiated && $requirementResolved) {
            return ConversationLifecycle::STAGE_CLOSING;
        }

        if ($requirementResolved) {
            return ConversationLifecycle::STAGE_RESOLVED;
        }

        // 服務進行中：New 視為已開始，收斂為 Active。
        $detected = ConversationLifecycle::STAGE_ACTIVE;

        // 不回退保護：若偵測結果早於目前階段，維持目前階段。
        if (ConversationLifecycle::order($detected) < ConversationLifecycle::order($currentStage)) {
            return $currentStage;
        }

        return $detected;
    }

    /**
     * 是否偵測為「客戶當前需求已完成」（Resolved 以後皆屬已完成需求）。
     * 供 Recommendation Eligibility 之「Conversation Completion」維度使用。
     */
    public function isRequirementCompleted(string $stage): bool
    {
        ConversationLifecycle::assertValid($stage);

        return ConversationLifecycle::order($stage)
            >= ConversationLifecycle::order(ConversationLifecycle::STAGE_RESOLVED);
    }
}
