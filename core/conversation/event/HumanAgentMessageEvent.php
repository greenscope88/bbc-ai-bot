<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AbstractConversationEvent.php';

/**
 * Phase 2-B Step 4-D-1 — 真人客服訊息事件.
 *
 * 表示「真人客服（human agent）送出一則訊息」。Channel 無關。
 * 未來整合時對應 Conversation State Runtime 的 Human Takeover（CA-005）與
 * Conversation Memory 同步；本回合僅為資料載體，不接線、不修改 Owner。
 *
 * Owner First Principle：本事件本身不變更 Owner；Owner 只能由
 * ConversationStateRuntime 在未來整合步驟中依此事件變更。
 */
final class HumanAgentMessageEvent extends AbstractConversationEvent
{
    public function getType(): string
    {
        return ConversationEventType::HUMAN_AGENT_MESSAGE;
    }

    public function getText(): string
    {
        return trim((string) $this->payloadValue('text', ''));
    }

    public function getAgentId(): string
    {
        return trim((string) $this->payloadValue('agent_id', ''));
    }
}
