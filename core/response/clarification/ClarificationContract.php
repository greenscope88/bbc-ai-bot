<?php
declare(strict_types=1);

/**
 * Generative Clarification structured input contract (foundation).
 *
 * Carries Runtime clarification direction only — never parses utterances
 * and never owns customer-facing wording.
 */
final class ClarificationContract
{
    public const SCHEMA_VERSION = 1;

    public const REASON_DATE_REQUIRED = 'date_required';
    public const REASON_DESTINATION_UNKNOWN = 'destination_unknown';

    public const ENTITY_DATE = 'date';
    public const ENTITY_DESTINATION = 'destination';

    public const TECHNICAL_FAIL_CLOSED_TEXT =
        '目前無法完成這次確認提問，請稍後再試一次，或直接告訴我您想補充的條件 😊';

    /** @var list<string> */
    public const ALLOWED_KNOWN_KEYS = ['date_from', 'date_to', 'departure', 'destination'];

    private int $schemaVersion;

    private string $clarificationReason;

    private string $missingEntity;

    /** @var array<string, mixed> */
    private array $knownEntities;

    /** @var list<string> */
    private array $mustAsk;

    /** @var list<string> */
    private array $mustNotAsk;

    private bool $searchExecuted;

    /** @var array{persona: string, allow_emoji: bool} */
    private array $tone;

    /** @var array<string, mixed> */
    private array $tenant;

    /** @var array{trace_id: string, tenant_sno: string, conversation_id: string} */
    private array $traceMetadata;

    /** @var list<array<string, mixed>> */
    private array $groundedFacts;

    /**
     * @param array<string, mixed> $knownEntities
     * @param list<string>         $mustAsk
     * @param list<string>         $mustNotAsk
     * @param array{persona: string, allow_emoji: bool} $tone
     * @param array<string, mixed> $tenant
     * @param array{trace_id: string, tenant_sno: string, conversation_id: string} $traceMetadata
     * @param list<array<string, mixed>> $groundedFacts
     */
    public function __construct(
        string $clarificationReason,
        string $missingEntity,
        array $knownEntities,
        array $mustAsk,
        array $mustNotAsk,
        array $tone,
        array $tenant,
        array $traceMetadata,
        array $groundedFacts,
        bool $searchExecuted = false,
        int $schemaVersion = self::SCHEMA_VERSION
    ) {
        $this->schemaVersion = $schemaVersion;
        $this->clarificationReason = $clarificationReason;
        $this->missingEntity = $missingEntity;
        $this->knownEntities = $knownEntities;
        $this->mustAsk = array_values($mustAsk);
        $this->mustNotAsk = array_values($mustNotAsk);
        $this->searchExecuted = $searchExecuted;
        $this->tone = $tone;
        $this->tenant = $tenant;
        $this->traceMetadata = $traceMetadata;
        $this->groundedFacts = $groundedFacts;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function getClarificationReason(): string
    {
        return $this->clarificationReason;
    }

    public function getMissingEntity(): string
    {
        return $this->missingEntity;
    }

    /**
     * @return array<string, mixed>
     */
    public function getKnownEntities(): array
    {
        return $this->knownEntities;
    }

    /**
     * @return list<string>
     */
    public function getMustAsk(): array
    {
        return $this->mustAsk;
    }

    /**
     * @return list<string>
     */
    public function getMustNotAsk(): array
    {
        return $this->mustNotAsk;
    }

    public function isSearchExecuted(): bool
    {
        return $this->searchExecuted;
    }

    /**
     * @return array{persona: string, allow_emoji: bool}
     */
    public function getTone(): array
    {
        return $this->tone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTenant(): array
    {
        return $this->tenant;
    }

    /**
     * @return array{trace_id: string, tenant_sno: string, conversation_id: string}
     */
    public function getTraceMetadata(): array
    {
        return $this->traceMetadata;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGroundedFacts(): array
    {
        return $this->groundedFacts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'clarification_required' => true,
            'clarification_reason' => $this->clarificationReason,
            'missing_entity' => $this->missingEntity,
            'known_entities' => $this->knownEntities,
            'must_ask' => $this->mustAsk,
            'must_not_ask' => $this->mustNotAsk,
            'search_executed' => $this->searchExecuted,
            'tone' => $this->tone,
            'tenant' => $this->tenant,
            'trace_metadata' => $this->traceMetadata,
            'grounded_facts' => $this->groundedFacts,
        ];
    }

    public function factIdForKnownKey(string $key, ?string $destinationIndex = null): string
    {
        if ($key === 'destination' && $destinationIndex !== null) {
            return 'known.destination.' . $destinationIndex;
        }

        return 'known.' . $key;
    }
}
