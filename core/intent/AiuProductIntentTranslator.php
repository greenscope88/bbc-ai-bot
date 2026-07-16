<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuClarificationReasonContract.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';

/**
 * B0: destination[] execution projection + direct v2 entity field copy only (B2 deferred).
 *
 * Clarification reasons: map closed AIU vocabulary only; never infer from entities/utterance.
 */
final class AiuProductIntentTranslator
{
    public function translate(AiIntentUnderstandingResult $result): BatsSearchIntent
    {
        $entities = $result->getEntities();

        $destination = $this->destinationList($entities['destination'] ?? null);
        $clarificationRequired = $result->isClarificationRequired();
        // Closed AIU→Runtime reason map is Product Search only.
        // Non-product AIU results must not fail-closed here; clear Product clarification
        // fields so Ambiguous/Knowledge keep prior Router/service branching.
        if ($result->getIntent() === AiIntentCategory::PRODUCT_SEARCH) {
            $clarificationReason = $this->translateReason(
                $result->getClarificationReason(),
                $clarificationRequired
            );
        } else {
            $clarificationRequired = false;
            $clarificationReason = null;
        }

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

    /**
     * Map closed AIU reason → Runtime reason. No entity fallback; no Runtime aliases as input.
     *
     * @throws \InvalidArgumentException
     */
    private function translateReason(string $semanticReason, bool $clarificationRequired): ?string
    {
        if (!$clarificationRequired) {
            return null;
        }

        return AiuClarificationReasonContract::mapToRuntimeReason($semanticReason);
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
