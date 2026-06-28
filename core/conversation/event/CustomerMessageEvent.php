<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AbstractConversationEvent.php';

/**
 * Phase 2-B Step 4-D-1 — 客戶訊息事件.
 *
 * 表示「客戶（end customer）送出一則訊息」。Channel 無關。
 * 未來整合（Step 4-D-2+）時，對應 Conversation Runtime 的客戶訊息流。
 * 本回合僅為資料載體，不接線、不觸發任何 Runtime。
 */
final class CustomerMessageEvent extends AbstractConversationEvent
{
    public function getType(): string
    {
        return ConversationEventType::CUSTOMER_MESSAGE;
    }

    /** 便捷取出訊息文字（payload['text']）。 */
    public function getText(): string
    {
        return trim((string) $this->payloadValue('text', ''));
    }
}
