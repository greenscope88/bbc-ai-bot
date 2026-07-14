<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';

/**
 * B0: destination[] execution projection + direct v2 entity field copy only (B2 deferred).
 */
final class AiuProductIntentTranslator
{
    /** @var array<string, string> */
    private const REASON_VOCAB_MAP = [
        'missing_travel_dates' => ClarificationPolicy::REASON_DATE_REQUIRED,
        'date_required' => ClarificationPolicy::REASON_DATE_REQUIRED,
        'missing_destination' => ClarificationPolicy::REASON_DESTINATION_UNKNOWN,
        'destination_unknown' => ClarificationPolicy::REASON_DESTINATION_UNKNOWN,
    ];

    public function translate(AiIntentUnderstandingResult $result): BatsSearchIntent
    {
        $entities = $result->getEntities();

        $destination = $this->destinationList($entities['destination'] ?? null);
        $clarificationRequired = $result->isClarificationRequired();
        $clarificationReason = $this->translateReason(
            $result->getClarificationReason(),
            $destination !== [] ? $destination[0] : null,
            $clarificationRequired
        );

        $duration = null;
        if (isset($entities['duration_days']) && is_numeric($entities['duration_days'])) {
            $duration = (string) ((int) $entities['duration_days']);
        }

        $budgetAmount = $this->nullableNumber($entities['budget_amount'] ?? null);

        return new BatsSearchIntent(
            '',
            BatsSearchIntent::INTENT_TOUR_SEARCH,
            $destination,
            [],
            $this->nullableString($entities['departure'] ?? null),
            $this->nullableString($entities['date_from'] ?? null),
            $this->nullableString($entities['date_to'] ?? null),
            null,
            $budgetAmount !== null ? (int) $budgetAmount : null,
            $this->nullableInt($entities['people_count'] ?? null),
            null,
            $duration,
            $this->nullableString($entities['product_type'] ?? null),
            null,
            [],
            [],
            $clarificationRequired,
            $clarificationReason,
            $result->getConfidence()
        );
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

    private function translateReason(string $semanticReason, ?string $destination, bool $clarificationRequired): ?string
    {
        if (!$clarificationRequired) {
            return null;
        }

        $reason = trim($semanticReason);
        if ($reason !== '' && isset(self::REASON_VOCAB_MAP[$reason])) {
            return self::REASON_VOCAB_MAP[$reason];
        }

        if ($destination === null) {
            return ClarificationPolicy::REASON_DESTINATION_UNKNOWN;
        }

        return ClarificationPolicy::REASON_DATE_REQUIRED;
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
}
