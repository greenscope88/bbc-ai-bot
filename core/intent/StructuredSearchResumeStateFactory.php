<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeIdentity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuClarificationReasonContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';

/**
 * Builds / validates Structured Search Resume State documents (schema v1).
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
        'known_entities',
        'clarification_required',
        'aiu_clarification_reason',
        'missing_entity',
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

        $known = self::normalizeKnownEntities($document['known_entities']);
        $reason = trim((string) $document['aiu_clarification_reason']);
        $missing = trim((string) $document['missing_entity']);
        $expectedMissing = AiuClarificationReasonContract::missingEntityForAiuReason($reason);
        if ($missing !== $expectedMissing) {
            throw new \InvalidArgumentException('resume state missing_entity inconsistent with aiu reason');
        }

        $op = trim((string) $document['last_event_operation']);
        if ($op !== StructuredSearchResumeState::OP_CREATE && $op !== StructuredSearchResumeState::OP_REPLACE) {
            throw new \InvalidArgumentException('resume state last_event_operation invalid');
        }

        if ((int) $document['schema_version'] !== StructuredSearchResumeState::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('resume state schema_version unsupported');
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
            $known,
            $reason,
            $missing,
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
        $missing = AiuClarificationReasonContract::missingEntityForAiuReason($reason);

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
            self::knownEntitiesFromAiuEntities($result->getEntities()),
            $reason,
            $missing,
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
        $out['departure'] = self::nullableString($aiuEntities['departure'] ?? null);
        $out['date_from'] = self::nullableString($aiuEntities['date_from'] ?? null);
        $out['date_to'] = self::nullableString($aiuEntities['date_to'] ?? null);
        $out['duration_days'] = self::nullableInt($aiuEntities['duration_days'] ?? null);
        $out['budget_amount'] = self::nullableNumber($aiuEntities['budget_amount'] ?? null);
        $out['people_count'] = self::nullableInt($aiuEntities['people_count'] ?? null);
        $out['product_type'] = self::nullableString($aiuEntities['product_type'] ?? null);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyKnownEntities(): array
    {
        return [
            'destination' => [],
            'departure' => null,
            'date_from' => null,
            'date_to' => null,
            'duration_days' => null,
            'budget_amount' => null,
            'people_count' => null,
            'product_type' => null,
        ];
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
        $out['departure'] = self::nullableString($raw['departure'] ?? null);
        $out['date_from'] = self::nullableString($raw['date_from'] ?? null);
        $out['date_to'] = self::nullableString($raw['date_to'] ?? null);
        $out['duration_days'] = self::nullableInt($raw['duration_days'] ?? null);
        $out['budget_amount'] = self::nullableNumber($raw['budget_amount'] ?? null);
        $out['people_count'] = self::nullableInt($raw['people_count'] ?? null);
        $out['product_type'] = self::nullableString($raw['product_type'] ?? null);

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
