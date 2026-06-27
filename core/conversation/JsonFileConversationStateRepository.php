<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStateRepositoryInterface.php';

/**
 * Phase 2-B Step 2-A — JSON file backed Conversation State repository.
 *
 * 每個 conversationId 對應一個 JSON 檔，存於 runtime/conversation_state/ 下。
 * 本實作不依賴任何資料庫，符合 Additive Implementation 與最低重構原則。
 *
 * 注意：本目錄（runtime/conversation_state/）為「新增」儲存位置，與既有
 * runtime/conversation_status/（ConversationStatusResolver 使用）分離；
 * 本回合不接入、不遷移、不映射既有資料（無 Legacy Mapping）。
 */
final class JsonFileConversationStateRepository implements ConversationStateRepositoryInterface
{
    private string $storeDirectory;

    public function __construct(?string $storeDirectory = null)
    {
        $this->storeDirectory = $storeDirectory ?? self::defaultStoreDirectory();
    }

    public static function defaultStoreDirectory(): string
    {
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'conversation_state';
    }

    public function find(string $conversationId): ?ConversationState
    {
        $path = $this->stateFilePath($conversationId);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return ConversationState::fromArray($decoded);
    }

    public function save(ConversationState $state): void
    {
        if (!is_dir($this->storeDirectory)) {
            mkdir($this->storeDirectory, 0775, true);
        }

        file_put_contents(
            $this->stateFilePath($state->getConversationId()),
            json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public function delete(string $conversationId): void
    {
        $path = $this->stateFilePath($conversationId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function stateFilePath(string $conversationId): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9._-]+/', '_', trim($conversationId)) ?? 'unknown';
        if ($safeId === '') {
            $safeId = 'unknown';
        }

        return $this->storeDirectory . DIRECTORY_SEPARATOR . $safeId . '.json';
    }
}
