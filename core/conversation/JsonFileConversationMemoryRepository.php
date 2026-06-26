<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'CustomerMemoryCard.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationMemoryRepositoryInterface.php';

/**
 * Phase 2-B Step 1 — JSON file backed Customer Memory Card repository.
 *
 * 與 ConversationStatusResolver 的儲存風格一致：每個 conversationId 對應一個
 * JSON 檔，存於 runtime/conversation_memory/ 下。本實作不依賴任何資料庫，
 * 符合 Additive Implementation 與最低重構原則。
 */
final class JsonFileConversationMemoryRepository implements ConversationMemoryRepositoryInterface
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
            . DIRECTORY_SEPARATOR . 'conversation_memory';
    }

    public function find(string $conversationId): ?CustomerMemoryCard
    {
        $path = $this->cardFilePath($conversationId);
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

        return CustomerMemoryCard::fromArray($decoded);
    }

    public function save(CustomerMemoryCard $card): void
    {
        if (!is_dir($this->storeDirectory)) {
            mkdir($this->storeDirectory, 0775, true);
        }

        file_put_contents(
            $this->cardFilePath($card->getConversationId()),
            json_encode($card->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public function delete(string $conversationId): void
    {
        $path = $this->cardFilePath($conversationId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function cardFilePath(string $conversationId): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9._-]+/', '_', trim($conversationId)) ?? 'unknown';
        if ($safeId === '') {
            $safeId = 'unknown';
        }

        return $this->storeDirectory . DIRECTORY_SEPARATOR . $safeId . '.json';
    }
}
