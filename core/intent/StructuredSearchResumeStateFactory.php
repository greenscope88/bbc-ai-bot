<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeIdentity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuClarificationReasonContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DestinationFeasibilityContracts.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DestinationRelationCapabilityRegistry.php';

/**
 * Builds / validates Structured Search Resume State documents (schema v2 only).
 */
final class StructuredSearchResumeStateFactory
{
    public const DEFAULT_TTL_SECONDS = 86400;

    /** @var list<string> */
    private const DOCUMENT_REQUIRED_KEYS = [
        'schema_version',
        'conversation_id',
        'tenant_sno',
        'channel_type',
        'channel_id',
        'user_id',
        'source_trace_id',
        'created_at',
        'updated_at',
        'expires_at',
        'state_version',
        'intent_scope',
        'status',
        'resume_reason',
        'resume_trigger_source',
        'asked_entity',
        'response_route',
        'known_entities',
        'clarification_required',
        'aiu_clarification_reason',
        'relation_capability_version',
        'provenance_reference_timezone',
        'provenance_reference_calendar_date',
        'last_event_id',
        'last_event_operation',
    ];

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): StructuredSearchResumeState
    {
        self::assertDocumentShape($document);

        if ((int) $document['schema_version'] !== StructuredSearchResumeState::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('resume state schema_version unsupported');
        }

        $known = self::normalizeKnownEntities($document['known_entities']);
        $status = trim((string) $document['status']);
        $resumeReason = trim((string) $document['resume_reason']);
        $trigger = trim((string) $document['resume_trigger_source']);
        $asked = trim((string) $document['asked_entity']);
        $responseRoute = trim((string) $document['response_route']);
        $aiuReason = trim((string) $document['aiu_clarification_reason']);
        $relationCapabilityVersion = trim((string) $document['relation_capability_version']);

        self::assertReasonContract(
            $status,
            $resumeReason,
            $trigger,
            $asked,
            $responseRoute,
            $aiuReason,
            $relationCapabilityVersion,
            $known
        );

        $op = trim((string) $document['last_event_operation']);
        if ($op !== StructuredSearchResumeState::OP_CREATE && $op !== StructuredSearchResumeState::OP_REPLACE) {
            throw new \InvalidArgumentException('resume state last_event_operation invalid');
        }

        if (trim((string) $document['intent_scope']) !== StructuredSearchResumeState::INTENT_SCOPE_PRODUCT_SEARCH) {
            throw new \InvalidArgumentException('resume state intent_scope unsupported');
        }

        if ((bool) $document['clarification_required'] !== true) {
            throw new \InvalidArgumentException('resume state clarification_required must be true');
        }

        $version = (int) $document['state_version'];
        if ($version < 1) {
            throw new \InvalidArgumentException('resume state state_version invalid');
        }

        $lastEventId = trim((string) $document['last_event_id']);
        if ($lastEventId === '') {
            throw new \InvalidArgumentException('resume state last_event_id required');
        }

        return new StructuredSearchResumeState(
            trim((string) $document['conversation_id']),
            trim((string) $document['tenant_sno']),
            trim((string) $document['channel_type']),
            trim((string) $document['channel_id']),
            trim((string) $document['user_id']),
            trim((string) $document['source_trace_id']),
            trim((string) $document['created_at']),
            trim((string) $document['updated_at']),
            trim((string) $document['expires_at']),
            $version,
            $status,
            $resumeReason,
            $trigger,
            $asked,
            $responseRoute,
            $known,
            $aiuReason,
            $relationCapabilityVersion,
            trim((string) $document['provenance_reference_timezone']),
            trim((string) $document['provenance_reference_calendar_date']),
            $lastEventId,
            $op,
            StructuredSearchResumeState::SCHEMA_VERSION,
            StructuredSearchResumeState::INTENT_SCOPE_PRODUCT_SEARCH,
            true
        );
    }

    public static function fromValidatedClarification(
        StructuredSearchResumeIdentity $identity,
        AiIntentUnderstandingResult $result,
        string $eventId,
        string $operation,
        \DateTimeImmutable $referenceDateTime,
        string $sourceTraceId,
        int $stateVersion,
        ?string $createdAt = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): StructuredSearchResumeState {
        if (!$result->isClarificationRequired()) {
            throw new \InvalidArgumentException('resume state requires clarification_required');
        }
        if ($result->getIntent() !== \AiIntentCategory::PRODUCT_SEARCH) {
            throw new \InvalidArgumentException('resume state requires product_search intent');
        }

        $reason = trim($result->getClarificationReason());
        AiuClarificationReasonContract::assertValidProductSearchClarification(
            true,
            $reason,
            $result->getEntities()
        );
        $asked = AiuClarificationReasonContract::missingEntityForAiuReason($reason);

        $eventId = trim($eventId);
        if ($eventId === '') {
            throw new \InvalidArgumentException('resume state last_event_id required');
        }
        if ($operation !== StructuredSearchResumeState::OP_CREATE
            && $operation !== StructuredSearchResumeState::OP_REPLACE) {
            throw new \InvalidArgumentException('resume state last_event_operation invalid');
        }
        if ($stateVersion < 1) {
            throw new \InvalidArgumentException('resume state state_version invalid');
        }

        $nowAtom = $referenceDateTime->format(\DateTimeInterface::ATOM);
        $created = $createdAt !== null && trim($createdAt) !== '' ? trim($createdAt) : $nowAtom;
        $expires = $referenceDateTime
            ->modify('+' . max(1, $ttlSeconds) . ' seconds')
            ->format(\DateTimeInterface::ATOM);

        return new StructuredSearchResumeState(
            $identity->getConversationId(),
            $identity->getTenantSno(),
            $identity->getChannelType(),
            $identity->getChannelId(),
            $identity->getUserId(),
            trim($sourceTraceId),
            $created,
            $nowAtom,
            $expires,
            $stateVersion,
            StructuredSearchResumeState::STATUS_WAITING_CLARIFICATION,
            StructuredSearchResumeState::RESUME_REASON_AIU_PRODUCT_CLARIFICATION,
            StructuredSearchResumeState::TRIGGER_AIU_CLARIFICATION,
            $asked,
            StructuredSearchResumeState::RESPONSE_ROUTE_AIU_CLARIFICATION,
            self::knownEntitiesFromAiuEntities($result->getEntities()),
            $reason,
            '',
            $referenceDateTime->getTimezone()->getName(),
            $referenceDateTime->format('Y-m-d'),
            $eventId,
            $operation
        );
    }

    /**
     * @param array<string, mixed> $aiuEntities optional overlay for fields not on BatsSearchIntent (e.g. date_expression)
     */
    public static function fromRelationCapabilityUnavailable(
        StructuredSearchResumeIdentity $identity,
        BatsSearchIntent $intent,
        string $eventId,
        string $operation,
        \DateTimeImmutable $referenceDateTime,
        string $sourceTraceId,
        int $stateVersion,
        string $relationCapabilityVersion,
        ?string $createdAt = null,
        array $aiuEntities = [],
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): StructuredSearchResumeState {
        $known = self::knownEntitiesFromBatsSearchIntent($intent, $aiuEntities);
        self::assertCapabilityKnownEntitiesComplete($known);

        $eventId = trim($eventId);
        if ($eventId === '') {
            throw new \InvalidArgumentException('resume state last_event_id required');
        }
        if ($operation !== StructuredSearchResumeState::OP_CREATE
            && $operation !== StructuredSearchResumeState::OP_REPLACE) {
            throw new \InvalidArgumentException('resume state last_event_operation invalid');
        }
        if ($stateVersion < 1) {
            throw new \InvalidArgumentException('resume state state_version invalid');
        }
        $relationCapabilityVersion = trim($relationCapabilityVersion);
        if ($relationCapabilityVersion === '') {
            throw new \InvalidArgumentException('resume state relation_capability_version required');
        }

        $nowAtom = $referenceDateTime->format(\DateTimeInterface::ATOM);
        $created = $createdAt !== null && trim($createdAt) !== '' ? trim($createdAt) : $nowAtom;
        $expires = $referenceDateTime
            ->modify('+' . max(1, $ttlSeconds) . ' seconds')
            ->format(\DateTimeInterface::ATOM);

        return new StructuredSearchResumeState(
            $identity->getConversationId(),
            $identity->getTenantSno(),
            $identity->getChannelType(),
            $identity->getChannelId(),
            $identity->getUserId(),
            trim($sourceTraceId),
            $created,
            $nowAtom,
            $expires,
            $stateVersion,
            StructuredSearchResumeState::STATUS_WAITING_SINGLE_DESTINATION,
            StructuredSearchResumeState::RESUME_REASON_RELATION_CAPABILITY_UNAVAILABLE,
            StructuredSearchResumeState::TRIGGER_DESTINATION_EXECUTION_GATE,
            'destination',
            StructuredSearchResumeState::RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE,
            $known,
            '',
            $relationCapabilityVersion,
            $referenceDateTime->getTimezone()->getName(),
            $referenceDateTime->format('Y-m-d'),
            $eventId,
            $operation
        );
    }

    /**
     * @param array<string, mixed> $aiuEntities
     * @return array<string, mixed>
     */
    public static function knownEntitiesFromAiuEntities(array $aiuEntities): array
    {
        $out = self::emptyKnownEntities();
        $out['destination'] = self::stringList($aiuEntities['destination'] ?? []);
        $out['destination_relation'] = self::nullableString($aiuEntities['destination_relation'] ?? null);
        $out['destination_semantics'] = self::semanticsList($aiuEntities['destination_semantics'] ?? []);
        $out['departure'] = self::nullableString($aiuEntities['departure'] ?? null);
        $out['date_from'] = self::nullableString($aiuEntities['date_from'] ?? null);
        $out['date_to'] = self::nullableString($aiuEntities['date_to'] ?? null);
        $out['date_expression'] = self::nullableString($aiuEntities['date_expression'] ?? null);
        $out['duration_days'] = self::nullableInt($aiuEntities['duration_days'] ?? null);
        $out['budget_amount'] = self::nullableNumber($aiuEntities['budget_amount'] ?? null);
        $out['people_count'] = self::nullableInt($aiuEntities['people_count'] ?? null);
        $out['product_type'] = self::nullableString($aiuEntities['product_type'] ?? null);
        $out['must_have'] = self::stringList($aiuEntities['must_have'] ?? []);
        $out['avoid'] = self::stringList($aiuEntities['avoid'] ?? []);

        return $out;
    }

    /**
     * @param array<string, mixed> $aiuEntities
     * @return array<string, mixed>
     */
    public static function knownEntitiesFromBatsSearchIntent(BatsSearchIntent $intent, array $aiuEntities = []): array
    {
        $out = self::emptyKnownEntities();
        $out['destination'] = $intent->getDestination();
        $out['destination_relation'] = self::nullableString($intent->getDestinationRelation());
        $out['destination_semantics'] = $intent->getDestinationSemantics();
        $out['departure'] = $intent->getDepartureCity();
        $out['date_from'] = $intent->getDateFrom();
        $out['date_to'] = $intent->getDateTo();
        $out['date_expression'] = self::nullableString($aiuEntities['date_expression'] ?? null);
        $duration = $intent->getDuration();
        $out['duration_days'] = ($duration !== null && $duration !== '' && is_numeric($duration))
            ? (int) $duration
            : self::nullableInt($aiuEntities['duration_days'] ?? null);
        $budget = $intent->getBudgetMax();
        $out['budget_amount'] = $budget !== null
            ? $budget
            : self::nullableNumber($aiuEntities['budget_amount'] ?? null);
        $out['people_count'] = $intent->getPeopleCount();
        $out['product_type'] = $intent->getProductType();
        $mustHave = $intent->getMustHave();
        $out['must_have'] = $mustHave !== []
            ? $mustHave
            : self::stringList($aiuEntities['must_have'] ?? []);
        $avoid = $intent->getAvoid();
        $out['avoid'] = $avoid !== []
            ? $avoid
            : self::stringList($aiuEntities['avoid'] ?? []);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyKnownEntities(): array
    {
        return [
            'destination' => [],
            'destination_relation' => null,
            'destination_semantics' => [],
            'departure' => null,
            'date_from' => null,
            'date_to' => null,
            'date_expression' => null,
            'duration_days' => null,
            'budget_amount' => null,
            'people_count' => null,
            'product_type' => null,
            'must_have' => [],
            'avoid' => [],
        ];
    }

    /**
     * @param array<string, mixed> $known
     */
    public static function assertCapabilityKnownEntitiesComplete(array $known): void
    {
        $relation = isset($known['destination_relation']) ? trim((string) $known['destination_relation']) : '';
        if (!in_array($relation, [
            DestinationFeasibilityContracts::RELATION_AND,
            DestinationFeasibilityContracts::RELATION_OR,
            DestinationFeasibilityContracts::RELATION_SEQUENTIAL,
        ], true)) {
            throw new \InvalidArgumentException('capability resume requires multi-destination relation');
        }

        $semantics = isset($known['destination_semantics']) && is_array($known['destination_semantics'])
            ? $known['destination_semantics']
            : [];
        if ($semantics === []) {
            throw new \InvalidArgumentException('capability resume requires destination_semantics');
        }
        $projection = DestinationFeasibilityContracts::roleAwareDestinationLabels($semantics);
        if (!$projection['ok'] || $projection['labels'] === []) {
            throw new \InvalidArgumentException('capability resume destination_semantics invalid');
        }
        $destination = isset($known['destination']) && is_array($known['destination'])
            ? $known['destination']
            : [];
        if (!DestinationFeasibilityContracts::destinationLabelSetsEqual($destination, $projection['labels'])) {
            throw new \InvalidArgumentException('capability resume destination parity failed');
        }

        $dateFrom = isset($known['date_from']) ? trim((string) $known['date_from']) : '';
        $dateTo = isset($known['date_to']) ? trim((string) $known['date_to']) : '';
        if ($dateFrom === '' || $dateTo === '') {
            throw new \InvalidArgumentException('capability resume requires date_from and date_to');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new \InvalidArgumentException('capability resume dates invalid');
        }
    }

    /**
     * @param array<string, mixed> $known
     */
    private static function assertReasonContract(
        string $status,
        string $resumeReason,
        string $trigger,
        string $asked,
        string $responseRoute,
        string $aiuReason,
        string $relationCapabilityVersion,
        array $known
    ): void {
        if ($status === StructuredSearchResumeState::STATUS_WAITING_SINGLE_DESTINATION) {
            if ($resumeReason !== StructuredSearchResumeState::RESUME_REASON_RELATION_CAPABILITY_UNAVAILABLE) {
                throw new \InvalidArgumentException('resume state capability reason invalid');
            }
            if ($trigger !== StructuredSearchResumeState::TRIGGER_DESTINATION_EXECUTION_GATE) {
                throw new \InvalidArgumentException('resume state capability trigger invalid');
            }
            if ($asked !== 'destination') {
                throw new \InvalidArgumentException('resume state capability asked_entity invalid');
            }
            if ($responseRoute !== StructuredSearchResumeState::RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE) {
                throw new \InvalidArgumentException('resume state capability response_route invalid');
            }
            if ($aiuReason !== '') {
                throw new \InvalidArgumentException('resume state capability aiu_clarification_reason must be empty');
            }
            if ($relationCapabilityVersion === '') {
                throw new \InvalidArgumentException('resume state capability relation_capability_version required');
            }
            self::assertCapabilityKnownEntitiesComplete($known);

            return;
        }

        if ($status === StructuredSearchResumeState::STATUS_WAITING_CLARIFICATION) {
            if ($resumeReason !== StructuredSearchResumeState::RESUME_REASON_AIU_PRODUCT_CLARIFICATION) {
                throw new \InvalidArgumentException('resume state aiu reason invalid');
            }
            if ($trigger !== StructuredSearchResumeState::TRIGGER_AIU_CLARIFICATION) {
                throw new \InvalidArgumentException('resume state aiu trigger invalid');
            }
            if ($responseRoute !== StructuredSearchResumeState::RESPONSE_ROUTE_AIU_CLARIFICATION) {
                throw new \InvalidArgumentException('resume state aiu response_route invalid');
            }
            if ($relationCapabilityVersion !== '') {
                throw new \InvalidArgumentException('resume state aiu relation_capability_version must be empty');
            }
            $expectedAsked = AiuClarificationReasonContract::missingEntityForAiuReason($aiuReason);
            if ($asked !== $expectedAsked) {
                throw new \InvalidArgumentException('resume state asked_entity inconsistent with aiu reason');
            }

            return;
        }

        throw new \InvalidArgumentException('resume state status unsupported');
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private static function normalizeKnownEntities($raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('resume state known_entities must be object');
        }
        if ($raw !== [] && array_keys($raw) === range(0, count($raw) - 1)) {
            throw new \InvalidArgumentException('resume state known_entities must be object');
        }

        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, StructuredSearchResumeState::KNOWN_ENTITY_KEYS, true)) {
                throw new \InvalidArgumentException('resume state known_entities unknown key: ' . $key);
            }
        }

        $out = self::emptyKnownEntities();
        $out['destination'] = self::stringList($raw['destination'] ?? []);
        $out['destination_relation'] = self::nullableString($raw['destination_relation'] ?? null);
        $out['destination_semantics'] = self::semanticsList($raw['destination_semantics'] ?? []);
        $out['departure'] = self::nullableString($raw['departure'] ?? null);
        $out['date_from'] = self::nullableString($raw['date_from'] ?? null);
        $out['date_to'] = self::nullableString($raw['date_to'] ?? null);
        $out['date_expression'] = self::nullableString($raw['date_expression'] ?? null);
        $out['duration_days'] = self::nullableInt($raw['duration_days'] ?? null);
        $out['budget_amount'] = self::nullableNumber($raw['budget_amount'] ?? null);
        $out['people_count'] = self::nullableInt($raw['people_count'] ?? null);
        $out['product_type'] = self::nullableString($raw['product_type'] ?? null);
        $out['must_have'] = self::stringList($raw['must_have'] ?? []);
        $out['avoid'] = self::stringList($raw['avoid'] ?? []);

        return $out;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function assertDocumentShape(array $document): void
    {
        foreach (self::DOCUMENT_REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $document)) {
                throw new \InvalidArgumentException('resume state missing key: ' . $key);
            }
        }
        foreach (array_keys($document) as $key) {
            if (!in_array((string) $key, self::DOCUMENT_REQUIRED_KEYS, true)) {
                throw new \InvalidArgumentException('resume state unknown key: ' . $key);
            }
        }
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function semanticsList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value) || is_numeric($value)) {
            $s = trim((string) $value);

            return $s === '' ? [] : [$s];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $value
     */
    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    /**
     * @param mixed $value
     */
    private static function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param mixed $value
     * @return int|float|null
     */
    private static function nullableNumber($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? 0 + $value : null;
    }
}
