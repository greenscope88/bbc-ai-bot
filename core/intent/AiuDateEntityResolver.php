<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * AIU v2 date entity resolver — aligns Gemini entity dates with Hybrid Date Policy.
 *
 * Uses DateParser on the customer utterance to produce canonical date_from/date_to,
 * clears date clarification when a valid range is established, and does not modify
 * SearchCondition or Product Search architecture.
 */
final class AiuDateEntityResolver
{
    /** @var list<string> */
    private const DATE_CLARIFICATION_REASONS = [
        'missing_travel_dates',
        'date_required',
    ];

    /**
     * @param array<string, mixed> $entity
     * @return array{
     *   entity: array<string, mixed>,
     *   clarification_required: bool,
     *   clarification_reason: string
     * }
     */
    public function resolve(
        array $entity,
        string $customerUtterance,
        bool $clarificationRequired,
        string $clarificationReason,
        ?\DateTimeImmutable $referenceDate = null
    ): array {
        $out = $entity;

        $parser = new DateParser($referenceDate);
        $parsed = $parser->parse($customerUtterance, SearchCondition::empty($customerUtterance));
        $parsedFrom = $parsed->getDateFrom();
        $parsedTo = $parsed->getDateTo();

        if ($parsedFrom !== null && trim($parsedFrom) !== '') {
            $out['date_from'] = trim($parsedFrom);
        }
        if ($parsedTo !== null && trim($parsedTo) !== '') {
            $out['date_to'] = trim($parsedTo);
        }

        $out = $this->expandMonthOnlyRange($out);
        $out = $this->applyExplicitYear($out, $customerUtterance);

        if ($this->hasResolvedDateRange($out) && $clarificationRequired) {
            $reason = trim($clarificationReason);
            if ($reason === '' || in_array($reason, self::DATE_CLARIFICATION_REASONS, true)) {
                $clarificationRequired = false;
                $clarificationReason = '';
            }
        }

        return [
            'entity' => $out,
            'clarification_required' => $clarificationRequired,
            'clarification_reason' => $clarificationReason,
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function expandMonthOnlyRange(array $entity): array
    {
        $from = isset($entity['date_from']) ? trim((string) $entity['date_from']) : '';
        $to = isset($entity['date_to']) ? trim((string) $entity['date_to']) : '';

        if ($from === '' || $to !== '') {
            return $entity;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $m) !== 1) {
            return $entity;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($day !== 1) {
            return $entity;
        }

        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('d');

        $entity['date_to'] = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

        return $entity;
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function applyExplicitYear(array $entity, string $utterance): array
    {
        if (preg_match('/(\d{4})年/u', $utterance, $m) !== 1) {
            return $entity;
        }

        $year = (int) $m[1];
        foreach (['date_from', 'date_to'] as $field) {
            if (!isset($entity[$field]) || !is_string($entity[$field])) {
                continue;
            }
            $value = trim($entity[$field]);
            if ($value === '' || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
                continue;
            }
            $entity[$field] = sprintf('%04d-%02d-%02d', $year, (int) $parts[2], (int) $parts[3]);
        }

        return $entity;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function hasResolvedDateRange(array $entity): bool
    {
        $from = isset($entity['date_from']) ? trim((string) $entity['date_from']) : '';
        $to = isset($entity['date_to']) ? trim((string) $entity['date_to']) : '';

        return $from !== '' && $to !== '';
    }
}
