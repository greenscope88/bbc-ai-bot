<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublishedProductSet.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BbcshopsProductImageUrlBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ShortUrlServiceShortUrlProvider.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ProductLineageObservation.php';

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

    /** @var array<string, mixed>|null */
    private ?array $lastLineageTrace = null;

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
            $this->lastLineageTrace = $this->buildEmptyStoreTrace($eligibleOrderedProducts);

            return new PublishedProductSet([]);
        }

        /** @var list<string> $identityOrder */
        $identityOrder = [];
        /** @var array<string, list<array<string, mixed>>> $rowsByIdentity */
        $rowsByIdentity = [];
        /** @var array<int, array<string, mixed>> $pendingDecisions keyed by input index */
        $pendingDecisions = [];
        /** @var array<string, list<int>> $inputIndexesByIdentity */
        $inputIndexesByIdentity = [];

        foreach (array_values($eligibleOrderedProducts) as $inputIndex => $row) {
            if (!is_array($row)) {
                $pendingDecisions[$inputIndex] = [
                    'input_index' => $inputIndex,
                    'stable_key' => 'not_observable',
                    'outcome' => 'drop',
                    'target_key' => null,
                    'reason_code' => 'invalid_input_shape',
                    'aggregated_dates' => [],
                ];
                continue;
            }

            $couponNo = self::positiveToken($row['couponNo'] ?? null);
            if ($couponNo === null) {
                $pendingDecisions[$inputIndex] = [
                    'input_index' => $inputIndex,
                    'stable_key' => 'not_observable',
                    'outcome' => 'drop',
                    'target_key' => null,
                    'reason_code' => 'missing_coupon_no',
                    'aggregated_dates' => [],
                ];
                continue;
            }

            $factId = self::SOURCE_ID . ':' . $storeToken . ':' . $couponNo;
            $isFirstForIdentity = !isset($rowsByIdentity[$factId]);
            if ($isFirstForIdentity) {
                $identityOrder[] = $factId;
                $rowsByIdentity[$factId] = [];
                $inputIndexesByIdentity[$factId] = [];
            }
            $rowsByIdentity[$factId][] = $row;
            $inputIndexesByIdentity[$factId][] = $inputIndex;
            $pendingDecisions[$inputIndex] = [
                'input_index' => $inputIndex,
                'stable_key' => $factId,
                'outcome' => $isFirstForIdentity ? 'pending' : 'aggregate',
                'target_key' => $factId,
                'reason_code' => $isFirstForIdentity ? 'pending' : 'aggregated_into_identity',
                'aggregated_dates' => [],
            ];
        }

        $published = [];
        /** @var array<string, string> $shortUrlByLong */
        $shortUrlByLong = [];
        $processedIdentities = [];

        foreach ($identityOrder as $factId) {
            if (count($published) >= self::MAX_PUBLISHED_PRODUCTS) {
                break;
            }
            $rows = $rowsByIdentity[$factId];
            $item = $this->buildPublishedIdentity($factId, $storeToken, $rows, $shortUrlByLong);
            $processedIdentities[$factId] = true;
            $indexes = $inputIndexesByIdentity[$factId] ?? [];
            if ($item !== null) {
                $dates = isset($item['departure_dates']) && is_array($item['departure_dates'])
                    ? array_values($item['departure_dates'])
                    : [];
                foreach ($indexes as $position => $inputIndex) {
                    $pendingDecisions[$inputIndex] = [
                        'input_index' => $inputIndex,
                        'stable_key' => $factId,
                        'outcome' => $position === 0 ? 'keep' : 'aggregate',
                        'target_key' => $factId,
                        'reason_code' => $position === 0 ? 'kept' : 'aggregated_into_identity',
                        'aggregated_dates' => $dates,
                    ];
                }
                $published[] = $item;
                continue;
            }

            foreach ($indexes as $inputIndex) {
                $pendingDecisions[$inputIndex] = [
                    'input_index' => $inputIndex,
                    'stable_key' => $factId,
                    'outcome' => 'drop',
                    'target_key' => null,
                    'reason_code' => 'publish_validation_failed',
                    'aggregated_dates' => [],
                ];
            }
        }

        foreach ($identityOrder as $factId) {
            if (isset($processedIdentities[$factId])) {
                continue;
            }
            foreach ($inputIndexesByIdentity[$factId] ?? [] as $inputIndex) {
                $pendingDecisions[$inputIndex] = [
                    'input_index' => $inputIndex,
                    'stable_key' => $factId,
                    'outcome' => 'drop',
                    'target_key' => null,
                    'reason_code' => 'cap_exceeded',
                    'aggregated_dates' => [],
                ];
            }
        }

        ksort($pendingDecisions);
        $decisions = array_values($pendingDecisions);
        $inputKeys = [];
        foreach ($decisions as $decision) {
            $key = (string) ($decision['stable_key'] ?? 'not_observable');
            if ($key !== 'not_observable' && !in_array($key, $inputKeys, true)) {
                $inputKeys[] = $key;
            }
        }

        $outputKeys = [];
        foreach ($published as $product) {
            $factId = trim((string) ($product['fact_id'] ?? ''));
            if ($factId !== '') {
                $outputKeys[] = $factId;
            }
        }

        $this->lastLineageTrace = [
            'input_count' => count($decisions),
            'output_count' => count($outputKeys),
            'input_keys' => $inputKeys,
            'output_keys' => $outputKeys,
            'decisions' => $decisions,
        ];

        return new PublishedProductSet($published);
    }

    /**
     * Diagnostic-only trace from the most recent select() call.
     *
     * @return array<string, mixed>|null
     */
    public function getLastLineageTrace(): ?array
    {
        return $this->lastLineageTrace;
    }

    /**
     * @param list<array<string, mixed>> $eligibleOrderedProducts
     * @return array<string, mixed>
     */
    private function buildEmptyStoreTrace(array $eligibleOrderedProducts): array
    {
        $decisions = [];
        foreach (array_values($eligibleOrderedProducts) as $inputIndex => $row) {
            $decisions[] = [
                'input_index' => $inputIndex,
                'stable_key' => 'not_observable',
                'outcome' => 'drop',
                'target_key' => null,
                'reason_code' => 'invalid_store_no',
                'aggregated_dates' => [],
            ];
        }

        return [
            'input_count' => count($decisions),
            'output_count' => 0,
            'input_keys' => [],
            'output_keys' => [],
            'decisions' => $decisions,
        ];
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
