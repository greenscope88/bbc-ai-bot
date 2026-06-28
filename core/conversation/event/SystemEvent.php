<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AbstractConversationEvent.php';

/**
 * Phase 2-B Step 4-D-1 — 系統事件.
 *
 * 表示「系統層級觸發之事件」（如 timeout、排程、內部 policy 觸發）。
 * Channel 無關。本回合僅為資料載體，不接線。
 */
final class SystemEvent extends AbstractConversationEvent
{
    public function getType(): string
    {
        return ConversationEventType::SYSTEM;
    }

    /** 便捷取出系統動作名稱（payload['action']）。 */
    public function getAction(): string
    {
        return trim((string) $this->payloadValue('action', ''));
    }
}
