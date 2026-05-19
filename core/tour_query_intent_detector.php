<?php
declare(strict_types=1);

/**
 * Stage 1-B-13 tour search intent + keyword extraction (rule-based, no API/DB/Gemini).
 */
final class TourQueryIntentDetector
{
    /** @var list<string> */
    private const EXCLUSION_MARKERS = [
        '今天天氣',
        '天氣如何',
        '天氣',
        '氣溫',
        '溫度',
        'weather',
        '護照',
        '簽證',
        '台胞證',
        '訂單',
        '你好',
        '謝謝',
        '感謝',
    ];

    /** @var list<string> */
    private const TOUR_MARKERS = [
        '自由行',
        '行程',
        '旅遊',
        '團',
    ];

    /** @var list<string> */
    private const PREFIX_NOISE = [
        '請問一下',
        '請問',
        '想問',
        '有沒有',
        '想找',
        '想要',
        '幫我找',
        '幫我查',
        '我想找',
        '我想查',
    ];

    /** @var list<string> */
    private const INLINE_NOISE = [
        '有哪些行程',
        '有什麼行程',
        '有哪些',
        '有什麼',
        '旅遊推薦',
        '行程推薦',
        '自由行',
        '行程',
        '旅遊',
        '推薦',
        '相關',
        '一下',
        '嗎',
        '呢',
        '的',
    ];

    /**
     * @return array{
     *   is_tour_query: bool,
     *   keyword: string|null,
     *   confidence: float,
     *   reason: string
     * }
     */
    public function detect(string $message): array
    {
        $text = trim($message);
        if ($text === '') {
            return $this->result(false, null, 0.0, 'empty_message');
        }

        $lower = mb_strtolower($text, 'UTF-8');

        if ($this->matchesExclusion($lower)) {
            return $this->result(false, null, 0.05, 'excluded_non_tour_topic');
        }

        $tourScore = $this->scoreTourIntent($lower);
        if ($tourScore <= 0) {
            return $this->result(false, null, 0.1, 'no_tour_search_signal');
        }

        $keyword = $this->extractKeyword($text);
        if ($keyword === '') {
            return $this->result(false, null, 0.35, 'tour_signal_without_keyword');
        }

        $confidence = min(0.99, 0.55 + $tourScore);

        return $this->result(true, $keyword, $confidence, 'tour_search_detected');
    }

    private function matchesExclusion(string $lower): bool
    {
        foreach (self::EXCLUSION_MARKERS as $marker) {
            if (mb_strpos($lower, mb_strtolower($marker, 'UTF-8')) !== false) {
                return true;
            }
        }

        return false;
    }

    private function scoreTourIntent(string $lower): float
    {
        $score = 0.0;

        foreach (self::TOUR_MARKERS as $marker) {
            if (mb_strpos($lower, mb_strtolower($marker, 'UTF-8')) !== false) {
                $score += 0.35;
            }
        }

        if (preg_match('/\d+\s*日/u', $lower) || mb_strpos($lower, '五日') !== false) {
            $score += 0.35;
        }

        if (mb_strpos($lower, '想找') !== false || mb_strpos($lower, '有沒有') !== false) {
            $score += 0.2;
        }

        return $score;
    }

    private function extractKeyword(string $text): string
    {
        $working = trim($text);

        foreach (self::PREFIX_NOISE as $prefix) {
            if (mb_strpos($working, $prefix) === 0) {
                $working = trim(mb_substr($working, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
            }
        }

        $working = preg_replace('/(?:\d+|[一二三四五六七八九十百千兩]+)\s*日$/u', '', $working) ?? $working;
        $working = trim($working);

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::INLINE_NOISE as $noise) {
                $pos = mb_strpos($working, $noise, 0, 'UTF-8');
                if ($pos !== false) {
                    $working = trim(mb_substr($working, 0, $pos, 'UTF-8') . mb_substr($working, $pos + mb_strlen($noise, 'UTF-8'), null, 'UTF-8'));
                    $changed = true;
                }
            }
            if (mb_substr($working, -1, null, 'UTF-8') === '團') {
                $working = trim(mb_substr($working, 0, -1, 'UTF-8'));
                $changed = true;
            }
        }

        $working = preg_replace('/\s+/u', '', $working) ?? $working;
        $working = trim($working, " \t\n\r\0\x0B，。！？、；：\"'");

        return $working;
    }

    /**
     * @return array{is_tour_query: bool, keyword: string|null, confidence: float, reason: string}
     */
    private function result(bool $isTourQuery, ?string $keyword, float $confidence, string $reason): array
    {
        return [
            'is_tour_query' => $isTourQuery,
            'keyword' => $keyword,
            'confidence' => round($confidence, 2),
            'reason' => $reason,
        ];
    }
}
