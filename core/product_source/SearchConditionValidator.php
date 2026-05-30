<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductCategoryContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionContractException.php';

/**
 * Validates BATS unified search condition documents (Phase 9-B-12).
 */
final class SearchConditionValidator
{
    /**
     * @param array<string, mixed> $document
     * @throws SearchConditionContractException
     */
    public function validate(array $document): array
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            $this->throwFromViolations($violations);
        }

        return SearchConditionContract::normalize($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];

        if (isset($document['schema_version'])) {
            $version = (int) $document['schema_version'];
            if (!in_array($version, SearchConditionContract::SUPPORTED_SCHEMA_VERSIONS, true)) {
                $violations[] = 'unsupported schema_version';
            }
        }

        if (!SearchConditionContract::hasSearchCriterion($document)) {
            $violations[] = 'at least one search criterion is required (e.g. keyword)';
        }

        if (isset($document['product_category'])) {
            $category = trim((string) $document['product_category']);
            if ($category !== '' && !ProductCategoryContract::isValidProductCategory($category)) {
                $violations[] = SearchConditionContractException::INVALID_PRODUCT_CATEGORY;
            }
        }

        $dateFrom = isset($document['date_from']) ? trim((string) $document['date_from']) : '';
        $dateTo = isset($document['date_to']) ? trim((string) $document['date_to']) : '';

        if ($dateFrom !== '' && !preg_match(SearchConditionContract::DATE_PATTERN, $dateFrom)) {
            $violations[] = 'invalid date_from format';
        }
        if ($dateTo !== '' && !preg_match(SearchConditionContract::DATE_PATTERN, $dateTo)) {
            $violations[] = 'invalid date_to format';
        }

        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            $violations[] = SearchConditionContractException::INVALID_DATE_RANGE;
        }

        $budgetMin = $this->optionalInt($document, 'budget_min');
        $budgetMax = $this->optionalInt($document, 'budget_max');
        if ($budgetMin !== null && $budgetMin < 0) {
            $violations[] = 'budget_min must be >= 0';
        }
        if ($budgetMax !== null && $budgetMax < 0) {
            $violations[] = 'budget_max must be >= 0';
        }
        if ($budgetMin !== null && $budgetMax !== null && $budgetMax < $budgetMin) {
            $violations[] = SearchConditionContractException::INVALID_BUDGET_RANGE;
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function optionalInt(array $document, string $key): ?int
    {
        if (!isset($document[$key]) || $document[$key] === null || $document[$key] === '') {
            return null;
        }

        return (int) $document[$key];
    }

    /**
     * @param list<string> $violations
     */
    private function throwFromViolations(array $violations): void
    {
        $priority = [
            SearchConditionContractException::INVALID_PRODUCT_CATEGORY,
            SearchConditionContractException::INVALID_DATE_RANGE,
            SearchConditionContractException::INVALID_BUDGET_RANGE,
        ];

        foreach ($priority as $code) {
            if (in_array($code, $violations, true)) {
                throw new SearchConditionContractException(
                    $code,
                    'Search condition contract invalid: ' . $code,
                    $violations
                );
            }
        }

        throw new SearchConditionContractException(
            SearchConditionContractException::INVALID_INPUT,
            'Search condition contract invalid (' . count($violations) . ' issue(s)).',
            $violations
        );
    }
}
