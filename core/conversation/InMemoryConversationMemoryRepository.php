<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'CustomerMemoryCard.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationMemoryRepositoryInterface.php';

/**
 * Phase 2-B Step 1 — in-memory Customer Memory Card repository (tests / MVP).
 *
 * 以 array 模擬持久化邊界：存入時序列化為陣列、取出時重建卡片，避免外部
 * 直接持有並改動內部狀態（reference aliasing）。
 */
final class InMemoryConversationMemoryRepository implements ConversationMemoryRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $store = [];

    public function find(string $conversationId): ?CustomerMemoryCard
    {
        $conversationId = trim($conversationId);
        if (!isset($this->store[$conversationId])) {
            return null;
        }

        return CustomerMemoryCard::fromArray($this->store[$conversationId]);
    }

    public function save(CustomerMemoryCard $card): void
    {
        $this->store[$card->getConversationId()] = $card->toArray();
    }

    public function delete(string $conversationId): void
    {
        unset($this->store[trim($conversationId)]);
    }
}
