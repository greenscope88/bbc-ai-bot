<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionCanonicalizer.php';

/**
 * Maps SearchCondition → Host B tour.search client params (allowlist-safe). No SQL/DB.
 */
final class ApiQueryMapper
{
    /** Mirrors TourSearchRequestBuilder::HOST_B_QUERY_ALLOWLIST (search fields only). */
    public const ALLOWED_QUERY_KEYS = [
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

    /**
     * @param array<string, mixed> $options page (int), pageSize (int), include_sno (string)
     * @return array<string, string|int>
     */
    public function toClientParams(SearchCondition $condition, array $options = []): array
    {
        $canonical = SearchConditionCanonicalizer::canonicalize($condition);
        $params = [];

        if (!empty($options['include_sno']) && is_string($options['include_sno'])) {
            $sno = trim($options['include_sno']);
            if ($sno !== '') {
                $params['sno'] = $sno;
            }
        }

        $keyword = $canonical['keyword'];
        if ($keyword !== '') {
            $params['keyword'] = $keyword;
        }

        if ($canonical['destination'] !== null && $canonical['destination'] !== '') {
            $params['destination'] = $canonical['destination'];
        }

        if ($canonical['country'] !== null && $canonical['country'] !== '') {
            $params['country'] = $canonical['country'];
        }

        if ($canonical['city'] !== null && $canonical['city'] !== '') {
            $params['city'] = $canonical['city'];
        }

        if ($canonical['dateFrom'] !== null && $canonical['dateFrom'] !== '') {
            $params['dateFrom'] = $canonical['dateFrom'];
        }

        if ($canonical['dateTo'] !== null && $canonical['dateTo'] !== '') {
            $params['dateTo'] = $canonical['dateTo'];
        }

        if ($canonical['budget_max'] !== null && (int) $canonical['budget_max'] > 0) {
            $params['priceMax'] = (int) $canonical['budget_max'];
        }

        if ($canonical['budget_min'] !== null && (int) $canonical['budget_min'] > 0) {
            $params['priceMin'] = (int) $canonical['budget_min'];
        }

        $params['page'] = isset($options['page']) ? max(1, (int) $options['page']) : 1;
        $params['pageSize'] = isset($options['pageSize']) ? max(1, min(100, (int) $options['pageSize'])) : 20;

        return $params;
    }

    /**
     * Budget mapping audit (budget_* → priceMax/priceMin on wire).
     *
     * @return array{budget_min: ?int, budget_max: ?int, area_fallback: bool, maps_to_priceMax: bool, maps_to_priceMin: bool}
     */
    public function metaOnly(SearchCondition $condition): array
    {
        $canonical = SearchConditionCanonicalizer::canonicalize($condition);

        return [
            'budget_min' => $canonical['budget_min'],
            'budget_max' => $canonical['budget_max'],
            'area_fallback' => $canonical['area_fallback'],
            'maps_to_priceMax' => $canonical['budget_max'] !== null && (int) $canonical['budget_max'] > 0,
            'maps_to_priceMin' => $canonical['budget_min'] !== null && (int) $canonical['budget_min'] > 0,
        ];
    }
}
