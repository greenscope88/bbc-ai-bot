<?php
declare(strict_types=1);

/**
 * AIU v2 date mapping — Gap B0-5.
 *
 * Only maps Gemini-provided date_range / date_from / date_to.
 * Does NOT re-parse customer utterance or invent dates from date_expression.
 */
final class AiuDateEntityResolver
{
    /**
     * @param array<string, mixed> $entities
     * @return array{
     *   entities: array<string, mixed>,
     *   clarification_required: bool,
     *   clarification_reason: string
     * }
     */
    public function resolve(
        array $entities,
        string $customerUtterance,
        bool $clarificationRequired,
        string $clarificationReason,
        ?\DateTimeImmutable $referenceDate = null
    ): array {
        unset($customerUtterance, $referenceDate);

        $out = $entities;

        // Flatten date_range object only when Gemini already provided it.
        $dateRange = $out['date_range'] ?? null;
        if (is_array($dateRange)) {
            $from = isset($dateRange['from']) ? trim((string) $dateRange['from']) : '';
            $to = isset($dateRange['to']) ? trim((string) $dateRange['to']) : '';
            $out['date_from'] = $from !== '' ? $from : null;
            $out['date_to'] = $to !== '' ? $to : null;
            $out['date_range'] = [
                'from' => $out['date_from'],
                'to' => $out['date_to'],
            ];
        } else {
            $from = isset($out['date_from']) ? trim((string) $out['date_from']) : '';
            $to = isset($out['date_to']) ? trim((string) $out['date_to']) : '';
            $out['date_from'] = $from !== '' ? $from : null;
            $out['date_to'] = $to !== '' ? $to : null;
            if ($out['date_from'] !== null && $out['date_to'] !== null) {
                $out['date_range'] = [
                    'from' => $out['date_from'],
                    'to' => $out['date_to'],
                ];
            } else {
                $out['date_range'] = null;
            }
        }

        // If Gemini already supplied a complete range, clear date-missing clarification only.
        if (
            $clarificationRequired
            && $out['date_from'] !== null
            && $out['date_to'] !== null
            && in_array(trim($clarificationReason), ['missing_travel_dates', 'date_required', ''], true)
        ) {
            $clarificationRequired = false;
            $clarificationReason = '';
        }

        return [
            'entities' => $out,
            'clarification_required' => $clarificationRequired,
            'clarification_reason' => $clarificationReason,
        ];
    }
}
