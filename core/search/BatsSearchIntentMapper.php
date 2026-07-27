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
        if ($intent->isClarificationRequired() || $intent->getDestination() === []) {
            return false;
        }

        $projectedKeyword = $intent->getProjectedKeyword();

        return $projectedKeyword !== null && trim($projectedKeyword) !== '';
    }

    public function toSearchCondition(BatsSearchIntent $intent): ?SearchCondition
    {
        if (!$this->canMap($intent)) {
            return null;
        }

        $destination = $intent->getDestination();
        $productType = $intent->getProductType();
        $projectedKeyword = $intent->getProjectedKeyword();
        $searchKeywordTokens = $intent->getSearchKeywordTokens();
        if ($projectedKeyword === null || trim($projectedKeyword) === '') {
            return null;
        }

        $keyword = trim($projectedKeyword);
        self::assertAuthoritativeInvariant($searchKeywordTokens, $keyword);

        return SearchCondition::empty($intent->getFreeText())->with([
            'intent' => $intent->getIntent(),
            'destination' => $destination,
            'area' => $intent->getTravelArea(),
            'keyword' => $keyword,
            'search_keyword_tokens' => $searchKeywordTokens,
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

    /**
     * @param list<string> $searchKeywordTokens
     */
    private static function assertAuthoritativeInvariant(array $searchKeywordTokens, string $keyword): void
    {
        if ($searchKeywordTokens === [] || $keyword === '') {
            throw new \InvalidArgumentException('authoritative search keyword token invariant violated');
        }

        if ($keyword !== implode(' ', $searchKeywordTokens)) {
            throw new \InvalidArgumentException('authoritative search keyword token invariant violated');
        }
    }
}
