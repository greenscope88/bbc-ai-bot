<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Maps semantic BatsSearchIntent to execution-layer SearchCondition.
 */
final class BatsSearchIntentMapper
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
        $productType = $this->resolveProductType($intent);
        $travelStyle = $this->resolveTravelStyle($intent, $productType);

        return SearchCondition::empty($intent->getFreeText())->with([
            'intent' => $intent->getIntent(),
            'destination' => $destination,
            'keyword' => $this->resolveSearchKeyword($intent, $destination),
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
            'travel_style' => $travelStyle,
            'special_tags' => $travelStyle,
            'confidence' => $intent->getConfidence(),
        ])->flag('bats_intent_mapped');
    }

    private function resolveProductType(BatsSearchIntent $intent): ?string
    {
        $explicit = $intent->getProductType();
        if ($explicit !== null) {
            return $explicit;
        }

        foreach ($intent->getTravelType() as $token) {
            if ($this->isProductTypeToken($token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveTravelStyle(BatsSearchIntent $intent, ?string $productType): array
    {
        $styles = [];
        foreach ($intent->getTravelType() as $token) {
            if ($this->isProductTypeToken($token)) {
                continue;
            }
            $styles[] = $token;
        }

        return array_values(array_unique($styles));
    }

    private function resolveSearchKeyword(BatsSearchIntent $intent, ?string $destination): ?string
    {
        $parts = [];
        if ($destination !== null && trim($destination) !== '') {
            $parts[] = trim($destination);
        }
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

    private function isProductTypeToken(string $token): bool
    {
        return in_array(trim($token), self::PRODUCT_TYPE_TOKENS, true);
    }
}
