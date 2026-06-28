<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 4-D-1 — Conversation Domain Event（介面）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md（Conversation Runtime 僅接受
 *       Conversation Event）；Step 4-D Architecture Review。
 *
 * Conversation Runtime Foundation 原則：
 *   - Runtime 不知道 LINE / Webhook / Gemini。
 *   - 外部世界一律以「Conversation Domain Event」與 Runtime 溝通。
 *
 * 本介面定義所有 Domain Event 的共同契約（Channel 無關、純資料）。
 * Event 為不可變（immutable）值物件，不含任何外部解析或 Runtime 行為。
 */
interface ConversationEventInterface
{
    /** @return string 事件型別（見 ConversationEventType::*）。 */
    public function getType(): string;

    /** @return string 對話識別碼（conversationId）。 */
    public function getConversationId(): string;

    /** @return \DateTimeImmutable 事件實際發生時間（以來源 timestamp 為準）。 */
    public function getOccurredAt(): \DateTimeImmutable;

    /**
     * @return array<string, mixed> 已正規化之事件內容（Channel 無關）。
     */
    public function getPayload(): array;

    /**
     * @return array<string, mixed> 可序列化表示（log / persistence 用）。
     */
    public function toArray(): array;
}
