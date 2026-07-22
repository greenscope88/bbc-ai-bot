<?php
declare(strict_types=1);

/**
 * Phase 9-C-1 semantic intent DTO (BATS_AI_SEMANTIC_SEARCH.md §3).
 */
final class BatsSearchIntent
{
    public const INTENT_TOUR_SEARCH = 'tour_search';

    /** @var string */
    private $intent;

    /** @var list<string> */
    private $destination;

    /** @var list<string> */
    private $destination_alias;

    /** @var string|null */
    private $departure_city;

    /** @var string|null */
    private $date_from;

    /** @var string|null */
    private $date_to;

    /** @var int|null */
    private $budget_min;

    /** @var int|null */
    private $budget_max;

    /** @var int|null */
    private $people_count;

    /** @var string|null */
    private $people_label;

    /** @var string|null */
    private $duration;

    /** @var string|null */
    private $product_type;

    /** @var string|null */
    private $landmark;

    /** @var list<string> */
    private $must_have;

    /** @var list<string> */
    private $avoid;

    /** @var bool */
    private $clarification_required;

    /** @var string|null */
    private $clarification_reason;

    /** @var float */
    private $confidence;

    /** @var string */
    private $free_text;

    /** @var string */
    private $destination_relation;

    /** @var list<array<string, mixed>> */
    private $destination_semantics;

    /**
     * @param list<string> $destination
     * @param list<string> $destination_alias
     * @param list<string> $must_have
     * @param list<string> $avoid
     * @param list<array<string, mixed>> $destination_semantics
     */
    public function __construct(
        string $free_text,
        string $intent = self::INTENT_TOUR_SEARCH,
        array $destination = [],
        array $destination_alias = [],
        ?string $departure_city = null,
        ?string $date_from = null,
        ?string $date_to = null,
        ?int $budget_min = null,
        ?int $budget_max = null,
        ?int $people_count = null,
        ?string $people_label = null,
        ?string $duration = null,
        ?string $product_type = null,
        ?string $landmark = null,
        array $must_have = [],
        array $avoid = [],
        bool $clarification_required = false,
        ?string $clarification_reason = null,
        float $confidence = 0.0,
        string $destination_relation = '',
        array $destination_semantics = []
    ) {
        $this->free_text = trim($free_text);
        $this->intent = $intent;
        $this->destination = self::stringList($destination);
        $this->destination_alias = self::stringList($destination_alias);
        $this->departure_city = self::nullableString($departure_city);
        $this->date_from = self::nullableString($date_from);
        $this->date_to = self::nullableString($date_to);
        $this->budget_min = $budget_min;
        $this->budget_max = $budget_max;
        $this->people_count = $people_count;
        $this->people_label = self::nullableString($people_label);
        $this->duration = self::nullableString($duration);
        $this->product_type = self::nullableString($product_type);
        $this->landmark = self::nullableString($landmark);
        $this->must_have = self::stringList($must_have);
        $this->avoid = self::stringList($avoid);
        $this->clarification_required = $clarification_required;
        $this->clarification_reason = self::nullableString($clarification_reason);
        $this->confidence = max(0.0, min(1.0, $confidence));
        $this->destination_relation = trim($destination_relation);
        $this->destination_semantics = self::semanticsList($destination_semantics);
    }

    public static function empty(string $freeText): self
    {
        return new self($freeText);
    }

    public function getIntent(): string
    {
        return $this->intent;
    }

    /** @return list<string> */
    public function getDestination(): array
    {
        return $this->destination;
    }

    /** @return list<string> */
    public function getDestinationAlias(): array
    {
        return $this->destination_alias;
    }

    public function getDepartureCity(): ?string
    {
        return $this->departure_city;
    }

    public function getDateFrom(): ?string
    {
        return $this->date_from;
    }

    public function getDateTo(): ?string
    {
        return $this->date_to;
    }

    public function getBudgetMin(): ?int
    {
        return $this->budget_min;
    }

    public function getBudgetMax(): ?int
    {
        return $this->budget_max;
    }

    public function getPeopleCount(): ?int
    {
        return $this->people_count;
    }

