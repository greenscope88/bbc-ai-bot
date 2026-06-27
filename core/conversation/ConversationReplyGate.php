<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationOwner.php';

/**
 * Phase 2-B Step 4-A — Conversation Reply Gate（Owner-based）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §7 Conversation Ownership Rule
 *       (CA-003)；AI 分流服務架構圖（Owner == AI? 閘門）。
 *
 * 唯一職責：依 Conversation Owner 判斷 AI 是否可送出正式回覆。
 *   - Owner = AI    → 允許
 *   - Owner = HUMAN → 禁止（真人接手中）
 *
 * 嚴格邊界（依本回合 Scope）：
 *   - **不修改、不依賴** FinalReplyGate（無 Bridge / Alias / Legacy Mapping）。
 *   - **不接入** saas_router / webhook / Production Working Path。
 *   - 純函式式判定：只接收已決定之 Owner，不查 Runtime、不管 State、不組句。
 *
 * 註：Conversation Maintenance Message（如 Gentle Reminder，CA-007）為 State
 * Runtime 發出之維運訊息，非 Owner 正式回覆，不受本閘門限制；本閘門只規範
 * 「AI 正式回覆」。
 */
final class ConversationReplyGate
{
    public const BLOCK_REASON_HUMAN_OWNER = 'human_owner';

    /**
     * Owner = AI 時允許 AI 正式回覆。
     */
    public static function mayAiReply(string $owner): bool
    {
        ConversationOwner::assertValid($owner);

        return $owner === ConversationOwner::AI;
    }

    /**
     * 結構化判定結果（與既有 gate 風格一致，但為獨立新元件）。
     *
     * @return array{allowed: bool, owner: string, block_reason: string}
     */
    public static function evaluate(string $owner): array
    {
        $allowed = self::mayAiReply($owner);

        return [
            'allowed' => $allowed,
            'owner' => $owner,
            'block_reason' => $allowed ? '' : self::BLOCK_REASON_HUMAN_OWNER,
        ];
    }
}
