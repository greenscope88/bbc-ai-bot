<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Unified clarification gate for BatsSearchIntent (BATS_AI_SEMANTIC_SEARCH.md §10).
 */
final class ClarificationPolicy
{
    public const REASON_DATE_REQUIRED = 'date_required';

    public const REASON_DESTINATION_UNKNOWN = 'destination_unknown';

    /** @var HybridDateRequiredGate */
    private $dateGate;

    /** @var float */
    private $destinationConfidenceThreshold;

    public function __construct(
        ?HybridDateRequiredGate $dateGate = null,
        float $destinationConfidenceThreshold = 0.15
    ) {
        $this->dateGate = $dateGate ?? new HybridDateRequiredGate();
        $this->destinationConfidenceThreshold = $destinationConfidenceThreshold;
    }

    public function apply(BatsSearchIntent $intent, ?SearchCondition $condition = null): BatsSearchIntent
    {
        if ($intent->getDestination() === [] || $intent->getConfidence() < $this->destinationConfidenceThreshold) {
            return $intent->with([
                'clarification_required' => true,
                'clarification_reason' => self::REASON_DESTINATION_UNKNOWN,
            ]);
        }

        $condition = $condition ?? $this->buildProbeCondition($intent);
        $dateResult = $this->dateGate->evaluate($condition);
        if ($dateResult->requiresDateClarification()) {
            return $intent->with([
                'clarification_required' => true,
                'clarification_reason' => self::REASON_DATE_REQUIRED,
            ]);
        }

        if (count($intent->getDestination()) > 1 && !$this->hasResolvedDateRange($intent)) {
            return $intent->with([
                'clarification_required' => true,
                'clarification_reason' => self::REASON_DATE_REQUIRED,
            ]);
        }

        return $intent->with([
            'clarification_required' => false,
            'clarification_reason' => null,
        ]);
    }

    private function hasResolvedDateRange(BatsSearchIntent $intent): bool
    {
        $from = $intent->getDateFrom();
        $to = $intent->getDateTo();

        if ($from !== null && trim($from) !== '') {
            return true;
        }

        if ($to !== null && trim($to) !== '') {
            return true;
        }

        return false;
    }

    private function buildProbeCondition(BatsSearchIntent $intent): SearchCondition
    {
        $destination = $intent->getDestination();

        return SearchCondition::empty($intent->getFreeText())->with([
            'destination' => $destination,
            'keyword' => $destination !== [] ? implode(' ', $destination) : null,
            'date_from' => $intent->getDateFrom(),
            'date_to' => $intent->getDateTo(),
        ]);
    }
}
