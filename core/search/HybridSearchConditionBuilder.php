<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AreaParser.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BudgetParser.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'KeywordNormalizer.php';

/**
 * Orchestrates Phase 2-A parsers into one SearchCondition (Phase 2-B).
 */
final class HybridSearchConditionBuilder
{
    /** @var DateParser */
    private $dateParser;

    /** @var AreaParser */
    private $areaParser;

    /** @var BudgetParser */
    private $budgetParser;

    /** @var KeywordNormalizer */
    private $keywordNormalizer;

    public function __construct(
        ?DateParser $dateParser = null,
        ?AreaParser $areaParser = null,
        ?BudgetParser $budgetParser = null,
        ?KeywordNormalizer $keywordNormalizer = null
    ) {
        $this->dateParser = $dateParser ?? new DateParser();
        $this->areaParser = $areaParser ?? new AreaParser();
        $this->budgetParser = $budgetParser ?? new BudgetParser();
        $this->keywordNormalizer = $keywordNormalizer ?? new KeywordNormalizer();
    }

    /**
     * @param array<string, mixed> $context optional: reference_date (DateTimeImmutable), merge_legacy_keyword (bool)
     */
    public function parse(string $message, array $context = []): SearchCondition
    {
        $message = trim($message);
        $condition = SearchCondition::empty($message);

        if (isset($context['reference_date']) && $context['reference_date'] instanceof \DateTimeImmutable) {
            $this->dateParser = new DateParser($context['reference_date']);
        }

        $condition = $this->dateParser->parse($message, $condition);
        $condition = $this->budgetParser->parse($message, $condition);
        $condition = $this->areaParser->parse($message, $condition);
        $condition = $this->keywordNormalizer->normalize($message, $condition);

        $condition = $this->extractDepartureCity($message, $condition);

        if (!empty($context['merge_legacy_keyword'])) {
            $condition = $this->mergeLegacyIntentKeyword($message, $condition);
        }

        if ($condition->getKeyword() === null && $condition->getDestination() === null && $condition->getArea() === null) {
            return $condition->with(['confidence' => max($condition->getConfidence(), 0.1)]);
        }

        return $condition->flag('hybrid_built')->bumpConfidence(0.05);
    }

    private function extractDepartureCity(string $message, SearchCondition $condition): SearchCondition
    {
        if (preg_match('/(台北|高雄|台中|桃園|松山|花蓮|台南)出發/u', $message, $m) !== 1) {
            return $condition;
        }

        return $condition
            ->with(['departure_city' => $m[1]])
            ->flag('departure_parsed')
            ->bumpConfidence(0.1);
    }

    private function mergeLegacyIntentKeyword(string $message, SearchCondition $condition): SearchCondition
    {
        if ($condition->getKeyword() !== null && $condition->getKeyword() !== '') {
            return $condition;
        }

        $detectorPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tour_query_intent_detector.php';
        if (!is_file($detectorPath)) {
            return $condition;
        }

        require_once $detectorPath;
        $intent = (new \TourQueryIntentDetector())->detect($message);
        if (($intent['is_tour_query'] ?? false) !== true) {
            return $condition;
        }

        $kw = isset($intent['keyword']) && is_string($intent['keyword']) ? trim($intent['keyword']) : '';
        if ($kw === '') {
            return $condition;
        }

        return $condition
            ->with(['keyword' => $kw])
            ->flag('legacy_keyword_merged')
            ->bumpConfidence(0.15);
    }
}
