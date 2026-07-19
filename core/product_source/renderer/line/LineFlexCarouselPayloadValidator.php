<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'LineJsonEncoder.php';

final class LineFlexCarouselPayloadValidator
{
    private const MAX_CAROUSEL_JSON_BYTES = 50000;
    private const MAX_ALT_TEXT_LENGTH = 1500;

    /**
     * @param array<string, mixed> $wireFlexMessage
     */
    public function validate(array $wireFlexMessage): void
    {
        if (($wireFlexMessage['type'] ?? '') !== 'flex') {
            throw new \RuntimeException('line_flex_message_type_invalid');
        }

        $altText = isset($wireFlexMessage['altText']) ? trim((string) $wireFlexMessage['altText']) : '';
        if ($altText === '' || mb_strlen($altText, 'UTF-8') > self::MAX_ALT_TEXT_LENGTH) {
            throw new \RuntimeException('line_flex_alt_text_invalid');
        }

        $contents = isset($wireFlexMessage['contents']) && is_array($wireFlexMessage['contents'])
            ? $wireFlexMessage['contents']
            : null;
        if ($contents === null || ($contents['type'] ?? '') !== 'carousel') {
            throw new \RuntimeException('line_flex_contents_not_carousel');
        }

        $bubbles = isset($contents['contents']) && is_array($contents['contents']) ? $contents['contents'] : [];
        $count = count($bubbles);
        if ($count < 1 || $count > 12) {
            throw new \RuntimeException('line_flex_bubble_count_invalid');
        }

        $size = null;
        foreach ($bubbles as $index => $bubble) {
            if (!is_array($bubble) || ($bubble['type'] ?? '') !== 'bubble') {
                throw new \RuntimeException('line_flex_bubble_invalid:' . $index);
            }
            if (!isset($bubble['body']) || !is_array($bubble['body'])) {
                throw new \RuntimeException('line_flex_bubble_body_missing:' . $index);
            }
            $bubbleSize = isset($bubble['size']) ? (string) $bubble['size'] : '';
            if ($size === null) {
                $size = $bubbleSize;
            } elseif ($size !== $bubbleSize) {
                throw new \RuntimeException('line_flex_bubble_width_mismatch');
            }
            $this->assertSlottedUris($bubble);
        }

        $encodedCarousel = LineJsonEncoder::encode($contents);
        if (strlen($encodedCarousel) > self::MAX_CAROUSEL_JSON_BYTES) {
            throw new \RuntimeException('line_flex_carousel_json_too_large');
        }
    }

    /**
     * Schema-slot URI validation only.
     * Roles are defined by fixed positions — never by action label text search.
     *
     * @param array<string, mixed> $bubble
     */
    private function assertSlottedUris(array $bubble): void
    {
        if (!isset($bubble['hero']) || !is_array($bubble['hero'])) {
            throw new \RuntimeException('line_flex_hero_missing');
        }
        $hero = $bubble['hero'];
        if (($hero['type'] ?? '') !== 'image') {
            throw new \RuntimeException('line_flex_hero_missing');
        }
        $heroUrl = isset($hero['url']) ? trim((string) $hero['url']) : '';
        $this->assertHeroImageUrl($heroUrl);

        $footer = isset($bubble['footer']) && is_array($bubble['footer']) ? $bubble['footer'] : null;
        if ($footer === null
            || ($footer['type'] ?? '') !== 'box'
            || ($footer['layout'] ?? '') !== 'horizontal'
            || !isset($footer['contents'])
            || !is_array($footer['contents'])
            || count($footer['contents']) !== 2
        ) {
            throw new \RuntimeException('line_flex_footer_schema_invalid');
        }

        $detailUrl = $this->assertDetailSlot($footer['contents'][0]);
        $this->assertItinerarySlot($footer['contents'][1], $detailUrl);
    }

    /**
     * footer.contents[0] — detail slot. URI role is index 0; label is display-contract only.
     *
     * @param mixed $button
     */
    private function assertDetailSlot($button): string
    {
        if (!is_array($button) || ($button['type'] ?? '') !== 'button') {
            throw new \RuntimeException('line_flex_footer_slot0_invalid');
        }
        if (!isset($button['action']) || !is_array($button['action'])) {
            throw new \RuntimeException('line_flex_footer_slot0_invalid');
        }
        $action = $button['action'];
        if (($action['type'] ?? '') !== 'uri') {
            throw new \RuntimeException('line_flex_footer_slot0_invalid');
        }
        $uri = isset($action['uri']) ? trim((string) $action['uri']) : '';
        // Slot-index URI rule first (independent of label text).
        $this->assertDetailActionUrl($uri);
        $label = isset($action['label']) ? trim((string) $action['label']) : '';
        if ($label !== '詳細內容') {
            throw new \RuntimeException('line_flex_footer_slot0_invalid');
        }

        return $uri;
    }

    /**
     * footer.contents[1] — itinerary slot. URI role is index 1; label is display-contract only.
     *
     * @param mixed $button
     */
    private function assertItinerarySlot($button, string $detailUrl): void
    {
        if (!is_array($button) || ($button['type'] ?? '') !== 'button') {
            throw new \RuntimeException('line_flex_footer_slot1_invalid');
        }
        if (!isset($button['action']) || !is_array($button['action'])) {
            throw new \RuntimeException('line_flex_footer_slot1_invalid');
        }
        $action = $button['action'];
        if (($action['type'] ?? '') !== 'uri') {
            throw new \RuntimeException('line_flex_footer_slot1_invalid');
        }
        $uri = isset($action['uri']) ? trim((string) $action['uri']) : '';
        $this->assertItineraryActionUrl($uri, $detailUrl);
        $label = isset($action['label']) ? trim((string) $action['label']) : '';
        if ($label !== '行程表') {
            throw new \RuntimeException('line_flex_footer_slot1_invalid');
        }
    }

    private function assertHeroImageUrl(string $url): void
    {
        if ($url === '' || stripos($url, 'https://') !== 0) {
            throw new \RuntimeException('line_flex_invalid_hero_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('line_flex_invalid_hero_url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('line_flex_invalid_hero_url');
        }
        $host = isset($parts['host']) ? strtolower(trim((string) $parts['host'])) : '';
        if ($host !== 'kowanbo.com') {
            throw new \RuntimeException('line_flex_invalid_hero_url');
        }
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if (strpos($path, '/pubimg/coupon/') !== 0) {
            throw new \RuntimeException('line_flex_invalid_hero_url');
        }
    }

    private function assertDetailActionUrl(string $url): void
    {
        if ($url === '' || stripos($url, 'https://') !== 0) {
            throw new \RuntimeException('line_flex_invalid_detail_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('line_flex_invalid_detail_url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('line_flex_invalid_detail_url');
        }
        $host = isset($parts['host']) ? strtolower(trim((string) $parts['host'])) : '';
        if ($host !== 'bbcshops.com') {
            throw new \RuntimeException('line_flex_invalid_detail_url');
        }
    }

    private function assertItineraryActionUrl(string $url, string $detailUrl): void
    {
        if ($url === '' || stripos($url, 'https://') !== 0) {
            throw new \RuntimeException('line_flex_invalid_itinerary_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('line_flex_invalid_itinerary_url');
        }
        $host = isset($parts['host']) ? trim((string) $parts['host']) : '';
        if ($host === '') {
            throw new \RuntimeException('line_flex_invalid_itinerary_url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('line_flex_invalid_itinerary_url');
        }
        if ($detailUrl !== '' && $url === $detailUrl) {
            throw new \RuntimeException('line_flex_invalid_itinerary_url');
        }
    }
}
