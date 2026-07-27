<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionCanonicalizer.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'SourceQueryMapper.php';

/**
 * Builds cloud_store_tourdate.php URL from SearchCondition.
 * Listing date wire uses Storefront keys departureDateS/E (YYYY/MM/DD), not Host B API keys.
 */
final class SearchUrlBuilder
{
    private const SEARCH_URL_BASE = 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php';

    /** @var bool */
    private $applyShortUrl;

    public function __construct(bool $applyShortUrl = false)
    {
        $this->applyShortUrl = $applyShortUrl;
    }

    /**
     * @param array<string, mixed> $options apply_short_url (bool) overrides constructor
     */
    public function build(string $sno, SearchCondition $condition, array $options = []): string
    {
        $sno = trim($sno);
        $canonical = SearchConditionCanonicalizer::canonicalize($condition);

        $wireKeyword = SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($condition);
        if ($wireKeyword === '') {
            $wireKeyword = $canonical['keyword'];
        }

        $query = [
            'openExternalBrowser' => '1',
            'sno' => $sno,
            'keyword' => $wireKeyword,
            'mode' => '0',
        ];

        $departureDateS = self::toStorefrontDepartureDate($canonical['dateFrom'] ?? null);
        if ($departureDateS !== null) {
            $query['departureDateS'] = $departureDateS;
        }

        $departureDateE = self::toStorefrontDepartureDate($canonical['dateTo'] ?? null);
        if ($departureDateE !== null) {
            $query['departureDateE'] = $departureDateE;
        }

        if ($condition->getDepartureCity() !== null && $condition->getDepartureCity() !== '') {
            $query['departureCity'] = $condition->getDepartureCity();
        }

        $query['UnCarousel'] = '1';
        $query['mcno'] = '0';

        $qs = self::buildStorefrontQueryString($query);
        $longUrl = self::SEARCH_URL_BASE . '?' . $qs;

        $useShort = $this->applyShortUrl;
        if (array_key_exists('apply_short_url', $options)) {
            $useShort = (bool) $options['apply_short_url'];
        }

        if (!$useShort) {
            return $longUrl;
        }

        $shortPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'short_url_service.php';
        if (!is_file($shortPath)) {
            return $longUrl;
        }

        require_once $shortPath;

        return (new \ShortUrlService())->toPublicShortUrl($longUrl);
    }


    /**
     * Storefront listing URL without search keyword (zero-result fallback).
     *
     * @param array<string, mixed> $options apply_short_url (bool) overrides constructor
     */
    public function buildStorefrontListingUrl(string $sno, array $options = []): string
    {
        $sno = trim($sno);
        $query = [
            'openExternalBrowser' => '1',
            'clearParam' => 'Y',
            'sno' => $sno,
        ];

        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $longUrl = self::SEARCH_URL_BASE . '?' . $qs;

        $useShort = $this->applyShortUrl;
        if (array_key_exists('apply_short_url', $options)) {
            $useShort = (bool) $options['apply_short_url'];
        }

        if (!$useShort) {
            return $longUrl;
        }

        $shortPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'short_url_service.php';
        if (!is_file($shortPath)) {
            return $longUrl;
        }

        require_once $shortPath;

        return (new \ShortUrlService())->toPublicShortUrl($longUrl);
    }

    /**
     * Bonusmee listing search params only (same date contract as build()).
     *
     * @return array<string, string>
     */
    public function searchParamsOnly(SearchCondition $condition): array
    {
        $canonical = SearchConditionCanonicalizer::canonicalize($condition);
        $wireKeyword = SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($condition);
        if ($wireKeyword === '') {
            $wireKeyword = $canonical['keyword'];
        }

        $out = ['keyword' => $wireKeyword];

        $departureDateS = self::toStorefrontDepartureDate($canonical['dateFrom'] ?? null);
        if ($departureDateS !== null) {
            $out['departureDateS'] = $departureDateS;
        }
        $departureDateE = self::toStorefrontDepartureDate($canonical['dateTo'] ?? null);
        if ($departureDateE !== null) {
            $out['departureDateE'] = $departureDateE;
        }

        return $out;
    }

    /**
     * Canonical YYYY-MM-DD → Storefront departure date YYYY/MM/DD. Empty/null → omit.
     */
    private static function toStorefrontDepartureDate(?string $canonicalDate): ?string
    {
        if ($canonicalDate === null) {
            return null;
        }
        $trimmed = trim($canonicalDate);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $m) === 1) {
            return $m[1] . '/' . $m[2] . '/' . $m[3];
        }

        return $trimmed;
    }

    /**
     * RFC3986-encode query values, but keep literal "/" only on departureDateS/E.
     *
     * @param array<string, scalar> $query
     */
    private static function buildStorefrontQueryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $name = rawurlencode((string) $key);
            $raw = (string) $value;
            if ($key === 'departureDateS' || $key === 'departureDateE') {
                $parts[] = $name . '=' . $raw;
                continue;
            }
            $parts[] = $name . '=' . rawurlencode($raw);
        }

        return implode('&', $parts);
    }
}
