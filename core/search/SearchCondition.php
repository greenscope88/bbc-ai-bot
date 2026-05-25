<?php
declare(strict_types=1);

/**
 * Hybrid Smart Search — canonical search condition (Phase 2-A core DTO).
 * Not wired to production flow in this phase.
 */
final class SearchCondition
{
    public const INTENT_TOUR_SEARCH = 'tour_search';

    /** @var string */
    private $intent;

    /** @var string|null */
    private $keyword;

    /** @var string|null */
    private $area;

    /** @var string|null */
    private $destination;

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

    /** @var list<string> */
    private $travel_style;

    /** @var list<string> */
    private $special_tags;

    /** @var string|null */
    private $free_text;

    /** @var float */
    private $confidence;

    /** @var array<string, bool> */
    private $parser_flags;

    /** @var string|null */
    private $date_precision;

    /** @var string|null */
    private $date_label;

    /**
     * @param list<string> $travel_style
     * @param list<string> $special_tags
     * @param array<string, bool> $parser_flags
     */
    public function __construct(
        string $intent = self::INTENT_TOUR_SEARCH,
        ?string $keyword = null,
        ?string $area = null,
        ?string $destination = null,
        ?string $departure_city = null,
        ?string $date_from = null,
        ?string $date_to = null,
        ?int $budget_min = null,
        ?int $budget_max = null,
        array $travel_style = [],
        array $special_tags = [],
        ?string $free_text = null,
        float $confidence = 0.0,
        array $parser_flags = [],
        ?string $date_precision = null,
        ?string $date_label = null
    ) {
        $this->intent = $intent;
        $this->keyword = $keyword;
        $this->area = $area;
        $this->destination = $destination;
        $this->departure_city = $departure_city;
        $this->date_from = $date_from;
        $this->date_to = $date_to;
        $this->budget_min = $budget_min;
        $this->budget_max = $budget_max;
        $this->travel_style = $travel_style;
        $this->special_tags = $special_tags;
        $this->free_text = $free_text;
        $this->confidence = $confidence;
        $this->parser_flags = $parser_flags;
        $this->date_precision = $date_precision;
        $this->date_label = $date_label;
    }

    public static function empty(?string $freeText = null): self
    {
        $text = $freeText !== null ? trim($freeText) : null;
        if ($text === '') {
            $text = null;
        }

        return new self(self::INTENT_TOUR_SEARCH, null, null, null, null, null, null, null, null, [], [], $text);
    }

    public function getIntent(): string
    {
        return $this->intent;
    }

    public function getKeyword(): ?string
    {
        return $this->keyword;
    }

    public function getArea(): ?string
    {
        return $this->area;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
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

    /** @return list<string> */
    public function getTravelStyle(): array
    {
        return $this->travel_style;
    }

    /** @return list<string> */
    public function getSpecialTags(): array
    {
        return $this->special_tags;
    }

    public function getFreeText(): ?string
    {
        return $this->free_text;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    /** @return array<string, bool> */
    public function getParserFlags(): array
    {
        return $this->parser_flags;
    }

    public function getDatePrecision(): ?string
    {
        return $this->date_precision;
    }

    public function getDateLabel(): ?string
    {
        return $this->date_label;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function with(array $patch): self
    {
        return new self(
            isset($patch['intent']) && is_string($patch['intent']) ? $patch['intent'] : $this->intent,
            array_key_exists('keyword', $patch) ? self::nullableString($patch['keyword']) : $this->keyword,
            array_key_exists('area', $patch) ? self::nullableString($patch['area']) : $this->area,
            array_key_exists('destination', $patch) ? self::nullableString($patch['destination']) : $this->destination,
            array_key_exists('departure_city', $patch)
                ? self::nullableString($patch['departure_city'])
                : $this->departure_city,
            array_key_exists('date_from', $patch) ? self::nullableString($patch['date_from']) : $this->date_from,
            array_key_exists('date_to', $patch) ? self::nullableString($patch['date_to']) : $this->date_to,
            array_key_exists('budget_min', $patch) ? self::nullableInt($patch['budget_min']) : $this->budget_min,
            array_key_exists('budget_max', $patch) ? self::nullableInt($patch['budget_max']) : $this->budget_max,
            array_key_exists('travel_style', $patch) ? self::stringList($patch['travel_style']) : $this->travel_style,
            array_key_exists('special_tags', $patch) ? self::stringList($patch['special_tags']) : $this->special_tags,
            array_key_exists('free_text', $patch) ? self::nullableString($patch['free_text']) : $this->free_text,
            isset($patch['confidence']) ? (float) $patch['confidence'] : $this->confidence,
            array_key_exists('parser_flags', $patch)
                ? self::mergeFlags($this->parser_flags, $patch['parser_flags'])
                : $this->parser_flags,
            array_key_exists('date_precision', $patch)
                ? self::nullableString($patch['date_precision'])
                : $this->date_precision,
            array_key_exists('date_label', $patch) ? self::nullableString($patch['date_label']) : $this->date_label
        );
    }

    public function flag(string $name, bool $value = true): self
    {
        $flags = $this->parser_flags;
        $flags[$name] = $value;

        return $this->with(['parser_flags' => $flags]);
    }

    public function bumpConfidence(float $delta): self
    {
        return $this->with(['confidence' => min(0.99, $this->confidence + $delta)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'keyword' => $this->keyword,
            'area' => $this->area,
            'destination' => $this->destination,
            'departure_city' => $this->departure_city,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'travel_style' => $this->travel_style,
            'special_tags' => $this->special_tags,
            'free_text' => $this->free_text,
            'confidence' => round($this->confidence, 2),
            'parser_flags' => $this->parser_flags,
            'date_precision' => $this->date_precision,
            'date_label' => $this->date_label,
        ];
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

    /**
     * @param array<string, bool> $base
     * @param mixed $patch
     * @return array<string, bool>
     */
    private static function mergeFlags(array $base, $patch): array
    {
        if (!is_array($patch)) {
            return $base;
        }

        foreach ($patch as $k => $v) {
            if (is_string($k)) {
                $base[$k] = (bool) $v;
            }
        }

        return $base;
    }
}
