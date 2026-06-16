<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Maps semantic BatsSearchIntent to execution-layer SearchCondition.
 */
final class BatsSearchIntentMapper
{
    public function canMap(BatsSearchIntent $intent): bool
    {
        return !$intent->isClarificationRequired() && $intent->getDestination() !== null;
    }

    public function toSearchCondition(BatsSearchIntent $intent): ?SearchCondition
    {
        if (!$this->canMap($intent)) {
            return null;
        }

        $destination = $intent->getDestination();
        $travelStyle = $intent->getTravelType();

        return SearchCondition::empty($intent->getFreeText())->with([
            'intent' => $intent->getIntent(),
            'destination' => $destination,
            'keyword' => $destination,
            'departure_city' => $intent->getDepartureCity(),
            'date_from' => $intent->getDateFrom(),
            'date_to' => $intent->getDateTo(),
            'budget_min' => $intent->getBudgetMin(),
            'budget_max' => $intent->getBudgetMax(),
            'travel_style' => $travelStyle,
            'special_tags' => $travelStyle,
            'confidence' => $intent->getConfidence(),
        ])->flag('bats_intent_mapped');
    }
}
