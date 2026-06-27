<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationOwner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStatus.php';

/**
 * Phase 2-B Step 2-A — Conversation State（對話狀態實體）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §5～§11.
 *
 * 保存一段對話的：Conversation Owner、Conversation Status、最後一次真人客服
 * 訊息時間（last_human_message_at，CA-004 正式欄位名）、最後一次客戶訊息時間
 * （用於 AI Resume 的「Customer New Message」條件，CA-006）。
 *
 * 本 DTO 為純資料容器：不含計時、takeover、resume 等決策邏輯
 * （決策由 ConversationStateRuntime 負責）。
 */
final class ConversationState
{
    private string $conversationId;

    private string $owner = ConversationOwner::AI;

    private string $status = ConversationStatus::ACTIVE;

    /** CA-004 正式欄位名：最後一次真人客服訊息時間（ATOM 字串）。 */
    private ?string $lastHumanMessageAt = null;

    /** AI Resume「Customer New Message」判定用：最後一次客戶訊息時間（ATOM 字串）。 */
    private ?string $lastCustomerMessageAt = null;

    private ?string $updatedAt = null;

    public function __construct(string $conversationId)
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            throw new \InvalidArgumentException('conversationId must not be empty');
        }
        $this->conversationId = $conversationId;
    }

    public static function create(string $conversationId): self
    {
        return new self($conversationId);
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function setOwner(string $owner): self
    {
        $owner = trim($owner);
        ConversationOwner::assertValid($owner);
        $this->owner = $owner;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $status = trim($status);
        ConversationStatus::assertValid($status);
        $this->status = $status;

        return $this;
    }

    public function getLastHumanMessageAt(): ?string
    {
        return $this->lastHumanMessageAt;
    }

    public function setLastHumanMessageAt(?string $timestamp): self
    {
        $this->lastHumanMessageAt = self::normalizeTimestamp($timestamp);

        return $this;
    }

    public function getLastCustomerMessageAt(): ?string
    {
        return $this->lastCustomerMessageAt;
    }

    public function setLastCustomerMessageAt(?string $timestamp): self
    {
        $this->lastCustomerMessageAt = self::normalizeTimestamp($timestamp);

        return $this;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function touch(?string $timestamp): self
    {
        $this->updatedAt = self::normalizeTimestamp($timestamp);

        return $this;
    }

    /**
     * 將 ATOM 字串解析為 DateTimeImmutable；無效或空值回傳 null。
     */
    public function getLastHumanMessageAtDate(): ?\DateTimeImmutable
    {
        return self::parseTimestamp($this->lastHumanMessageAt);
    }

    public function getLastCustomerMessageAtDate(): ?\DateTimeImmutable
    {
        return self::parseTimestamp($this->lastCustomerMessageAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'owner' => $this->owner,
            'status' => $this->status,
            'last_human_message_at' => $this->lastHumanMessageAt,
            'last_customer_message_at' => $this->lastCustomerMessageAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conversationId = isset($data['conversation_id']) ? (string) $data['conversation_id'] : '';
        $state = new self($conversationId);

        if (isset($data['owner']) && trim((string) $data['owner']) !== '') {
            $state->setOwner((string) $data['owner']);
        }
        if (isset($data['status']) && trim((string) $data['status']) !== '') {
            $state->setStatus((string) $data['status']);
        }
        if (array_key_exists('last_human_message_at', $data)) {
            $state->setLastHumanMessageAt(
                $data['last_human_message_at'] !== null ? (string) $data['last_human_message_at'] : null
            );
        }
        if (array_key_exists('last_customer_message_at', $data)) {
            $state->setLastCustomerMessageAt(
                $data['last_customer_message_at'] !== null ? (string) $data['last_customer_message_at'] : null
            );
        }
        if (array_key_exists('updated_at', $data)) {
            $state->touch($data['updated_at'] !== null ? (string) $data['updated_at'] : null);
        }

        return $state;
    }

    private static function normalizeTimestamp(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }
        $timestamp = trim($timestamp);

        return $timestamp === '' ? null : $timestamp;
    }

    private static function parseTimestamp(?string $timestamp): ?\DateTimeImmutable
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception $e) {
            return null;
        }
    }
}
