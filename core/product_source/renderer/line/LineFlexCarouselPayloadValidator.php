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
            $this->assertBbcshopsUrlsOnly($bubble);
        }

        $encodedCarousel = LineJsonEncoder::encode($contents);
        if (strlen($encodedCarousel) > self::MAX_CAROUSEL_JSON_BYTES) {
            throw new \RuntimeException('line_flex_carousel_json_too_large');
        }
    }

    /**
     * @param mixed $value
     */
    private function assertBbcshopsUrlsOnly($value): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $nested) {
            if ($key === 'uri' && is_scalar($nested)) {
                $url = trim((string) $nested);
                if (preg_match('#^https?://#i', $url) === 1
                    && preg_match('#^https://(?:www\.)?bbcshops\.com(?:/|$)#i', $url) !== 1) {
                    throw new \RuntimeException('line_flex_invalid_public_url');
                }
            }
            $this->assertBbcshopsUrlsOnly($nested);
        }
    }
}