    public function getPeopleLabel(): ?string
    {
        return $this->people_label;
    }

    public function getDuration(): ?string
    {
        return $this->duration;
    }

    public function getProductType(): ?string
    {
        return $this->product_type;
    }

    public function getLandmark(): ?string
    {
        return $this->landmark;
    }

    /** @return list<string> */
    public function getMustHave(): array
    {
        return $this->must_have;
    }

    /** @return list<string> */
    public function getAvoid(): array
    {
        return $this->avoid;
    }

    public function isClarificationRequired(): bool
    {
        return $this->clarification_required;
    }

    public function getClarificationReason(): ?string
    {
        return $this->clarification_reason;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getFreeText(): string
    {
        return $this->free_text;
    }

    public function getDestinationRelation(): string
    {
        return $this->destination_relation;
    }

    /** @return list<array<string, mixed>> */
    public function getDestinationSemantics(): array
    {
        return $this->destination_semantics;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function with(array $patch): self
    {
        return new self(
            array_key_exists('free_text', $patch) ? (string) $patch['free_text'] : $this->free_text,
            isset($patch['intent']) && is_string($patch['intent']) ? $patch['intent'] : $this->intent,
            array_key_exists('destination', $patch) ? self::stringList($patch['destination']) : $this->destination,
            array_key_exists('destination_alias', $patch)
                ? self::stringList($patch['destination_alias'])
                : $this->destination_alias,
            array_key_exists('departure_city', $patch)
                ? self::nullableString($patch['departure_city'])
                : $this->departure_city,
            array_key_exists('date_from', $patch) ? self::nullableString($patch['date_from']) : $this->date_from,
            array_key_exists('date_to', $patch) ? self::nullableString($patch['date_to']) : $this->date_to,
            array_key_exists('budget_min', $patch) ? self::nullableInt($patch['budget_min']) : $this->budget_min,
            array_key_exists('budget_max', $patch) ? self::nullableInt($patch['budget_max']) : $this->budget_max,
            array_key_exists('people_count', $patch)
                ? self::nullableInt($patch['people_count'])
                : $this->people_count,
            array_key_exists('people_label', $patch)
                ? self::nullableString($patch['people_label'])
                : $this->people_label,
            array_key_exists('duration', $patch) ? self::nullableString($patch['duration']) : $this->duration,
            array_key_exists('product_type', $patch)
                ? self::nullableString($patch['product_type'])
                : $this->product_type,
            array_key_exists('landmark', $patch) ? self::nullableString($patch['landmark']) : $this->landmark,
            array_key_exists('must_have', $patch) ? self::stringList($patch['must_have']) : $this->must_have,
            array_key_exists('avoid', $patch) ? self::stringList($patch['avoid']) : $this->avoid,
            array_key_exists('clarification_required', $patch)
                ? (bool) $patch['clarification_required']
                : $this->clarification_required,
            array_key_exists('clarification_reason', $patch)
                ? self::nullableString($patch['clarification_reason'])
                : $this->clarification_reason,
            isset($patch['confidence']) ? (float) $patch['confidence'] : $this->confidence,
            array_key_exists('destination_relation', $patch)
                ? (string) $patch['destination_relation']
                : $this->destination_relation,
            array_key_exists('destination_semantics', $patch)
                ? self::semanticsList($patch['destination_semantics'])
                : $this->destination_semantics
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'destination' => $this->destination,
            'destination_alias' => $this->destination_alias,
            'departure_city' => $this->departure_city,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'people_count' => $this->people_count,
            'people_label' => $this->people_label,
            'duration' => $this->duration,
            'product_type' => $this->product_type,
            'landmark' => $this->landmark,
            'must_have' => $this->must_have,
            'avoid' => $this->avoid,
            'clarification_required' => $this->clarification_required,
            'clarification_reason' => $this->clarification_reason,
            'confidence' => round($this->confidence, 2),
            'free_text' => $this->free_text,
            'destination_relation' => $this->destination_relation,
            'destination_semantics' => $this->destination_semantics,
        ];
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

        return (int) $value;
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
        foreach ($value as $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }
}
