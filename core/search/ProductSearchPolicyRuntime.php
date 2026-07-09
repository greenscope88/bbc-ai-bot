<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Product Search Policy — execution layer only (not AIU Runtime Contract).
 *
 * - product_type: Strict Match when mapped to source keyword
 * - keyword: Search Preference (ranking only, not filtering)
 */
final class ProductSearchPolicyRuntime
{
    /** @var array<string, list<string>> */
    private const PRODUCT_TYPE_ALIASES = [
        '自由行' => ['自由行', '半自助'],
        '半自助' => ['自由行', '半自助'],
        '跟團' => ['跟團', '團體'],
        '團體' => ['跟團', '團體'],
        '迷你團' => ['迷你團'],
        '包車' => ['包車'],
        '郵輪' => ['郵輪'],
    ];

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function apply(SearchCondition $condition, array $items): array
    {
        $normalizedItems = $this->normalizeItems($items);
        $productType = $condition->getProductType();
        $keyword = $condition->getKeyword();
        $destination = $condition->getDestination();

        if ($productType !== null && trim($productType) !== '') {
            return $this->applyProductTypeStrict(
                $normalizedItems,
                trim($productType),
                $destination,
                $condition
            );
        }

        if ($keyword !== null && trim($keyword) !== '') {
            $trimmedKeyword = trim($keyword);
            $trimmedDestination = $destination !== null ? trim($destination) : '';
            if ($trimmedKeyword !== '' && $trimmedKeyword !== $trimmedDestination) {
                return $this->applyKeywordPreference($normalizedItems, $trimmedKeyword);
            }
        }

        return [
            'primary_results' => $normalizedItems,
            'alternative_results' => [],
            'policy' => 'none',
            'product_type' => null,
            'product_type_mismatch' => false,
            'keyword_preference' => null,
            'raw_count' => count($normalizedItems),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function applyProductTypeStrict(
        array $items,
        string $productType,
        ?string $destination,
        SearchCondition $condition
    ): array {
        $primary = [];
        $alternatives = [];

        foreach ($items as $item) {
            if ($this->itemMatchesProductType($productType, $item)) {
                $primary[] = $item;
                continue;
            }
            $alternatives[] = $item;
        }

        return [
            'primary_results' => $primary,
            'alternative_results' => $alternatives,
            'policy' => 'product_type_strict',
            'product_type' => $productType,
            'product_type_mismatch' => $primary === [] && $items !== [],
            'keyword_preference' => null,
            'raw_count' => count($items),
            'destination' => $destination !== null ? trim($destination) : '',
            'date_label' => $this->buildDateLabel($condition),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function applyKeywordPreference(array $items, string $keyword): array
    {
        $ranked = $items;
        usort(
            $ranked,
            function (array $left, array $right) use ($keyword): int {
                return $this->keywordPreferenceScore($right, $keyword)
                    <=> $this->keywordPreferenceScore($left, $keyword);
            }
        );

        return [
            'primary_results' => $ranked,
            'alternative_results' => [],
            'policy' => 'keyword_preference',
            'product_type' => null,
            'product_type_mismatch' => false,
            'keyword_preference' => $keyword,
            'raw_count' => count($items),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    public function itemMatchesProductType(string $requestedType, array $item): bool
    {
        $text = $this->itemText($item);
        if ($text === '') {
            return false;
        }

        $inferred = $this->inferProductType($text);
        if ($inferred === null) {
            return false;
        }

        return $this->productTypesEquivalent($requestedType, $inferred);
    }

    public function inferProductType(string $text): ?string
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return null;
        }

        if (mb_strpos($normalized, '迷你團', 0, 'UTF-8') !== false) {
            return '迷你團';
        }
        if (mb_strpos($normalized, '郵輪', 0, 'UTF-8') !== false) {
            return '郵輪';
        }
        if (mb_strpos($normalized, '包車', 0, 'UTF-8') !== false) {
            return '包車';
        }
        if (
            mb_strpos($normalized, '自由行', 0, 'UTF-8') !== false
            || mb_strpos($normalized, '半自助', 0, 'UTF-8') !== false
        ) {
            return '自由行';
        }
        if (
            mb_strpos($normalized, '跟團', 0, 'UTF-8') !== false
            || mb_strpos($normalized, '團體', 0, 'UTF-8') !== false
            || mb_strpos($normalized, '團旅', 0, 'UTF-8') !== false
            || preg_match('/\d+\s*日團/u', $normalized) === 1
            || preg_match('/\d+\s*天團/u', $normalized) === 1
        ) {
            return '跟團';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function keywordPreferenceScore(array $item, string $keyword): int
    {
        $text = $this->itemText($item);
        if ($text === '') {
            return 0;
        }

        $score = 0;
        if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
            $score += 100;
        }

        $tokens = preg_split('/\s+/u', $keyword) ?: [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strpos($text, $token, 0, 'UTF-8') === false) {
                continue;
            }
            $score += 20;
        }

        return $score;
    }

    private function productTypesEquivalent(string $requested, string $inferred): bool
    {
        $requested = trim($requested);
        $inferred = trim($inferred);
        if ($requested === $inferred) {
            return true;
        }

        $requestedAliases = self::PRODUCT_TYPE_ALIASES[$requested] ?? [$requested];
        $inferredAliases = self::PRODUCT_TYPE_ALIASES[$inferred] ?? [$inferred];

        foreach ($requestedAliases as $alias) {
            if (in_array($alias, $inferredAliases, true)) {
                return true;
            }
        }

        return false;
    }

    private function buildDateLabel(SearchCondition $condition): string
    {
        $from = $condition->getDateFrom();
        if ($from !== null && preg_match('/^(\d{4})-(\d{2})/', $from, $m) === 1) {
            return (int) $m[2] . '月';
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemText(array $item): string
    {
        $parts = [];
        foreach (['title', 'summary', 'couponName', 'name', '行程名稱'] as $field) {
            if (!isset($item[$field])) {
                continue;
            }
            $value = trim((string) $item[$field]);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }
}