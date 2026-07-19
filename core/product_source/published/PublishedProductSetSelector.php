<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublishedProductSet.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BbcshopsProductImageUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ShortUrlServiceShortUrlProvider.php';

/**
 * Unique publication-cap owner for BBCShops Flex.
 *
 * Public customer URL owner: ShortUrlService::toPublicShortUrlForItemLink via
 * ShortUrlServiceShortUrlProvider (Production). Fixture primary_url/url is not authority.
 * Product image URL owner: BbcshopsProductImageUrlBuilder.
 */
final class PublishedProductSetSelector
{
    private const SOURCE_ID = 'bbcshops';
    private const MAX_PUBLISHED_PRODUCTS = 12;

    private TourDetailUrlBuilder $detailUrlBuilder;

    private ShortUrlProviderInterface $shortUrlProvider;

    private BbcshopsProductImageUrlBuilder $imageUrlBuilder;

    public function __construct(
        ?TourDetailUrlBuilder $detailUrlBuilder = null,
        ?ShortUrlProviderInterface $shortUrlProvider = null,
        ?BbcshopsProductImageUrlBuilder $imageUrlBuilder = null
    ) {
        $this->detailUrlBuilder = $detailUrlBuilder ?? new TourDetailUrlBuilder();
        $this->shortUrlProvider = $shortUrlProvider ?? new ShortUrlServiceShortUrlProvider();
        $this->imageUrlBuilder = $imageUrlBuilder ?? new BbcshopsProductImageUrlBuilder();
    }

