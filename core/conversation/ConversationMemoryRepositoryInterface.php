<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'CustomerMemoryCard.php';

/**
 * Phase 2-B Step 1 — Conversation Memory Repository contract.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_MEMORY.md.
 *
 * 持久化 / 讀取 Customer Memory Card 的抽象介面。Conversation Memory Runtime
 * 透過本介面存取記憶卡，使儲存實作（in-memory / JSON file / 未來 DB）可替換，
 * 符合 Additive Implementation 與 Tenant-independent 原則。
 */
interface ConversationMemoryRepositoryInterface
{
    /**
     * 依 conversationId 取出記憶卡；不存在時回傳 null。
     */
    public function find(string $conversationId): ?CustomerMemoryCard;

    /**
     * 儲存（新增或覆寫）記憶卡。
     */
    public function save(CustomerMemoryCard $card): void;

    /**
     * 刪除記憶卡；不存在時為 no-op。
     */
    public function delete(string $conversationId): void;
}
