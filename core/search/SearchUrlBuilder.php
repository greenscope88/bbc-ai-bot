<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchConditionCanonicalizer.php';

/**
 * Builds cloud_store_tourdate.php URL from SearchCondition (same canonical params as API).
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

        $query = [
            'openExternalBrowser' => '1',
            'UnCarousel' => '1',
            'fromDMDetailFlag' => '1',
            'clearParam' => 'Y',
            'mode' => '1',
            'sno' => $sno,
            'keyword' => $canonical['keyword'],
        ];

        if ($canonical['dateFrom'] !== null && $canonical['dateFrom'] !== '') {
            $query['dateFrom'] = $canonical['dateFrom'];
        }

        if ($canonical['dateTo'] !== null && $canonical['dateTo'] !== '') {
            $query['dateTo'] = $canonical['dateTo'];
        }

        if ($condition->getDepartureCity() !== null && $condition->getDepartureCity() !== '') {
            $query['departureCity'] = $condition->getDepartureCity();
        }

        if ($canonical['destination'] !== null && $canonical['destination'] !== '') {
            $query['destination'] = $canonical['destination'];
        }

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
     * Search params only (for parity checks vs ApiQueryMapper).
     *
     * @return array<string, string>
     */
    public function searchParamsOnly(SearchCondition $condition): array
    {
        $canonical = SearchConditionCanonicalizer::canonicalize($condition);
        $out = ['keyword' => $canonical['keyword']];

        if ($canonical['dateFrom'] !== null) {
            $out['dateFrom'] = $canonical['dateFrom'];
        }
        if ($canonical['dateTo'] !== null) {
            $out['dateTo'] = $canonical['dateTo'];
        }
        if ($canonical['destination'] !== null && $canonical['destination'] !== '') {
            $out['destination'] = $canonical['destination'];
        }

        return $out;
    }
}
