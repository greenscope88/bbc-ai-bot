<?php
declare(strict_types=1);

/**
 * Phase 2-C.2: Host B filter capability analysis (Host A side only).
 */
final class HybridSearchFilterCapability
{
    /** @var list<string> */
    public const HOST_B_ALLOWLIST_KEYS = [
        'sno',
        'keyword',
        'destination',
        'country',
        'city',
        'dateFrom',
        'dateTo',
        'priceMax',
        'priceMin',
        'page',
        'pageSize',
    ];

    /** @var list<string> */
    public const GATEWAY_CLIENT_URL_KEYS = [
        'keyword',
        'destination',
        'country',
        'city',
        'dateFrom',
        'dateTo',
        'priceMax',
        'priceMin',
    ];

    /**
     * @return array<string, bool>
     */
    public static function allowlistReport(): array
    {
        $report = [];
        foreach (['dateFrom', 'dateTo', 'priceMax', 'priceMin', 'destination', 'country', 'city'] as $key) {
            $report[$key] = in_array($key, self::HOST_B_ALLOWLIST_KEYS, true);
        }

        return $report;
    }

    /**
     * @param array<string, string|int> $apiParams
     * @return array<string, string|int>
     */
    public static function paramsOnWire(array $apiParams): array
    {
        $out = [];
        foreach (self::GATEWAY_CLIENT_URL_KEYS as $key) {
            if (!array_key_exists($key, $apiParams)) {
                continue;
            }
            $value = $apiParams[$key];
            if (is_int($value) || (is_string($value) && trim($value) !== '')) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{title: string, price: int|null, tourDate: string|null}>
     */
    public static function sampleItems(array $items, int $limit = 5): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'title' => isset($row['title']) ? (string) $row['title'] : '',
                'price' => isset($row['price']) ? (int) $row['price'] : null,
                'tourDate' => isset($row['tourDate']) ? (string) $row['tourDate'] : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $condition SearchCondition::toArray() shape
     * @return list<array<string, mixed>>
     */
    public static function detectViolations(array $items, array $condition): array
    {
        $violations = [];
        $dateFrom = isset($condition['date_from']) ? (string) $condition['date_from'] : '';
        $dateTo = isset($condition['date_to']) ? (string) $condition['date_to'] : '';
        $budgetMax = isset($condition['budget_max']) ? (int) $condition['budget_max'] : null;

        foreach ($items as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = isset($row['title']) ? (string) $row['title'] : '';
            $price = isset($row['price']) ? (int) $row['price'] : null;
            $tourDate = isset($row['tourDate']) ? (string) $row['tourDate'] : '';

            if ($dateFrom !== '' || $dateTo !== '') {
                $parsed = self::parseTourDate($tourDate);
                if ($parsed !== null) {
                    if ($dateFrom !== '' && $parsed < $dateFrom) {
                        $violations[] = [
                            'index' => $idx,
                            'type' => 'date_before_range',
                            'title' => $title,
                            'tourDate' => $tourDate,
                            'expected_from' => $dateFrom,
                        ];
                    }
                    if ($dateTo !== '' && $parsed > $dateTo) {
                        $violations[] = [
                            'index' => $idx,
                            'type' => 'date_after_range',
                            'title' => $title,
                            'tourDate' => $tourDate,
                            'expected_to' => $dateTo,
                        ];
                    }
                }
            }

            if ($budgetMax !== null && $budgetMax > 0 && $price !== null && $price > $budgetMax) {
                $violations[] = [
                    'index' => $idx,
                    'type' => 'price_above_max',
                    'title' => $title,
                    'price' => $price,
                    'expected_max' => $budgetMax,
                ];
            }

            if (count($violations) >= 10) {
                break;
            }
        }

        return $violations;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $condition
     */
    public static function filterLikelyEffective(array $items, array $condition): ?bool
    {
        if ($items === []) {
            return null;
        }

        $hasDate = !empty($condition['date_from']) || !empty($condition['date_to']);
        $hasBudget = !empty($condition['budget_max']);

        if (!$hasDate && !$hasBudget) {
            return null;
        }

        return self::detectViolations($items, $condition) === [];
    }

    private static function parseTourDate(string $tourDate): ?string
    {
        $tourDate = trim($tourDate);
        if ($tourDate === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $tourDate, $m) === 1) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})/', $tourDate, $m) === 1) {
            $year = (int) date('Y');
            $month = (int) $m[1];
            $day = (int) $m[2];

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }
}
