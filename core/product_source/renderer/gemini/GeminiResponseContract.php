<?php
declare(strict_types=1);

/**
 * Gemini response contract (Phase 9-B-24).
 *
 * Represents future Gemini reply output. Must not contain prompts or API credentials.
 */
final class GeminiResponseContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const REPLY_TYPES = [
        'normal_reply',
        'human_agent_fallback',
        'out_of_scope',
    ];

    private string $replyText;

    private string $replyType;

    private bool $usedFallback;

    private ?string $usedServiceScope;

    private string $voiceProfileUsed;

    private int $schemaVersion;

    private function __construct(
        string $replyText,
        string $replyType,
        bool $usedFallback,
        ?string $usedServiceScope,
        string $voiceProfileUsed,
        int $schemaVersion
    ) {
        $this->replyText = $replyText;
        $this->replyType = $replyType;
        $this->usedFallback = $usedFallback;
        $this->usedServiceScope = $usedServiceScope;
        $this->voiceProfileUsed = $voiceProfileUsed;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $normalized = self::normalizeDocument($document);

        return new self(
            (string) $normalized['reply_text'],
            (string) $normalized['reply_type'],
            (bool) $normalized['used_fallback'],
            $normalized['used_service_scope'],
            (string) $normalized['voice_profile_used'],
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
            'reply_text' => $this->replyText,
            'reply_type' => $this->replyType,
            'used_fallback' => $this->usedFallback,
            'used_service_scope' => $this->usedServiceScope,
            'voice_profile_used' => $this->voiceProfileUsed,
        ];
    }

    public function getReplyText(): string
    {
        return $this->replyText;
    }

    public function getReplyType(): string
    {
        return $this->replyType;
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
        $out['reply_text'] = isset($document['reply_text'])
            ? trim((string) $document['reply_text'])
            : '';
        $out['reply_type'] = isset($document['reply_type'])
            ? strtolower(trim((string) $document['reply_type']))
            : '';
        $out['used_fallback'] = isset($document['used_fallback']) && (bool) $document['used_fallback'];
        $out['voice_profile_used'] = isset($document['voice_profile_used'])
            ? trim((string) $document['voice_profile_used'])
            : '';

        if (array_key_exists('used_service_scope', $document) && $document['used_service_scope'] !== null) {
            $scope = trim((string) $document['used_service_scope']);
            $out['used_service_scope'] = $scope !== '' ? $scope : null;
        } else {
            $out['used_service_scope'] = null;
        }

        return $out;
    }
}