    /**
     * @param list<array<string, mixed>> $eligibleOrderedProducts
     * @param int|string|null $storeNo
     */
    public function select(array $eligibleOrderedProducts, $storeNo): PublishedProductSet
    {
        $storeToken = self::positiveToken($storeNo);
        if ($storeToken === null) {
            return new PublishedProductSet([]);
        }

        /** @var list<string> $identityOrder */
        $identityOrder = [];
        /** @var array<string, list<array<string, mixed>>> $rowsByIdentity */
        $rowsByIdentity = [];

        foreach ($eligibleOrderedProducts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $couponNo = self::positiveToken($row['couponNo'] ?? null);
            if ($couponNo === null) {
                continue;
            }
            $factId = self::SOURCE_ID . ':' . $storeToken . ':' . $couponNo;
            if (!isset($rowsByIdentity[$factId])) {
                $identityOrder[] = $factId;
                $rowsByIdentity[$factId] = [];
            }
            $rowsByIdentity[$factId][] = $row;
        }

        $published = [];
        /** @var array<string, string> $shortUrlByLong */
        $shortUrlByLong = [];

        foreach ($identityOrder as $factId) {
            if (count($published) >= self::MAX_PUBLISHED_PRODUCTS) {
                break;
            }
            $rows = $rowsByIdentity[$factId];
            $item = $this->buildPublishedIdentity($factId, $storeToken, $rows, $shortUrlByLong);
            if ($item !== null) {
                $published[] = $item;
            }
        }

        return new PublishedProductSet($published);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $shortUrlByLong
     * @return array<string, mixed>|null
     */
    private function buildPublishedIdentity(
        string $factId,
        string $storeToken,
        array $rows,
        array &$shortUrlByLong
    ): ?array {
        $couponNo = self::positiveToken($rows[0]['couponNo'] ?? null);
        if ($couponNo === null) {
            return null;
        }

        $title = null;
        $tourSeqNo = null;
        $departure = null;
        $price = null;
        $retailPrice = null;
        $durationDays = null;
        $representativeImageUrl = null;
        $baseRow = null;

        /** @var array<string, true> $dateKeys */
        $dateKeys = [];
        /** @var list<string> $datesAsc */
        $datesAsc = [];
        /** @var array<string, true> $itinerarySeen */
        $itinerarySeen = [];
        /** @var list<string> $itineraryUrls */
        $itineraryUrls = [];

        foreach ($rows as $row) {
            if ($baseRow === null) {
                $baseRow = $row;
            }
            if ($title === null) {
                $title = self::nonEmptyString($row['title'] ?? ($row['couponName'] ?? ($row['name'] ?? null)));
            }
            if ($tourSeqNo === null) {
                $tourSeqNo = self::positiveToken($row['tourSeqNo'] ?? ($row['tour_seq_no'] ?? null));
            }
            if ($departure === null) {
                $departure = self::nonEmptyString($row['departureStr'] ?? ($row['departure'] ?? null));
            }
            if ($price === null && isset($row['price'])) {
                $price = $row['price'];
            }
            if ($retailPrice === null && isset($row['retailPrice'])) {
                $retailPrice = $row['retailPrice'];
            }
            if ($durationDays === null) {
                $durationDays = self::positiveDurationDays($row['tourDays'] ?? null);
            }
            if ($representativeImageUrl === null) {
                $imageStore = self::positiveToken($row['storeNo'] ?? null) ?? $storeToken;
                $built = $this->imageUrlBuilder->build(
                    $row['dmFile'] ?? null,
                    $row['depID'] ?? null,
                    $imageStore,
                    $row['couponAttr'] ?? null
                );
                if ($built !== null) {
                    $representativeImageUrl = $built;
                }
            }

            $normalizedDate = self::normalizeTourDate($row['tourDate'] ?? null);
            if ($normalizedDate !== null && !isset($dateKeys[$normalizedDate])) {
                $dateKeys[$normalizedDate] = true;
                $datesAsc[] = $normalizedDate;
            }

            foreach (self::extractSchLinkCandidates($row) as $candidate) {
                if (!isset($itinerarySeen[$candidate])) {
                    $itinerarySeen[$candidate] = true;
                    $itineraryUrls[] = $candidate;
                }
            }
        }

        if ($title === null
            || $tourSeqNo === null
            || $departure === null
            || $representativeImageUrl === null
            || $datesAsc === []
            || $itineraryUrls === []
        ) {
            return null;
        }

        sort($datesAsc, SORT_STRING);

        $itineraryUrl = null;
        foreach ($itineraryUrls as $candidate) {
            if (self::isValidHttpsItineraryUrl($candidate)) {
                $itineraryUrl = $candidate;
                break;
            }
        }
        if ($itineraryUrl === null) {
            return null;
        }

        $longDetailUrl = $this->detailUrlBuilder->buildDetailUrl($storeToken, $couponNo, $tourSeqNo);
        if ($longDetailUrl === null || trim($longDetailUrl) === '') {
            return null;
        }

        if (isset($shortUrlByLong[$longDetailUrl])) {
            $publicUrl = $shortUrlByLong[$longDetailUrl];
        } else {
            $publicUrl = trim($this->shortUrlProvider->shortenDetailUrl($longDetailUrl, [
                'source_id' => self::SOURCE_ID,
                'short_url_domain' => 'bbcshops.com',
                'domain_namespace' => 'bbcshops',
                'product_category' => 'group_tour',
            ]));
            $shortUrlByLong[$longDetailUrl] = $publicUrl;
        }

        if (!self::isValidBbcshopsCustomerUrl($publicUrl)) {
            return null;
        }

        $item = is_array($baseRow) ? $baseRow : [];
        unset($item['url']);
        $item['source_id'] = self::SOURCE_ID;
        $item['storeNo'] = $storeToken;
        $item['couponNo'] = $couponNo;
        $item['tourSeqNo'] = $tourSeqNo;
        $item['title'] = $title;
        $item['primary_url'] = $publicUrl;
        $item['fact_id'] = $factId;
        $item['representative_image_url'] = $representativeImageUrl;
        $item['departure'] = $departure;
        $item['departure_dates'] = $datesAsc;
        $item['itinerary_url'] = $itineraryUrl;
        $item['itinerary_urls'] = $itineraryUrls;
        $item['duration_days'] = $durationDays;
        if ($price !== null) {
            $item['price'] = $price;
        }
        if ($retailPrice !== null) {
            $item['retailPrice'] = $retailPrice;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function extractSchLinkCandidates(array $row): array
    {
        $out = [];
        if (isset($row['schLinks']) && is_array($row['schLinks'])) {
            foreach ($row['schLinks'] as $link) {
                if (!is_scalar($link)) {
                    continue;
                }
                $text = trim((string) $link);
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }
        $scalar = self::nonEmptyString($row['schLink'] ?? null);
        if ($scalar !== null) {
            $out[] = $scalar;
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function normalizeTourDate($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $text, $m) !== 1) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if (!checkdate($mo, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /**
     * @param mixed $value
     */
    private static function positiveDurationDays($value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_float($value) && $value > 0 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $n = (int) trim($value);

            return $n > 0 ? $n : null;
        }

        return null;
    }

    private static function isValidHttpsItineraryUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = isset($parts['host']) ? trim((string) $parts['host']) : '';
        if ($host === '') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return true;
    }

    /**
     * @param mixed $value
     */
    private static function positiveToken($value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_float($value) && $value > 0) {
            return (string) (int) $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1 && (int) trim($value) > 0) {
            return (string) (int) trim($value);
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function nonEmptyString($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * Customer-visible URL: https://bbcshops.com/{non-empty-code}
     */
    private static function isValidBbcshopsCustomerUrl(string $url): bool
    {
        return preg_match('~^https://bbcshops\.com/[^/\s?#]+$~', trim($url)) === 1;
    }
}
