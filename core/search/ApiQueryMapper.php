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

        $params['page'] = isset($options['page']) ? max(1, (int) $options['page']) : 1;
        $params['pageSize'] = isset($options['pageSize']) ? max(1, min(100, (int) $options['pageSize'])) : 20;

        return $params;
    }

    /**
     * Budget is not on Host B allowlist yet — exposed for logging/tests only.
     *
     * @return array{budget_min: ?int, budget_max: ?int, area_fallback: bool}
     */
    public function metaOnly(SearchCondition $condition): array
    {
        $canonical = SearchConditionCanonicalizer::canonicalize($condition);

        return [
            'budget_min' => $canonical['budget_min'],
            'budget_max' => $canonical['budget_max'],
            'area_fallback' => $canonical['area_fallback'],
        ];
    }
}
