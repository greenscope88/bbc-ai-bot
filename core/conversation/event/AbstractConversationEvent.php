<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventType.php';

/**
 * Phase 2-B Step 4-D-1 — Conversation Domain Event 共用基底.
 *
 * 提供不可變的共同欄位（conversationId / occurredAt / payload）與序列化。
 * 子類別只需宣告 getType()。本基底不含任何外部解析或 Runtime 行為。
 */
abstract class AbstractConversationEvent implements ConversationEventInterface
{
    private string $conversationId;

    private \DateTimeImmutable $occurredAt;

    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $conversationId,
        ?\DateTimeImmutable $occurredAt = null,
        array $payload = []
    ) {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            throw new \InvalidArgumentException('conversation event requires a non-empty conversation_id');
        }

        $this->conversationId = $conversationId;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
        $this->payload = $payload;
    }

    abstract public function getType(): string;

    final public function getConversationId(): string
    {
        return $this->conversationId;
    }

    final public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    final public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @return mixed
     */
    final protected function payloadValue(string $key, $default = null)
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->getType(),
            'conversation_id' => $this->conversationId,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'payload' => $this->payload,
        ];
    }
}
