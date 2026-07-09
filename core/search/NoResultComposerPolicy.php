<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * No Result Composer Policy — execution layer only.
 *
 * When Product Search returns 0 and hard constraints are complete,
 * route to No Result Composer instead of asking for known fields.
 */
final class NoResultComposerPolicy
{
    /**
     * @param array<string, mixed> $intentArray
     * @return array{
     *   hard_constraints_complete: bool,
     *   should_use_no_result_composer: bool,
     *   missing_constraints: list<string>
     * }
     */
    public function evaluateFromIntentArray(array $intentArray): array
    {
        $destination = isset($intentArray['destination']) && is_string($intentArray['destination'])
            ? trim($intentArray['destination'])
            : '';
        $dateFrom = isset($intentArray['date_from']) && is_string($intentArray['date_from'])
            ? trim($intentArray['date_from'])
            : '';
        $dateTo = isset($intentArray['date_to']) && is_string($intentArray['date_to'])
            ? trim($intentArray['date_to'])
            : '';
        $productType = isset($intentArray['product_type']) && is_string($intentArray['product_type'])
            ? trim($intentArray['product_type'])
            : '';

        $missing = [];
        if ($destination === '') {
            $missing[] = 'destination';
        }
        if ($dateFrom === '' && $dateTo === '') {
            $missing[] = 'date';
        }

        $hardComplete = $missing === [];

        return [
            'hard_constraints_complete' => $hardComplete,
            'should_use_no_result_composer' => $hardComplete,
            'missing_constraints' => $missing,
            'destination' => $destination,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'product_type' => $productType,
        ];
    }

    public function evaluate(BatsSearchIntent $intent): array
    {
        return $this->evaluateFromIntentArray($intent->toArray());
    }

    public function evaluateSearchCondition(SearchCondition $condition): array
    {
        return $this->evaluateFromIntentArray([
            'destination' => $condition->getDestination(),
            'date_from' => $condition->getDateFrom(),
            'date_to' => $condition->getDateTo(),
            'product_type' => $condition->getProductType(),
        ]);
    }

    public function buildNoResultReason(array $evaluation): string
    {
        $destination = trim((string) ($evaluation['destination'] ?? ''));
        $productType = trim((string) ($evaluation['product_type'] ?? ''));
        $dateLabel = $this->buildDateLabel(
            isset($evaluation['date_from']) ? (string) $evaluation['date_from'] : null
        );

        $focus = $dateLabel . $destination . $productType;
        if ($focus === '') {
            return '目前沒有找到符合條件的商品。';
        }

        return '目前沒有找到 ' . $focus . '商品。';
    }

    private function buildDateLabel(?string $dateFrom): string
    {
        if ($dateFrom !== null && preg_match('/^(\d{4})-(\d{2})/', $dateFrom, $m) === 1) {
            return (int) $m[2] . '月';
        }

        return '';
    }
}