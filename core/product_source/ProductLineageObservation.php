<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logger.php';

/**
 * B0-LINE-01D-3G: diagnostic-only product lineage observability.
 * Never feeds routing, search, eligibility, aggregation, Flex, or LINE reply behavior.
 */
final class ProductLineageObservation
{
    private const LOG_FILE = 'saas_router.log';
    private const HOST_B_SOURCE_ID = 'hostb';
    private const PUBLISHED_SOURCE_ID = 'bbcshops';
    private const MAX_TITLE_LENGTH = 120;

    /** @var list<string> */
    private const FORBIDDEN_LOG_KEYS = [
        'api_key',
        'authorization',
        'reply_token',
        'replyToken',
        'access_token',
        'user_id',
        'userId',
        'line_user_id',
        'conversation_id',
        'email',
        'phone',
        'description',
        'raw_response',
        'request_payload',
        'utterance',
        'message_text',
    ];

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function emitHostBResponse(string $traceId, array $items, $storeNo): void
    {
        self::emit('product_lineage_host_b_response', self::buildHostBResponseEvent($traceId, $items, $storeNo));
    }

    /**
     * @param array<string, mixed> $trace
     */
    public static function emitPublishedProductSet(string $traceId, array $trace): void
    {
        $event = $trace;
        $event['trace_id'] = $traceId;
        $event['stage'] = 'published_product_set';
        self::emit('product_lineage_published_product_set', $event);
    }

    /**
     * @param list<array<string, mixed>> $publishedProducts
     * @param list<string> $bubbleFactIds
     */
    public static function emitFlexCarousel(string $traceId, array $publishedProducts, array $bubbleFactIds): void
    {
        self::emit(
            'product_lineage_flex_carousel',
            self::buildFlexCarouselEvent($traceId, $publishedProducts, $bubbleFactIds)
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function buildHostBResponseEvent(string $traceId, array $items, $storeNo): array
    {
        $storeToken = self::positiveToken($storeNo);
        $observed = [];

        foreach (array_values($items) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = self::resolveHostBIdentity($row, $storeToken);
            $observed[] = [
                'response_index' => $index,
                'stable_key' => $identity['stable_key'],
                'coupon_no' => $identity['coupon_no'],
                'tour_seq_no' => $identity['tour_seq_no'],
                'source_id' => self::HOST_B_SOURCE_ID,
                'title' => self::safeTitle($row),
                'area' => self::safeArea($row),
                'destination' => self::safeDestination($row),
                'departure_date' => self::safeDepartureDate($row),
            ];
        }

        return [
            'trace_id' => $traceId !== '' ? $traceId : null,
            'stage' => 'host_b_response',
            'item_count' => count($observed),
            'items' => $observed,
        ];
    }

    /**
     * @param list<array<string, mixed>> $publishedProducts
     * @param list<string> $bubbleFactIds
     * @return array<string, mixed>
     */
    public static function buildFlexCarouselEvent(
        string $traceId,
        array $publishedProducts,
        array $bubbleFactIds
    ): array {
        $productsByKey = [];
        foreach ($publishedProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $factId = trim((string) ($product['fact_id'] ?? ''));
            if ($factId === '') {
                continue;
            }
            $productsByKey[$factId] = $product;
        }

        $bubbles = [];
        foreach (array_values($bubbleFactIds) as $index => $factId) {
            $factId = trim((string) $factId);
            $product = $productsByKey[$factId] ?? null;
            $dates = [];
            if (is_array($product) && isset($product['departure_dates']) && is_array($product['departure_dates'])) {
                foreach ($product['departure_dates'] as $date) {
                    if (is_string($date) && trim($date) !== '') {
                        $dates[] = trim($date);
                    }
                }
            }

            $bubbles[] = [
                'bubble_index' => $index + 1,
                'stable_key' => $factId !== '' ? $factId : 'not_observable',
                'published_set_key' => $factId !== '' ? $factId : 'not_observable',
                'title' => is_array($product) ? self::safeTitle($product) : 'not_observable',
                'departure_dates' => $dates !== [] ? $dates : ['not_observable'],
            ];
        }

        return [
            'trace_id' => $traceId !== '' ? $traceId : null,
            'stage' => 'flex_carousel',
            'bubble_count' => count($bubbles),
            'bubbles' => $bubbles,
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function emit(string $step, array $event): void
    {
        try {
            Logger::log(self::LOG_FILE, $step, self::sanitizeEvent($event));
        } catch (\Throwable $e) {
            // Never break LINE flow.
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{stable_key: string, coupon_no: string, tour_seq_no: string}
     */
    public static function resolveHostBIdentity(array $row, ?string $storeToken): array
    {
        $couponNo = self::positiveToken($row['couponNo'] ?? null);
        $tourSeqNo = self::positiveToken($row['tourSeqNo'] ?? ($row['tour_seq_no'] ?? null));
        $rowStore = self::positiveToken($row['storeNo'] ?? null) ?? $storeToken;

        if ($couponNo === null || $rowStore === null) {
            return [
                'stable_key' => 'not_observable',
                'coupon_no' => $couponNo ?? 'not_observable',
                'tour_seq_no' => $tourSeqNo ?? 'not_observable',
            ];
        }

        return [
            'stable_key' => self::HOST_B_SOURCE_ID . ':' . $rowStore . ':' . $couponNo,
            'coupon_no' => $couponNo,
            'tour_seq_no' => $tourSeqNo ?? 'not_observable',
        ];
    }

    public static function buildPublishedStableKey(string $storeToken, string $couponNo): string
    {
        return self::PUBLISHED_SOURCE_ID . ':' . $storeToken . ':' . $couponNo;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function safeTitle(array $row): string
    {
        foreach (['title', 'couponName', 'name'] as $field) {
            if (!isset($row[$field]) || !is_scalar($row[$field])) {
                continue;
            }
            $text = trim((string) $row[$field]);
            if ($text === '') {
                continue;
            }

            return self::truncate($text, self::MAX_TITLE_LENGTH);
        }

        return 'not_observable';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function safeArea(array $row): string
    {
        foreach (['areaNames', 'area', 'areaName'] as $field) {
            if (!isset($row[$field]) || !is_scalar($row[$field])) {
                continue;
            }
            $text = trim((string) $row[$field]);
            if ($text !== '') {
                return self::truncate($text, 80);
            }
        }

        return 'not_observable';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function safeDestination(array $row): string
    {
        foreach (['destination', 'country', 'city'] as $field) {
            if (!isset($row[$field]) || !is_scalar($row[$field])) {
                continue;
            }
            $text = trim((string) $row[$field]);
            if ($text !== '') {
                return self::truncate($text, 80);
            }
        }

        return 'not_observable';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function safeDepartureDate(array $row): string
    {
        if (!isset($row['tourDate']) || !is_scalar($row['tourDate'])) {
            return 'not_observable';
        }

        $text = trim((string) $row['tourDate']);

        return $text !== '' ? $text : 'not_observable';
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function sanitizeEvent(array $event): array
    {
        $out = [];
        foreach ($event as $key => $value) {
            // Preserve PHP list indices (0..N-1) and order; only string keys use forbidden filter.
            if (is_int($key)) {
                if (is_array($value)) {
                    $out[$key] = self::sanitizeEvent($value);
                } else {
                    $out[$key] = $value;
                }
                continue;
            }
            if (!is_string($key)) {
                continue;
            }
            if (in_array(strtolower($key), self::FORBIDDEN_LOG_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::sanitizeEvent($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
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

    private static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
}
