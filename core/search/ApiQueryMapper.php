<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionCanonicalizer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'HostBTourSearchParamMapper.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';

/**
 * Maps SearchCondition → internal gateway client params, then Host B wire params. No SQL/DB.
 */
final class ApiQueryMapper
{
    /** Internal/gateway keys (SearchUrlBuilder / dry-run; not sent verbatim to Host B). */
    public const ALLOWED_QUERY_KEYS = [
        'keyword',
        'destination',
        'country',
        'city',
        'dateFrom',
        'dateTo',
        'priceMax',
        'priceMin',
        'departureCity',
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

        $hasDestinationParam = $canonical['destination'] !== null && $canonical['destination'] !== '';
        $wireKeyword = $hasDestinationParam
            ? SourceQueryMapper::buildHostBKeywordFromSearchCondition($condition)
            : SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($condition);
        if ($wireKeyword === '') {
            $wireKeyword = $canonical['keyword'];
        }
        if ($wireKeyword !== '') {
            $params['keyword'] = $wireKeyword;
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

        $departureCity = $condition->getDepartureCity();
        if ($departureCity !== null && trim($departureCity) !== '') {
            $params['departureCity'] = trim($departureCity);
        }

        $params['page'] = isset($options['page']) ? max(1, (int) $options['page']) : 1;
        $params['pageSize'] = isset($options['pageSize']) ? max(1, min(100, (int) $options['pageSize'])) : 20;

        return $params;
    }

    /**
     * Host B wire params (TourDateS, AmountMax, Departure, …). Phase 2-C.4.
     *
     * @param array<string, mixed> $options same as toClientParams()
     * @return array<string, string|int>
     */
    public function toHostBParams(SearchCondition $condition, array $options = []): array
    {
        return HostBTourSearchParamMapper::toHostBQueryParams(
            $this->toClientParams($condition, $options)
        );
    }

    /**
     * @param array<string, string|int> $internalParams
     * @return array<string, string|int>
     */
    public function hostbMappingAudit(array $internalParams): array
    {
        return HostBTourSearchParamMapper::mappingAudit($internalParams);
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

    /**
     * Entity context for composer / metadata (P1 contract fields).
     *
     * @return array{
     *   duration: ?string,
     *   people_count: ?int,
     *   people_label: ?string,
     *   product_type: ?string,
     *   travel_style: list<string>,
     *   must_have: list<string>
     * }
     */
    public function entityContext(SearchCondition $condition): array
    {
        return [
            'duration' => $condition->getDuration(),
            'people_count' => $condition->getPeopleCount(),
            'people_label' => $condition->getPeopleLabel(),
            'product_type' => $condition->getProductType(),
            'travel_style' => $condition->getTravelStyle(),
            'must_have' => $condition->getMustHave(),
        ];
    }
}
