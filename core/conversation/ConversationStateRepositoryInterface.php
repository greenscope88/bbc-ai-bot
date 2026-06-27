<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationState.php';

/**
 * Phase 2-B Step 2-A — Conversation State Repository contract.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §5（Conversation State Runtime）.
 *
 * 持久化 / 讀取 Conversation State 的抽象介面，使儲存實作
 * （in-memory / JSON file / 未來 DB）可替換，符合 Additive Implementation
 * 與 Tenant-independent 原則。
 */
interface ConversationStateRepositoryInterface
{
    /**
     * 依 conversationId 取出狀態；不存在時回傳 null。
     */
    public function find(string $conversationId): ?ConversationState;

    /**
     * 儲存（新增或覆寫）狀態。
     */
    public function save(ConversationState $state): void;

    /**
     * 刪除狀態；不存在時為 no-op。
     */
    public function delete(string $conversationId): void;
}
