<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeIdentity.php';

/**
 * Pending Structured Search Resume State document (schema v2 only).
 */
final class StructuredSearchResumeState
{
    public const SCHEMA_VERSION = 2;
    public const INTENT_SCOPE_PRODUCT_SEARCH = 'product_search';
    public const OP_CREATE = 'create';
    public const OP_REPLACE = 'replace';

    public const STATUS_WAITING_SINGLE_DESTINATION = 'WAITING_SINGLE_DESTINATION';
    public const STATUS_WAITING_CLARIFICATION = 'WAITING_CLARIFICATION';

    public const RESUME_REASON_RELATION_CAPABILITY_UNAVAILABLE = 'relation_capability_unavailable';
    public const RESUME_REASON_AIU_PRODUCT_CLARIFICATION = 'aiu_product_clarification';

    public const TRIGGER_DESTINATION_EXECUTION_GATE = 'destination_execution_gate';
    public const TRIGGER_AIU_CLARIFICATION = 'aiu_clarification';

    public const RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE = 'destination_relation_capability_unavailable';
    public const RESPONSE_ROUTE_AIU_CLARIFICATION = 'aiu_product_clarification';

    /** @var list<string> */
    public const KNOWN_ENTITY_KEYS = [
        'destination',
        'destination_relation',
        'destination_semantics',
        'departure',
        'date_from',
        'date_to',
        'date_expression',
        'duration_days',
        'budget_amount',
        'people_count',
        'product_type',
        'must_have',
        'avoid',
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
    private string $status;
    private string $resumeReason;
    private string $resumeTriggerSource;
    private string $askedEntity;
    private string $responseRoute;
    /** @var array<string, mixed> */
    private array $knownEntities;
    private bool $clarificationRequired;
    private string $aiuClarificationReason;
    private string $relationCapabilityVersion;
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
        string $status,
        string $resumeReason,
        string $resumeTriggerSource,
        string $askedEntity,
        string $responseRoute,
        array $knownEntities,
        string $aiuClarificationReason,
        string $relationCapabilityVersion,
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
        $this->status = $status;
        $this->resumeReason = $resumeReason;
        $this->resumeTriggerSource = $resumeTriggerSource;
        $this->askedEntity = $askedEntity;
        $this->responseRoute = $responseRoute;
        $this->knownEntities = $knownEntities;
        $this->clarificationRequired = $clarificationRequired;
        $this->aiuClarificationReason = $aiuClarificationReason;
        $this->relationCapabilityVersion = $relationCapabilityVersion;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getResumeReason(): string
    {
        return $this->resumeReason;
    }

    public function getResumeTriggerSource(): string
    {
        return $this->resumeTriggerSource;
    }

    public function getAskedEntity(): string
    {
        return $this->askedEntity;
    }

    public function getResponseRoute(): string
    {
        return $this->responseRoute;
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

    public function getRelationCapabilityVersion(): string
    {
        return $this->relationCapabilityVersion;
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

    public function isCapabilityWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING_SINGLE_DESTINATION
            && $this->resumeReason === self::RESUME_REASON_RELATION_CAPABILITY_UNAVAILABLE;
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
            'status' => $this->status,
            'resume_reason' => $this->resumeReason,
            'resume_trigger_source' => $this->resumeTriggerSource,
            'asked_entity' => $this->askedEntity,
            'response_route' => $this->responseRoute,
            'known_entities' => $this->knownEntities,
            'clarification_required' => $this->clarificationRequired,
            'aiu_clarification_reason' => $this->aiuClarificationReason,
            'relation_capability_version' => $this->relationCapabilityVersion,
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
            'status' => $this->status,
            'resume_reason' => $this->resumeReason,
            'resume_trigger_source' => $this->resumeTriggerSource,
            'asked_entity' => $this->askedEntity,
            'response_route' => $this->responseRoute,
            'known_entities' => $this->knownEntities,
            'clarification_required' => $this->clarificationRequired,
            'aiu_clarification_reason' => $this->aiuClarificationReason,
            'relation_capability_version' => $this->relationCapabilityVersion,
            'provenance_reference_timezone' => $this->provenanceReferenceTimezone,
            'provenance_reference_calendar_date' => $this->provenanceReferenceCalendarDate,
            'last_event_id' => $this->lastEventId,
            'last_event_operation' => $this->lastEventOperation,
        ];
    }
}
