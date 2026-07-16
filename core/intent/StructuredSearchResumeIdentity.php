<?php
declare(strict_types=1);

/**
 * State-specific Structured Search Resume identity.
 *
 * Canonical: {tenant_sno}:line:{channel_id}:{user_id}
 * Does not modify ConversationIdentityBuilder::forLine.
 */
final class StructuredSearchResumeIdentity
{
    public const CHANNEL_TYPE_LINE = 'line';

    private string $tenantSno;
    private string $channelType;
    private string $channelId;
    private string $userId;
    private string $conversationId;
    private string $storageKey;

    public function __construct(
        string $tenantSno,
        string $channelId,
        string $userId,
        string $channelType = self::CHANNEL_TYPE_LINE
    ) {
        $tenantSno = trim($tenantSno);
        $channelId = trim($channelId);
        $userId = trim($userId);
        $channelType = trim($channelType);

        if ($tenantSno === '' || $channelId === '' || $userId === '') {
            throw new \InvalidArgumentException('resume identity requires tenant_sno, channel_id, user_id');
        }
        if ($channelType === '') {
            throw new \InvalidArgumentException('resume identity requires channel_type');
        }

        $this->tenantSno = $tenantSno;
        $this->channelType = $channelType;
        $this->channelId = $channelId;
        $this->userId = $userId;
        $this->conversationId = $tenantSno . ':' . $channelType . ':' . $channelId . ':' . $userId;
        $this->storageKey = hash('sha256', $this->conversationId);
    }

    public static function fromParts(
        string $tenantSno,
        string $channelId,
        string $userId,
        string $channelType = self::CHANNEL_TYPE_LINE
    ): self {
        return new self($tenantSno, $channelId, $userId, $channelType);
    }

    public function getTenantSno(): string
    {
        return $this->tenantSno;
    }

    public function getChannelType(): string
    {
        return $this->channelType;
    }

    public function getChannelId(): string
    {
        return $this->channelId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    /** Lowercase hex SHA-256 of canonical identity (filename stem). */
    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    /**
     * @return array{
     *   conversation_id: string,
     *   tenant_sno: string,
     *   channel_type: string,
     *   channel_id: string,
     *   user_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'tenant_sno' => $this->tenantSno,
            'channel_type' => $this->channelType,
            'channel_id' => $this->channelId,
            'user_id' => $this->userId,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public function matchesDocument(array $document): bool
    {
        return trim((string) ($document['conversation_id'] ?? '')) === $this->conversationId
            && trim((string) ($document['tenant_sno'] ?? '')) === $this->tenantSno
            && trim((string) ($document['channel_type'] ?? '')) === $this->channelType
            && trim((string) ($document['channel_id'] ?? '')) === $this->channelId
            && trim((string) ($document['user_id'] ?? '')) === $this->userId;
    }
}
