<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublishedProductSet.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ShortUrlServiceShortUrlProvider.php';

/**
 * Unique publication-cap owner for BBCShops Flex.
 *
 * Public customer URL owner: ShortUrlService::toPublicShortUrlForItemLink via
 * ShortUrlServiceShortUrlProvider (Production). Fixture primary_url/url is not authority.
 */
final class PublishedProductSetSelector
{
    private const SOURCE_ID = 'bbcshops';
    private const MAX_PUBLISHED_PRODUCTS = 12;

    private TourDetailUrlBuilder $detailUrlBuilder;

    private ShortUrlProviderInterface $shortUrlProvider;

    public function __construct(
        ?TourDetailUrlBuilder $detailUrlBuilder = null,
        ?ShortUrlProviderInterface $shortUrlProvider = null
    ) {
        $this->detailUrlBuilder = $detailUrlBuilder ?? new TourDetailUrlBuilder();
        $this->shortUrlProvider = $shortUrlProvider ?? new ShortUrlServiceShortUrlProvider();
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

        $published = [];
        $seenFactIds = [];
        /** @var array<string, string> $shortUrlByLong */
        $shortUrlByLong = [];

        foreach ($eligibleOrderedProducts as $row) {
            if (count($published) >= self::MAX_PUBLISHED_PRODUCTS) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }

            $couponNo = self::positiveToken($row['couponNo'] ?? null);
            if ($couponNo === null) {
                continue;
            }

            $factId = self::SOURCE_ID . ':' . $storeToken . ':' . $couponNo;
            if (isset($seenFactIds[$factId])) {
                continue;
            }

            $tourSeqNo = self::positiveToken($row['tourSeqNo'] ?? ($row['tour_seq_no'] ?? null));
            if ($tourSeqNo === null) {
                continue;
            }

            $title = self::nonEmptyString($row['title'] ?? ($row['couponName'] ?? ($row['name'] ?? null)));
            if ($title === null) {
                continue;
            }

            $longDetailUrl = $this->detailUrlBuilder->buildDetailUrl($storeToken, $couponNo, $tourSeqNo);
            if ($longDetailUrl === null || trim($longDetailUrl) === '') {
                continue;
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
                continue;
            }

            $item = $row;
            unset($item['url']);
            $item['source_id'] = self::SOURCE_ID;
            $item['storeNo'] = $storeToken;
            $item['couponNo'] = $couponNo;
            $item['tourSeqNo'] = $tourSeqNo;
            $item['title'] = $title;
            $item['primary_url'] = $publicUrl;
            $item['fact_id'] = $factId;
            $published[] = $item;
            $seenFactIds[$factId] = true;
        }

        return new PublishedProductSet($published);
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
