<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuDateEntityResolver.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuClarificationReasonContract.php';

/**
 * AIU v2 Normalize — Translation Layer.
 *
 * SSOT: docs/BATS_AI_NORMALIZE.md (Frozen) — Gap B0-1～B0-5.
 * Does NOT produce dispatch_plan / execution_hint / semantic_notes.
 */
final class AiuSemanticJsonNormalizer
{
    /** @var list<string> */
    private const FROZEN_SCALAR_KEYS = [
        'travel_area',
        'date_from',
        'date_to',
        'date_expression',
        'duration_days',
        'date_flexibility',
        'product_type',
        'occasion',
        'travel_style',
        'departure',
        'people_count',
        'adult_count',
        'child_count',
        'senior_count',
        'group_type',
        'budget_amount',
        'budget_unit',
        'currency',
        'price_sensitivity',
    ];

    /** @var list<string> */
    private const FROZEN_ARRAY_KEYS = [
        'destination',
        'theme',
        'route',
        'hotel_preference',
        'transportation_preference',
        'airline_preference',
        'meal_preference',
        'room_preference',
        'constraint',
        'exclusion',
        'special_need',
        'preserved_keywords',
        'unclassified_terms',
        'must_have',
        'avoid',
    ];

    private AiuDateEntityResolver $dateEntityResolver;

    public function __construct(?AiuDateEntityResolver $dateEntityResolver = null)
    {
        $this->dateEntityResolver = $dateEntityResolver ?? new AiuDateEntityResolver();
    }

    /**
     * @param array<string, mixed> $semantic
     * @return array{
     *   intent: string,
     *   entities: array<string, mixed>,
     *   clarification_required: bool,
     *   clarification_reason: string,
     *   confidence: float
     * }
     */
    public function normalize(
        array $semantic,
        string $customerUtterance,
        ?\DateTimeImmutable $referenceDate = null
    ): array {
        unset($customerUtterance);

        $intent = $this->normalizeIntent((string) ($semantic['intent'] ?? ''));
        $rawEntities = $this->extractEntitiesMap($semantic);
        $entities = $this->normalizeEntities($rawEntities);

        $clarification = isset($semantic['clarification']) && is_array($semantic['clarification'])
            ? $semantic['clarification']
            : [];
        $clarificationRequired = (bool) ($clarification['required'] ?? false);
        $clarificationReason = trim((string) ($clarification['reason'] ?? ''));
        $confidence = isset($semantic['confidence']) ? (float) $semantic['confidence'] : 0.0;

        if ($intent === AiIntentCategory::PRODUCT_SEARCH) {
            $dateResolved = $this->dateEntityResolver->resolve(
                $entities,
                '',
                $clarificationRequired,
                $clarificationReason,
                $referenceDate
            );
            $entities = $dateResolved['entities'];
            $clarificationRequired = $dateResolved['clarification_required'];
            $clarificationReason = $dateResolved['clarification_reason'];

            // Closed Product Search reason vocabulary: validate only; never repair/coerce.
            AiuClarificationReasonContract::assertValidProductSearchClarification(
                $clarificationRequired,
                $clarificationReason,
                $entities
            );
        }

        if ($intent === AiIntentCategory::AMBIGUOUS) {
            $clarificationRequired = true;
            if ($clarificationReason === '') {
                $clarificationReason = 'intent_ambiguous';
            }
        }

        return [
            'intent' => $intent,
            'entities' => $entities,
            'clarification_required' => $clarificationRequired,
            'clarification_reason' => $clarificationReason,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    private function normalizeIntent(string $intent): string
    {
        $intent = trim($intent);
        if (
            strcasecmp($intent, 'Product Search') === 0
            || strcasecmp($intent, 'product_search') === 0
        ) {
            return AiIntentCategory::PRODUCT_SEARCH;
        }
        if (strcasecmp($intent, 'Knowledge') === 0 || strcasecmp($intent, 'knowledge') === 0) {
            return AiIntentCategory::KNOWLEDGE;
        }
        if (
            strcasecmp($intent, 'human_service') === 0
            || strcasecmp($intent, 'Human Service') === 0
        ) {
            return AiIntentCategory::HUMAN_SERVICE;
        }
        if (strcasecmp($intent, 'Ambiguous') === 0 || strcasecmp($intent, 'ambiguous') === 0) {
            return AiIntentCategory::AMBIGUOUS;
        }

        throw new \InvalidArgumentException('invalid semantic intent: ' . $intent);
    }

    /**
     * @param array<string, mixed> $semantic
     * @return array<string, mixed>
     */
    private function extractEntitiesMap(array $semantic): array
    {
        if (isset($semantic['entities']) && is_array($semantic['entities'])) {
            return $semantic['entities'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeEntities(array $raw): array
    {
        $out = [];
        foreach (self::FROZEN_ARRAY_KEYS as $key) {
            $out[$key] = [];
        }
        foreach (self::FROZEN_SCALAR_KEYS as $key) {
            $out[$key] = null;
        }

        // B0-4: destination[] preserved as array — never split to primary.
        $out['destination'] = $this->destinationList($raw['destination'] ?? null);

        foreach (self::FROZEN_SCALAR_KEYS as $key) {
            if ($key === 'date_from' || $key === 'date_to') {
                continue;
            }
            if ($key === 'people_count' || $key === 'adult_count' || $key === 'child_count'
                || $key === 'senior_count' || $key === 'duration_days') {
                $out[$key] = $this->nullableInt($raw[$key] ?? null);
                continue;
            }
            if ($key === 'budget_amount') {
                $out[$key] = $this->nullableNumber($raw[$key] ?? null);
                continue;
            }
            $out[$key] = $this->nullableString($raw[$key] ?? null);
        }

        foreach (self::FROZEN_ARRAY_KEYS as $key) {
            if ($key === 'destination' || $key === 'must_have' || $key === 'avoid') {
                continue;
            }
            $out[$key] = $this->stringList($raw[$key] ?? []);
        }

        // Frozen §7.6 — semantic-equivalent projection only.
        $out['must_have'] = $this->stringList(
            !empty($raw['must_have']) ? $raw['must_have'] : ($raw['constraint'] ?? [])
        );
        $out['avoid'] = $this->stringList(
            !empty($raw['avoid']) ? $raw['avoid'] : ($raw['exclusion'] ?? [])
        );

        // Frozen §7.3 — date_range flatten only; no re-inference.
        $dateRange = $raw['date_range'] ?? null;
        if (is_array($dateRange)) {
            $out['date_from'] = $this->nullableString($dateRange['from'] ?? null);
            $out['date_to'] = $this->nullableString($dateRange['to'] ?? null);
        } else {
            $out['date_from'] = $this->nullableString($raw['date_from'] ?? null);
            $out['date_to'] = $this->nullableString($raw['date_to'] ?? null);
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function destinationList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value) || is_numeric($value)) {
            $s = trim((string) $value);

            return $s === '' ? [] : [$s];
        }

        return $this->stringList($value);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList($value): array
    {
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
    private function nullableString($value): ?string
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
    private function nullableInt($value): ?int
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
    private function nullableNumber($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? 0 + $value : null;
    }
}
