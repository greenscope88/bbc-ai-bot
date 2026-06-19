<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TravelConsultantPersonaFormatter.php';

/**
 * Phase 9-C-2A: travel consultant persona reply composer (grounded, mock runtime).
 */
final class TravelConsultantPersonaRuntime
{
    private TravelConsultantPersonaFormatter $personaFormatter;

    public function __construct(?TravelConsultantPersonaFormatter $personaFormatter = null)
    {
        $this->personaFormatter = $personaFormatter ?? TravelConsultantPersonaFormatter::createRandomized();
    }

    /**
     * @param array<string, mixed> $recommendationSummary
     */
    public function composeProductRecommendation(array $recommendationSummary): string
    {
        $resultCount = isset($recommendationSummary['result_count'])
            ? max(0, (int) $recommendationSummary['result_count'])
            : 0;
        if ($resultCount <= 0) {
            return $this->composeNoResultsMessage();
        }

        $lines = $this->personaFormatter->openingLines();
        $reason = trim((string) ($recommendationSummary['recommendation_reason'] ?? ''));
        if ($reason !== '') {
            if ($lines !== [] && end($lines) !== '') {
                $lines[] = '';
            }
            $lines[] = $reason;
        }

        $topProducts = isset($recommendationSummary['top_products']) && is_array($recommendationSummary['top_products'])
            ? $recommendationSummary['top_products']
            : [];
        if ($topProducts !== []) {
            $lines[] = '';
        }
        foreach ($topProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $title = trim((string) ($product['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $emoji = trim((string) ($product['display_emoji'] ?? '✈️'));
            $lines[] = $emoji . ' ' . $title;
        }

        $primaryUrl = trim((string) ($recommendationSummary['primary_url'] ?? ''));
        if ($primaryUrl !== '') {
            $lines[] = '';
            $lines[] = '您可以參考以下完整行程：';
            $lines[] = $primaryUrl;
        }

        $hints = isset($recommendationSummary['preference_hints']) && is_array($recommendationSummary['preference_hints'])
            ? $recommendationSummary['preference_hints']
            : [];
        if ($hints !== []) {
            $lines[] = '';
            $lines[] = '如果您偏好：';
            foreach ($hints as $hint) {
                if (!is_string($hint) || trim($hint) === '') {
                    continue;
                }
                $lines[] = '* ' . trim($hint);
            }
        }

        $lines[] = '';
        foreach ($this->personaFormatter->closingLines() as $closingLine) {
            $lines[] = $closingLine;
        }

        return implode("\n", $lines);
    }

    public function composeNoResultsMessage(): string
    {
        return implode("\n", [
            '目前尚未找到符合條件的商品。',
            '',
            '若您願意提供：',
            '',
            '* 日期',
            '* 預算',
            '* 出發地',
            '',
            '我再幫您查詢 😊',
        ]);
    }

    /**
     * @param array<string, mixed> $batsSearchIntent
     */
    public function isProductSearchIntent(array $batsSearchIntent): bool
    {
        if (($batsSearchIntent['clarification_required'] ?? false) === true) {
            return false;
        }

        $intent = isset($batsSearchIntent['intent']) ? trim((string) $batsSearchIntent['intent']) : '';
        if ($intent !== '' && $intent !== 'tour_search') {
            return false;
        }

        $destination = isset($batsSearchIntent['destination']) && is_string($batsSearchIntent['destination'])
            ? trim($batsSearchIntent['destination'])
            : '';
        if ($destination !== '') {
            return true;
        }

        $multi = isset($batsSearchIntent['multi_destination']) && is_array($batsSearchIntent['multi_destination'])
            ? $batsSearchIntent['multi_destination']
            : [];
        if ($multi !== []) {
            return true;
        }

        $freeText = isset($batsSearchIntent['free_text']) ? trim((string) $batsSearchIntent['free_text']) : '';
        if ($freeText === '') {
            return false;
        }

        $travelKeywords = ['團', '行程', '旅遊', '旅遊團', '跟團', '自由行', 'tour'];
        foreach ($travelKeywords as $keyword) {
            if (mb_strpos($freeText, $keyword) !== false) {
                return true;
            }
        }

        $destinations = ['北海道', '東京', '大阪', '京都', '沖繩', '日本', '韓國', '泰國', '歐洲', '美國'];
        foreach ($destinations as $place) {
            if (mb_strpos($freeText, $place) !== false) {
                return true;
            }
        }

        return preg_match('/\d{1,2}\s*月/u', $freeText) === 1;
    }
}
