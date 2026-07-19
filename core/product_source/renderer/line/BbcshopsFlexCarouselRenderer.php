<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'published' . DIRECTORY_SEPARATOR . 'PublishedProductSet.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BbcshopsFlexCarouselRenderResult.php';

final class BbcshopsFlexCarouselRenderer
{
    private const HERO_ASPECT_RATIO = '20:13';
    private const DETAIL_BUTTON_COLOR = '#06C755';
    private const ITINERARY_BUTTON_COLOR = '#1E88E5';
    private const PRICE_AMOUNT_COLOR = '#E53935';
    private const MAX_VISIBLE_DATES = 6;

    public function render(PublishedProductSet $set): BbcshopsFlexCarouselRenderResult
    {
        $products = $set->getProducts();
        $publishedCount = $set->getCount();
        $bubbles = [];
        $factIds = [];
        $index = 0;
        foreach ($products as $product) {
            $factId = trim((string) ($product['fact_id'] ?? ''));
            if ($factId === '') {
                throw new \RuntimeException('bbcshops_flex_product_missing_fact_id');
            }
            ++$index;
            $bubbles[] = $this->buildBubble($product, $index, $publishedCount);
            $factIds[] = $factId;
        }

        if ($bubbles === []) {
            throw new \RuntimeException('bbcshops_flex_empty_carousel');
        }

        return new BbcshopsFlexCarouselRenderResult([
            'type' => 'flex',
            'altText' => 'BBCShops 行程推薦',
            'contents' => [
                'type' => 'carousel',
                'contents' => $bubbles,
            ],
        ], $factIds);
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function buildBubble(array $product, int $index, int $publishedCount): array
    {
        $title = $this->text($product['title'] ?? 'BBCShops 行程');
        $primaryUrl = $this->text($product['primary_url'] ?? '');
        if (!$this->isBbcshopsUrl($primaryUrl)) {
            throw new \RuntimeException('bbcshops_flex_invalid_product_url');
        }
        $itineraryUrl = $this->text($product['itinerary_url'] ?? '');
        if ($itineraryUrl === '' || stripos($itineraryUrl, 'https://') !== 0) {
            throw new \RuntimeException('bbcshops_flex_invalid_itinerary_url');
        }
        $imageUrl = $this->text($product['representative_image_url'] ?? '');
        if ($imageUrl === '' || stripos($imageUrl, 'https://') !== 0) {
            throw new \RuntimeException('bbcshops_flex_invalid_image_url');
        }

        $departure = $this->text($product['departure'] ?? ($product['departureStr'] ?? ''));
        $datesLine = $this->formatDepartureDates($product);
        $durationLine = $this->formatDuration($product);
        $priceBox = $this->buildPriceBox($product);

        $bodyContents = [
            [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'filler',
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $index . '/' . $publishedCount,
                                'size' => 'xs',
                                'color' => '#FFFFFF',
                                'align' => 'center',
                            ],
                        ],
                        'backgroundColor' => '#111111',
                        'cornerRadius' => '4px',
                        'paddingAll' => '4px',
                        'paddingStart' => '6px',
                        'paddingEnd' => '6px',
                    ],
                ],
            ],
            [
                'type' => 'text',
                'text' => $title,
                'weight' => 'bold',
                'wrap' => true,
                'maxLines' => 3,
                'size' => 'md',
            ],
            [
                'type' => 'text',
                'text' => $datesLine,
                'wrap' => true,
                'size' => 'sm',
            ],
        ];

        if ($durationLine !== null) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => $durationLine,
                'wrap' => true,
                'size' => 'sm',
            ];
        }

        $bodyContents[] = [
            'type' => 'text',
            'text' => '出發地：' . ($departure !== '' ? $departure : '請點選查看'),
            'wrap' => true,
            'size' => 'sm',
        ];
        $bodyContents[] = $priceBox;

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'hero' => [
                'type' => 'image',
                'url' => $imageUrl,
                'size' => 'full',
                'aspectRatio' => self::HERO_ASPECT_RATIO,
                'aspectMode' => 'cover',
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents,
                'spacing' => 'sm',
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => self::DETAIL_BUTTON_COLOR,
                        'flex' => 1,
                        'action' => [
                            'type' => 'uri',
                            'label' => '詳細內容',
                            'uri' => $primaryUrl,
                        ],
                    ],
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => self::ITINERARY_BUTTON_COLOR,
                        'flex' => 1,
                        'action' => [
                            'type' => 'uri',
                            'label' => '行程表',
                            'uri' => $itineraryUrl,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function formatDepartureDates(array $product): string
    {
        $dates = [];
        if (isset($product['departure_dates']) && is_array($product['departure_dates'])) {
            foreach ($product['departure_dates'] as $date) {
                if (!is_scalar($date)) {
                    continue;
                }
                $normalized = trim((string) $date);
                if ($normalized !== '') {
                    $dates[] = $normalized;
                }
            }
        } elseif (isset($product['tourDate']) && is_scalar($product['tourDate'])) {
            $dates[] = trim((string) $product['tourDate']);
        }

        $display = [];
        foreach (array_slice($dates, 0, self::MAX_VISIBLE_DATES) as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1) {
                $display[] = $m[2] . '/' . $m[3];
            } elseif (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date, $m) === 1) {
                $display[] = sprintf('%02d/%02d', (int) $m[2], (int) $m[3]);
            }
        }

        if ($display === []) {
            return '出發日期：請點選查看';
        }

        return '出發日期：' . implode('、', $display);
    }

    /**
     * @param array<string, mixed> $product
     */
    private function formatDuration(array $product): ?string
    {
        $days = $product['duration_days'] ?? null;
        if (is_int($days) && $days > 0) {
            return '旅遊天數：' . $days . ' 日';
        }
        if (is_string($days) && preg_match('/^\d+$/', trim($days)) === 1 && (int) trim($days) > 0) {
            return '旅遊天數：' . ((int) trim($days)) . ' 日';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function buildPriceBox(array $product): array
    {
        $raw = $product['price'] ?? ($product['retailPrice'] ?? null);
        $amount = $this->formatPriceAmount($raw);
        if ($amount === null) {
            return [
                'type' => 'text',
                'text' => '價格：請點選查看',
                'wrap' => true,
                'size' => 'sm',
            ];
        }

        return [
            'type' => 'box',
            'layout' => 'baseline',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => 'NT$',
                    'size' => 'sm',
                    'color' => '#111111',
                    'flex' => 0,
                ],
                [
                    'type' => 'text',
                    'text' => $amount,
                    'size' => 'xl',
                    'weight' => 'bold',
                    'color' => self::PRICE_AMOUNT_COLOR,
                    'flex' => 0,
                ],
                [
                    'type' => 'text',
                    'text' => ' 起',
                    'size' => 'sm',
                    'color' => '#111111',
                    'flex' => 0,
                ],
            ],
        ];
    }

    /**
     * @param mixed $value
     */
    private function formatPriceAmount($value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return number_format($value, 0, '.', ',');
        }
        if (is_float($value) && $value >= 0) {
            return number_format((int) round($value), 0, '.', ',');
        }
        if (is_string($value)) {
            $digits = preg_replace('/[^\d]/', '', $value);
            if ($digits !== null && $digits !== '' && preg_match('/^\d+$/', $digits) === 1) {
                return number_format((int) $digits, 0, '.', ',');
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function isBbcshopsUrl(string $url): bool
    {
        return preg_match('#^https://(?:www\.)?bbcshops\.com(?:/|$)#i', $url) === 1;
    }
}
