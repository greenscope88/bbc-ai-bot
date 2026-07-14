<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Single source of truth: map SearchCondition → canonical search parameters
 * shared by ApiQueryMapper and SearchUrlBuilder (Phase 2-B).
 */
final class SearchConditionCanonicalizer
{
    /** Max keyword length sent to API / storefront (CPU + safety). */
    private const MAX_KEYWORD_LENGTH = 48;

    /** @var array<string, string> area → country field when Host B supports country */
    private const AREA_AS_COUNTRY = [
        '日本' => '日本',
        '韓國' => '韓國',
        '東南亞' => '東南亞',
    ];

    /**
     * @return array{
     *   keyword: string,
     *   destination: list<string>,
     *   country: ?string,
     *   city: ?string,
     *   dateFrom: ?string,
     *   dateTo: ?string,
     *   budget_min: ?int,
     *   budget_max: ?int,
     *   area_fallback: bool
     * }
     */
    public static function canonicalize(SearchCondition $condition): array
    {
        $destination = $condition->getDestination();
        $area = $condition->getArea();
        $keyword = $condition->getKeyword();
        $areaFallback = false;

        $resolvedKeyword = self::resolveKeyword(
            $keyword,
            $destination,
            $area,
            $condition->getSpecialTags(),
            $condition->getMustHave(),
            $areaFallback
        );
        $resolvedKeyword = self::truncateKeyword($resolvedKeyword);

        $country = null;
        $city = null;
        if ($destination !== []) {
            $city = count($destination) === 1 ? $destination[0] : implode(' ', $destination);
            if ($area !== null && isset(self::AREA_AS_COUNTRY[$area])) {
                $country = self::AREA_AS_COUNTRY[$area];
            }
        } elseif ($area !== null && $area !== '') {
            if (isset(self::AREA_AS_COUNTRY[$area])) {
                $country = $area;
            }
        }

        if ($area !== null && $area !== '' && $country === null) {
            $areaFallback = true;
        }

        return [
            'keyword' => $resolvedKeyword,
            'destination' => $destination,
            'country' => $country,
            'city' => $city,
            'dateFrom' => $condition->getDateFrom(),
            'dateTo' => $condition->getDateTo(),
            'budget_min' => $condition->getBudgetMin(),
            'budget_max' => $condition->getBudgetMax(),
            'area_fallback' => $areaFallback,
        ];
    }

    /**
     * @param list<string> $specialTags
     * @param list<string> $mustHave
     */
    private static function resolveKeyword(
        ?string $keyword,
        array $destination,
        ?string $area,
        array $specialTags,
        array $mustHave,
        bool &$areaFallback
    ): string {
        $mustHaveText = self::joinMustHave($mustHave);
        $k = $keyword !== null ? trim($keyword) : '';
        if ($k !== '') {
            return self::appendMustHave($k, $mustHaveText);
        }

        if ($destination !== []) {
            return self::appendMustHave(implode(' ', $destination), $mustHaveText);
        }

        if ($area !== null && trim($area) !== '') {
            $areaFallback = true;

            return self::appendMustHave(trim($area), $mustHaveText);
        }

        if ($specialTags !== []) {
            return self::appendMustHave(trim($specialTags[0]), $mustHaveText);
        }

        if ($mustHaveText !== '') {
            return $mustHaveText;
        }

        return '';
    }

    /**
     * @param list<string> $mustHave
     */
    private static function joinMustHave(array $mustHave): string
    {
        $parts = [];
        foreach ($mustHave as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $parts[] = $s;
            }
        }

        return implode(' ', array_values(array_unique($parts)));
    }

    private static function appendMustHave(string $keyword, string $mustHaveText): string
    {
        if ($mustHaveText === '') {
            return $keyword;
        }
        if ($keyword === '') {
            return $mustHaveText;
        }
        if (mb_strpos($keyword, $mustHaveText, 0, 'UTF-8') !== false) {
            return $keyword;
        }

        return $keyword . ' ' . $mustHaveText;
    }

    private static function truncateKeyword(string $keyword): string
    {
        if ($keyword === '') {
            return '';
        }

        if (mb_strlen($keyword, 'UTF-8') <= self::MAX_KEYWORD_LENGTH) {
            return $keyword;
        }

        return mb_substr($keyword, 0, self::MAX_KEYWORD_LENGTH, 'UTF-8');
    }
}
