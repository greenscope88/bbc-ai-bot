<?php
declare(strict_types=1);

/**
 * LINE sender result contract (Phase 9-B-25).
 *
 * Represents send preparation outcome. Must not contain HTTP responses or API credentials.
 */
final class LineSenderResult
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const SENDER_TYPES = ['reply', 'push'];

    private bool $success;

    private string $senderType;

    private int $messageCount;

    private int $payloadSize;

    private string $traceId;

    private int $schemaVersion;

    private function __construct(
        bool $success,
        string $senderType,
        int $messageCount,
        int $payloadSize,
        string $traceId,
        int $schemaVersion
    ) {
        $this->success = $success;
        $this->senderType = $senderType;
        $this->messageCount = $messageCount;
        $this->payloadSize = $payloadSize;
        $this->traceId = $traceId;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $normalized = self::normalizeDocument($document);

        return new self(
            (bool) $normalized['success'],
            (string) $normalized['sender_type'],
            (int) $normalized['message_count'],
            (int) $normalized['payload_size'],
            (string) $normalized['trace_id'],
            (int) $normalized['schema_version']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'success' => $this->success,
            'sender_type' => $this->senderType,
            'message_count' => $this->messageCount,
            'payload_size' => $this->payloadSize,
            'trace_id' => $this->traceId,
        ];
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getPayloadSize(): int
    {
        return $this->payloadSize;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function normalizeDocument(array $document): array
    {
        $out = [];

        $out['schema_version'] = isset($document['schema_version'])
            ? (int) $document['schema_version']
            : self::SCHEMA_VERSION;
        $out['success'] = isset($document['success']) && (bool) $document['success'];
        $out['sender_type'] = isset($document['sender_type'])
            ? strtolower(trim((string) $document['sender_type']))
            : '';
        $out['message_count'] = isset($document['message_count'])
            ? (int) $document['message_count']
            : 0;
        $out['payload_size'] = isset($document['payload_size'])
            ? (int) $document['payload_size']
            : 0;
        $out['trace_id'] = isset($document['trace_id'])
            ? trim((string) $document['trace_id'])
            : '';

        return $out;
    }
}
