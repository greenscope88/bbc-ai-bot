<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';

/**
 * Phase 2-D Step 2-D-4 — Semantic JSON v1.0 Normalizer（§16.6 / §16.7）.
 */
final class AiuSemanticJsonNormalizer
{
    /** @var list<string> */
    private const PRODUCT_TYPE_TOKENS = [
        '自由行',
        '跟團',
        '半自助',
        '包車',
        '郵輪',
        '團體',
        '迷你團',
    ];

    /**
     * @param array{
     *   intent: string,
     *   entity: array<string, mixed>,
     *   confidence: float,
     *   clarification: array{required: bool, reason: string},
     *   semantic_notes?: string
     * } $semantic
     *
     * @return array{
     *   intent: string,
     *   entity: array<string, mixed>,
     *   clarification_required: bool,
     *   clarification_reason: string,
     *   confidence: float
     * }
     */
    public function normalize(array $semantic, string $customerUtterance): array
    {
        $intent = $this->normalizeIntent((string) ($semantic['intent'] ?? ''));
        $entity = $this->normalizeEntity(
            isset($semantic['entity']) && is_array($semantic['entity']) ? $semantic['entity'] : [],
            $customerUtterance,
            $intent
        );
        $clarification = isset($semantic['clarification']) && is_array($semantic['clarification'])
            ? $semantic['clarification']
            : [];
        $clarificationRequired = (bool) ($clarification['required'] ?? false);
        $clarificationReason = trim((string) ($clarification['reason'] ?? ''));
        $confidence = isset($semantic['confidence']) ? (float) $semantic['confidence'] : 0.0;

        if ($intent === AiIntentCategory::AMBIGUOUS) {
            $clarificationRequired = true;
            if ($clarificationReason === '') {
                $clarificationReason = 'intent_ambiguous';
            }
        }

        return [
            'intent' => $intent,
            'entity' => $entity,
            'clarification_required' => $clarificationRequired,
            'clarification_reason' => $clarificationReason,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    private function normalizeIntent(string $intent): string
    {
        $intent = trim($intent);
        if (strcasecmp($intent, 'Product Search') === 0 || strcasecmp($intent, 'product_search') === 0) {
            return AiIntentCategory::PRODUCT_SEARCH;
        }
        if (strcasecmp($intent, 'Knowledge') === 0 || strcasecmp($intent, 'knowledge') === 0) {
            return AiIntentCategory::KNOWLEDGE;
        }
        if (strcasecmp($intent, 'Ambiguous') === 0 || strcasecmp($intent, 'ambiguous') === 0) {
            return AiIntentCategory::AMBIGUOUS;
        }

        throw new \InvalidArgumentException('invalid semantic intent: ' . $intent);
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function normalizeEntity(array $entity, string $customerUtterance, string $intent): array
    {
        if ($intent !== AiIntentCategory::PRODUCT_SEARCH) {
            if ($this->isHumanServiceEntity($entity)) {
                return [
                    'human_service_request' => true,
                    'free_text' => $customerUtterance,
                ];
            }

            return [];
        }

        $freeText = trim((string) ($entity['free_text'] ?? $customerUtterance));
        $destination = isset($entity['destination']) ? trim((string) $entity['destination']) : null;
        if ($destination === '') {
            $destination = null;
        }

        $peopleCount = $this->nullableInt($entity['people_count'] ?? null);
        $peopleLabel = $this->nullableString($entity['people_label'] ?? null);
        if ($peopleLabel === null && isset($entity['people']) && is_scalar($entity['people'])) {
            $peopleRaw = trim((string) $entity['people']);
            if ($peopleRaw !== '') {
                if (is_numeric($peopleRaw)) {
                    $peopleCount = (int) $peopleRaw;
                } else {
                    $peopleLabel = $peopleRaw;
                    $peopleCount = null;
                }
            }
        }
        if (
            $peopleCount === null
            && $peopleLabel === null
            && isset($entity['people_count'])
            && $entity['people_count'] !== null
            && $entity['people_count'] !== ''
            && !is_numeric($entity['people_count'])
        ) {
            $peopleLabel = trim((string) $entity['people_count']);
        }

        $travelType = $this->stringList($entity['travel_type'] ?? []);
        $productType = $this->nullableString($entity['product_type'] ?? null);
        if ($productType === null) {
            foreach ($travelType as $token) {
                if ($this->isProductTypeToken($token)) {
                    $productType = $token;
                    break;
                }
            }
        }

        return [
            'intent' => BatsSearchIntent::INTENT_TOUR_SEARCH,
            'destination' => $destination,
            'destination_alias' => $this->stringList($entity['destination_alias'] ?? []),
            'multi_destination' => $this->stringList($entity['multi_destination'] ?? []),
            'departure_city' => $this->nullableString($entity['departure_city'] ?? null),
            'date_from' => $this->nullableString($entity['date_from'] ?? null),
            'date_to' => $this->nullableString($entity['date_to'] ?? null),
            'travel_type' => $travelType,
            'budget_min' => $this->nullableInt($entity['budget_min'] ?? null),
            'budget_max' => $this->nullableInt($entity['budget_max'] ?? null),
            'people_count' => $peopleCount,
            'people_label' => $peopleLabel,
            'duration' => $this->nullableString($entity['duration'] ?? null),
            'product_type' => $productType,
            'landmark' => $this->nullableString($entity['landmark'] ?? null),
            'must_have' => $this->stringList($entity['must_have'] ?? []),
            'avoid' => $this->stringList($entity['avoid'] ?? []),
            'clarification_required' => (bool) ($entity['clarification_required'] ?? false),
            'clarification_reason' => $this->nullableString($entity['clarification_reason'] ?? null),
            'confidence' => isset($entity['confidence']) ? (float) $entity['confidence'] : 0.0,
            'free_text' => $freeText,
            'human_service_request' => $this->isHumanServiceEntity($entity),
        ];
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
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
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
     * @param array<string, mixed> $entity
     */
    private function isHumanServiceEntity(array $entity): bool
    {
        return ($entity['human_service_request'] ?? false) === true;
    }

    private function isProductTypeToken(string $token): bool
    {
        return in_array(trim($token), self::PRODUCT_TYPE_TOKENS, true);
    }
}
