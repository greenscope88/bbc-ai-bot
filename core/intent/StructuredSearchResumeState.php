<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeIdentity.php';

/**
 * Pending Structured Search Resume State document (schema v1).
 */
final class StructuredSearchResumeState
{
    public const SCHEMA_VERSION = 1;
    public const INTENT_SCOPE_PRODUCT_SEARCH = 'product_search';
    public const OP_CREATE = 'create';
    public const OP_REPLACE = 'replace';

    /** @var list<string> */
    public const KNOWN_ENTITY_KEYS = [
        'destination',
        'departure',
        'date_from',
        'date_to',
        'duration_days',
        'budget_amount',
        'people_count',
        'product_type',
    ];

    private int $schemaVersion;
    private string $conversationId;
    private string $tenantSno;
    private string $channelType;
    private string $channelId;
    private string $userId;
    private string $sourceTraceId;
    private string $createdAt;
    private string $updatedAt;
    private string $expiresAt;
    private int $stateVersion;
    private string $intentScope;
    /** @var array<string, mixed> */
    private array $knownEntities;
    private bool $clarificationRequired;
    private string $aiuClarificationReason;
    private string $missingEntity;
    private string $provenanceReferenceTimezone;
    private string $provenanceReferenceCalendarDate;
    private string $lastEventId;
    private string $lastEventOperation;

    /**
     * @param array<string, mixed> $knownEntities
     */
    public function __construct(
        string $conversationId,
        string $tenantSno,
        string $channelType,
        string $channelId,
        string $userId,
        string $sourceTraceId,
        string $createdAt,
        string $updatedAt,
        string $expiresAt,
        int $stateVersion,
        array $knownEntities,
        string $aiuClarificationReason,
        string $missingEntity,
        string $provenanceReferenceTimezone,
        string $provenanceReferenceCalendarDate,
        string $lastEventId,
        string $lastEventOperation,
        int $schemaVersion = self::SCHEMA_VERSION,
        string $intentScope = self::INTENT_SCOPE_PRODUCT_SEARCH,
        bool $clarificationRequired = true
    ) {
        $this->schemaVersion = $schemaVersion;
        $this->conversationId = $conversationId;
        $this->tenantSno = $tenantSno;
        $this->channelType = $channelType;
        $this->channelId = $channelId;
        $this->userId = $userId;
        $this->sourceTraceId = $sourceTraceId;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->expiresAt = $expiresAt;
        $this->stateVersion = $stateVersion;
        $this->intentScope = $intentScope;
        $this->knownEntities = $knownEntities;
        $this->clarificationRequired = $clarificationRequired;
        $this->aiuClarificationReason = $aiuClarificationReason;
        $this->missingEntity = $missingEntity;
        $this->provenanceReferenceTimezone = $provenanceReferenceTimezone;
        $this->provenanceReferenceCalendarDate = $provenanceReferenceCalendarDate;
        $this->lastEventId = $lastEventId;
        $this->lastEventOperation = $lastEventOperation;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
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

    public function getSourceTraceId(): string
    {
        return $this->sourceTraceId;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function getExpiresAt(): string
    {
        return $this->expiresAt;
    }

    public function getStateVersion(): int
    {
        return $this->stateVersion;
    }

    public function getIntentScope(): string
    {
        return $this->intentScope;
    }

    /**
     * @return array<string, mixed>
     */
    public function getKnownEntities(): array
    {
        return $this->knownEntities;
    }

    public function isClarificationRequired(): bool
    {
        return $this->clarificationRequired;
    }

    public function getAiuClarificationReason(): string
    {
        return $this->aiuClarificationReason;
    }

    public function getMissingEntity(): string
    {
        return $this->missingEntity;
    }

    public function getProvenanceReferenceTimezone(): string
    {
        return $this->provenanceReferenceTimezone;
    }

    public function getProvenanceReferenceCalendarDate(): string
    {
        return $this->provenanceReferenceCalendarDate;
    }

    public function getLastEventId(): string
    {
        return $this->lastEventId;
    }

    public function getLastEventOperation(): string
    {
        return $this->lastEventOperation;
    }

    public function toIdentity(): StructuredSearchResumeIdentity
    {
        return new StructuredSearchResumeIdentity(
            $this->tenantSno,
            $this->channelId,
            $this->userId,
            $this->channelType
        );
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        try {
            $expires = new \DateTimeImmutable($this->expiresAt);
        } catch (\Exception $e) {
            return true;
        }

        return $expires <= $now;
    }

    /**
     * Prompt injection shape (typed facts + provenance). Not a merge instruction.
     *
     * @return array<string, mixed>
     */
    public function toPromptInjectionArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'conversation_id' => $this->conversationId,
            'state_version' => $this->stateVersion,
            'intent_scope' => $this->intentScope,
            'known_entities' => $this->knownEntities,
            'clarification_required' => $this->clarificationRequired,
            'aiu_clarification_reason' => $this->aiuClarificationReason,
            'missing_entity' => $this->missingEntity,
            'provenance_reference_timezone' => $this->provenanceReferenceTimezone,
            'provenance_reference_calendar_date' => $this->provenanceReferenceCalendarDate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'conversation_id' => $this->conversationId,
            'tenant_sno' => $this->tenantSno,
            'channel_type' => $this->channelType,
            'channel_id' => $this->channelId,
            'user_id' => $this->userId,
            'source_trace_id' => $this->sourceTraceId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'expires_at' => $this->expiresAt,
            'state_version' => $this->stateVersion,
            'intent_scope' => $this->intentScope,
            'known_entities' => $this->knownEntities,
            'clarification_required' => $this->clarificationRequired,
            'aiu_clarification_reason' => $this->aiuClarificationReason,
            'missing_entity' => $this->missingEntity,
            'provenance_reference_timezone' => $this->provenanceReferenceTimezone,
            'provenance_reference_calendar_date' => $this->provenanceReferenceCalendarDate,
            'last_event_id' => $this->lastEventId,
            'last_event_operation' => $this->lastEventOperation,
        ];
    }
}
