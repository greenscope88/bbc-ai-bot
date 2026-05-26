<?php
declare(strict_types=1);

/**
 * Phase 2-C.4: Maps internal/gateway client param names → Host B /api/tour/search query names.
 * SearchCondition naming is unchanged; conversion happens only at Host B wire boundary.
 */
final class HostBTourSearchParamMapper
{
    /** @var array<string, string> internal/gateway key → Host B Swagger key */
    public const INTERNAL_TO_HOST_B = [
        'dateFrom' => 'TourDateS',
        'dateTo' => 'TourDateE',
        'priceMax' => 'AmountMax',
        'priceMin' => 'AmountMin',
        'departureCity' => 'Departure',
    ];

    /** @var list<string> Keys forwarded on Host B HTTP query (search + paging). */
    public const HOST_B_WIRE_ALLOWLIST = [
        'sno',
        'keyword',
        'destination',
        'country',
        'city',
        'TourDateS',
        'TourDateE',
        'AmountMax',
        'AmountMin',
        'Departure',
        'page',
        'pageSize',
    ];

    /**
     * @param array<string, mixed> $clientParams internal or mixed keys
     * @return array<string, string|int>
     */
    public static function toHostBQueryParams(array $clientParams): array
    {
        $out = [];

        foreach ($clientParams as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                continue;
            }

            $wireKey = self::INTERNAL_TO_HOST_B[$key] ?? $key;
            if (!self::isAllowedWireKey($wireKey)) {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $out[$wireKey] = (int) $value;
                continue;
            }

            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }

            $out[$wireKey] = $wireKey === 'keyword' ? $trimmed : $trimmed;
        }

        return $out;
    }

    /**
     * Audit payload for hybrid_search_api_debug.log (`hostb_param_mapping`).
     *
     * @param array<string, string|int> $internalParams from ApiQueryMapper::toClientParams()
     * @return array<string, string|int>
     */
    public static function mappingAudit(array $internalParams): array
    {
        $audit = [];

        foreach (self::INTERNAL_TO_HOST_B as $internal => $hostb) {
            if (!array_key_exists($internal, $internalParams)) {
                continue;
            }
            $value = $internalParams[$internal];
            if (is_int($value) || (is_string($value) && trim($value) !== '')) {
                $audit[$internal] = $value;
                $audit[$hostb] = $value;
            }
        }

        if (isset($internalParams['keyword']) && trim((string) $internalParams['keyword']) !== '') {
            $audit['keyword'] = trim((string) $internalParams['keyword']);
        }

        return $audit;
    }

    public static function isAllowedWireKey(string $key): bool
    {
        return in_array($key, self::HOST_B_WIRE_ALLOWLIST, true);
    }
}
