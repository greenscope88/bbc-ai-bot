<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ProductSearchPolicyRuntime.php';

/**
 * Phase 9-C-2A: builds grounded recommendation_summary from search results.
 */
final class ProductRecommendationBuilder
{
    private const MAX_TOP_PRODUCTS = 3;

    private const PREFERENCE_HINTS = [
        '親子旅遊',
        '溫泉渡假',
        '美食行程',
    ];

    private ProductSearchPolicyRuntime $searchPolicyRuntime;

    public function __construct(?ProductSearchPolicyRuntime $searchPolicyRuntime = null)
    {
        $this->searchPolicyRuntime = $searchPolicyRuntime ?? new ProductSearchPolicyRuntime();
    }

    /**
     * @param list<array<string, mixed>> $searchResults
     * @param array<string, mixed> $batsSearchIntent
     * @param array<string, mixed> $searchPolicyMeta
     * @return array<string, mixed>
     */
    public function build(
        array $searchResults,
        array $batsSearchIntent = [],
        string $customerQuery = '',
        array $searchPolicyMeta = []
    ): array {
        if (($searchPolicyMeta['product_type_mismatch'] ?? false) === true) {
            return $this->buildProductTypeMismatchSummary($batsSearchIntent, $searchPolicyMeta);
        }

        $topProducts = $this->selectTopProducts($searchResults);
        $resultCount = count($searchResults);
        $primaryUrl = $this->resolvePrimaryUrl($topProducts, $searchResults);

        return [
            'result_count' => $resultCount,
            'top_products' => $topProducts,
            'primary_url' => $primaryUrl,
            'recommendation_reason' => $this->buildRecommendationReason($batsSearchIntent, $customerQuery, $resultCount),
            'preference_hints' => self::PREFERENCE_HINTS,
            'search_policy' => isset($searchPolicyMeta['policy']) ? (string) $searchPolicyMeta['policy'] : 'none',
            'keyword_preference' => isset($searchPolicyMeta['keyword_preference'])
                ? (string) $searchPolicyMeta['keyword_preference']
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $batsSearchIntent
     * @param array<string, mixed> $searchPolicyMeta
     * @return array<string, mixed>
     */
    private function buildProductTypeMismatchSummary(array $batsSearchIntent, array $searchPolicyMeta): array
    {
        $productType = trim((string) ($searchPolicyMeta['product_type'] ?? ($batsSearchIntent['product_type'] ?? '')));
        $destination = trim((string) ($searchPolicyMeta['destination'] ?? ($batsSearchIntent['destination'] ?? '')));
        $dateLabel = trim((string) ($searchPolicyMeta['date_label'] ?? ''));

        return [
            'result_count' => 0,
            'top_products' => [],
            'primary_url' => '',
            'recommendation_reason' => $this->buildProductTypeMismatchReason($destination, $dateLabel, $productType),
            'product_type_mismatch' => true,
            'requested_product_type' => $productType,
            'alternative_recommendations' => $this->buildAlternativeRecommendations($searchPolicyMeta, $destination),
            'preference_hints' => self::PREFERENCE_HINTS,
            'search_policy' => isset($searchPolicyMeta['policy']) ? (string) $searchPolicyMeta['policy'] : 'product_type_strict',
        ];
    }

    /**
     * @param array<string, mixed> $searchPolicyMeta
     * @return list<array<string, mixed>>
     */
    private function buildAlternativeRecommendations(array $searchPolicyMeta, string $destination): array
    {
        $items = isset($searchPolicyMeta['alternative_results']) && is_array($searchPolicyMeta['alternative_results'])
            ? $searchPolicyMeta['alternative_results']
            : [];
        if ($items === []) {
            return [];
        }

        $groups = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = isset($item['title']) ? trim((string) $item['title']) : '';
            if ($title === '') {
                continue;
            }
            $inferred = $this->searchPolicyRuntime->inferProductType($title) ?? '其他';
            if (!isset($groups[$inferred])) {
                $groups[$inferred] = [];
            }
            $groups[$inferred][] = $item;
        }

        $out = [];
        foreach ($groups as $type => $groupItems) {
            $label = $destination !== '' ? $destination . $type : $type;
            $out[] = [
                'label' => $label,
                'product_type' => $type,
                'top_products' => $this->selectTopProducts($groupItems),
            ];
        }

        return $out;
    }

    private function buildProductTypeMismatchReason(string $destination, string $dateLabel, string $productType): string
    {
        $focus = $dateLabel . $destination . $productType;
        if ($focus === '') {
            return '目前沒有找到符合您需求的商品。';
        }

        return '目前沒有找到 ' . $focus . '商品。';
    }

    /**
     * @param list<array<string, mixed>> $searchResults
     * @return list<array<string, mixed>>
     */
    private function selectTopProducts(array $searchResults): array
    {
        $selected = [];

        foreach ($searchResults as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($title === '') {
                continue;
            }

            $primaryUrl = $this->resolveItemUrl($row);
            $selected[] = [
                'title' => $title,
                'summary' => isset($row['summary']) && is_string($row['summary'])
                    ? (trim($row['summary']) !== '' ? trim($row['summary']) : null)
                    : null,
                'primary_url' => $primaryUrl,
                'display_emoji' => $this->pickDisplayEmoji(count($selected)),
            ];

            if (count($selected) >= self::MAX_TOP_PRODUCTS) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $topProducts
     * @param list<array<string, mixed>> $searchResults
     */
    private function resolvePrimaryUrl(array $topProducts, array $searchResults): string
    {
        foreach ($topProducts as $product) {
            $url = trim((string) ($product['primary_url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        foreach ($searchResults as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = $this->resolveItemUrl($row);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveItemUrl(array $row): string
    {
        if (isset($row['primary_url']) && trim((string) $row['primary_url']) !== '') {
            return trim((string) $row['primary_url']);
        }
        if (isset($row['search_url']) && trim((string) $row['search_url']) !== '') {
            return trim((string) $row['search_url']);
        }
        if (isset($row['url']) && trim((string) $row['url']) !== '') {
            return trim((string) $row['url']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $batsSearchIntent
     */
    private function buildRecommendationReason(array $batsSearchIntent, string $customerQuery, int $resultCount): string
    {
        if ($resultCount <= 0) {
            return '目前尚未找到符合條件的商品。';
        }

        $parts = [];
        $destination = isset($batsSearchIntent['destination']) && is_string($batsSearchIntent['destination'])
            ? trim($batsSearchIntent['destination'])
            : '';
        if ($destination !== '') {
            $parts[] = $destination;
        }

        $dateFrom = isset($batsSearchIntent['date_from']) && is_string($batsSearchIntent['date_from'])
            ? trim($batsSearchIntent['date_from'])
            : '';
        $dateTo = isset($batsSearchIntent['date_to']) && is_string($batsSearchIntent['date_to'])
            ? trim($batsSearchIntent['date_to'])
            : '';
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom !== $dateTo) {
            $parts[] = $dateFrom . '～' . $dateTo;
        } elseif ($dateFrom !== '') {
            $parts[] = $dateFrom;
        }

        $departure = isset($batsSearchIntent['departure_city']) && is_string($batsSearchIntent['departure_city'])
            ? trim($batsSearchIntent['departure_city'])
            : '';
        if ($departure !== '') {
            $parts[] = $departure . '出發';
        }

        if ($parts !== []) {
            return '依您提到的「' . implode('、', $parts) . '」整理近期熱門行程。';
        }

        $query = trim($customerQuery);
        if ($query !== '') {
            return '依您提到的「' . $query . '」整理近期熱門行程。';
        }

        return '依您的需求整理近期熱門行程。';
    }

    private function pickDisplayEmoji(int $index): string
    {
        $emojis = ['✈️', '♨️', '🌸'];

        return $emojis[$index % count($emojis)];
    }
}
