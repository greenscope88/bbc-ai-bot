<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'published' . DIRECTORY_SEPARATOR . 'PublishedProductSet.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BbcshopsFlexCarouselRenderResult.php';

final class BbcshopsFlexCarouselRenderer
{
    public function render(PublishedProductSet $set): BbcshopsFlexCarouselRenderResult
    {
        $bubbles = [];
        $factIds = [];
        foreach ($set->getProducts() as $product) {
            $factId = trim((string) ($product['fact_id'] ?? ''));
            if ($factId === '') {
                throw new \RuntimeException('bbcshops_flex_product_missing_fact_id');
            }
            $bubbles[] = $this->buildBubble($product);
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
    private function buildBubble(array $product): array
    {
        $title = $this->text($product['title'] ?? 'BBCShops 行程');
        $url = $this->text($product['primary_url'] ?? '');
        if (!$this->isBbcshopsUrl($url)) {
            throw new \RuntimeException('bbcshops_flex_invalid_product_url');
        }

        $summary = $this->text($product['summary'] ?? ($product['description'] ?? ''));
        $price = $this->text($product['price'] ?? ($product['retailPrice'] ?? ''));
        $departure = $this->text($product['departure'] ?? ($product['departureStr'] ?? ''));
        $dates = $this->text($product['departure_dates'] ?? ($product['tourDate'] ?? ''));

        $bodyContents = [
            [
                'type' => 'text',
                'text' => $this->truncate($title, 80),
                'weight' => 'bold',
                'wrap' => true,
            ],
        ];
        foreach ([
            '出發日期：' . ($dates !== '' ? $dates : '請點選查看'),
            '出發地：' . ($departure !== '' ? $departure : '請點選查看'),
            '價格：' . ($price !== '' ? $price : '請點選查看'),
        ] as $line) {
            $bodyContents[] = ['type' => 'text', 'text' => $this->truncate($line, 120), 'wrap' => true, 'size' => 'sm'];
        }
        if ($summary !== '') {
            $bodyContents[] = ['type' => 'text', 'text' => $this->truncate($summary, 160), 'wrap' => true, 'size' => 'sm'];
        }

        return [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents,
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'action' => [
                            'type' => 'uri',
                            'label' => '查看行程',
                            'uri' => $url,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param mixed $value
     */
    private function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value, 'UTF-8') > $length ? mb_substr($value, 0, $length, 'UTF-8') : $value;
    }

    private function isBbcshopsUrl(string $url): bool
    {
        return preg_match('#^https://(?:www\.)?bbcshops\.com(?:/|$)#i', $url) === 1;
    }
}
