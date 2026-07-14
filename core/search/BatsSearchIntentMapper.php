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
        return !$intent->isClarificationRequired() && $intent->getDestination() !== [];
    }

    public function toSearchCondition(BatsSearchIntent $intent): ?SearchCondition
    {
        if (!$this->canMap($intent)) {
            return null;
        }

        $destination = $intent->getDestination();
        $productType = $intent->getProductType();

        return SearchCondition::empty($intent->getFreeText())->with([
            'intent' => $intent->getIntent(),
            'destination' => $destination,
            'keyword' => $this->resolveSearchKeyword($intent),
            'departure_city' => $intent->getDepartureCity(),
            'date_from' => $intent->getDateFrom(),
            'date_to' => $intent->getDateTo(),
            'budget_min' => $intent->getBudgetMin(),
            'budget_max' => $intent->getBudgetMax(),
            'duration' => $intent->getDuration(),
            'people_count' => $intent->getPeopleCount(),
            'people_label' => $intent->getPeopleLabel(),
            'product_type' => $productType,
            'must_have' => $intent->getMustHave(),
            'confidence' => $intent->getConfidence(),
        ])->flag('bats_intent_mapped');
    }

    private function resolveSearchKeyword(BatsSearchIntent $intent): ?string
    {
        $parts = [];
        foreach ($intent->getMustHave() as $term) {
            $t = trim($term);
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        if ($parts === []) {
            $landmark = $intent->getLandmark();
            if ($landmark !== null && trim($landmark) !== '') {
                return trim($landmark);
            }

            return null;
        }

        return implode(' ', array_values(array_unique($parts)));
    }
}
