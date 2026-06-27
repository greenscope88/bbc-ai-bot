<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStateRepositoryInterface.php';

/**
 * Phase 2-B Step 2-A — in-memory Conversation State repository (tests / MVP).
 *
 * 以 array 模擬持久化邊界：存入時序列化為陣列、取出時重建狀態，避免外部
 * 直接持有並改動內部狀態（reference aliasing）。
 */
final class InMemoryConversationStateRepository implements ConversationStateRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $store = [];

    public function find(string $conversationId): ?ConversationState
    {
        $conversationId = trim($conversationId);
        if (!isset($this->store[$conversationId])) {
            return null;
        }

        return ConversationState::fromArray($this->store[$conversationId]);
    }

    public function save(ConversationState $state): void
    {
        $this->store[$state->getConversationId()] = $state->toArray();
    }

    public function delete(string $conversationId): void
    {
        unset($this->store[trim($conversationId)]);
    }
}
