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
        '匯率',
        '你好',
        '謝謝',
        '感謝',
        '早安',
        '晚安',
    ];

    /** Whole-message exact match only (avoid bare-destination false positives). */
    private const EXACT_NON_TOUR_PHRASES = [
        '你好',
        '謝謝',
        '感謝',
        '早安',
        '晚安',
        '天氣',
        '匯率',
        '訂單',
        '護照',
        '簽證',
    ];

    /** Min/max Han character length for standalone destination names (e.g. 東京, 北海道). */
    private const BARE_DESTINATION_MIN_LEN = 2;
    private const BARE_DESTINATION_MAX_LEN = 3;

    /** @var list<string> */
    private const TOUR_MARKERS = [
        '自由行',
        '行程',
        '旅遊',
        '團',
    ];

    /**
     * 口語前綴（句首，最長優先；可反覆剝除）。不含「有沒有／有」——見 QUERY_MOOD_START。
     *
     * @var list<string>
     */
    private const PREFIX_NOISE = [
        '順便幫我找一下',
        '請幫我找一下',
        '請幫我看一下',
        '請幫我查一下',
        '請幫我看看',
        '順便幫我找',
        '幫我找一下',
        '幫我看一下',
        '幫我查一下',
        '幫我看看',
        '請幫我找',
        '請幫我看',
        '請幫我查',
        '幫我找',
        '幫我看',
        '幫我查',
        '你們有沒有',
        '請問一下',
        '推薦一下',
        '請推薦',
        '順便找一下',
        '我想找',
        '我想看',
        '我想查',
        '你們有',
        '找一下',
        '查一下',
        '看一下',
        '請找',
        '推薦',
        '請問',
        '想問',
        '想找',
        '想要',
        '順便找',
        '找',
        '看看',
        '順便',
    ];

    /**
     * 查詢語氣（句首可重複剝除，最長優先）。
     *
     * @var list<string>
     */
    private const QUERY_MOOD_START = [
        '有沒有',
        '看一下',
        '找一下',
        '查一下',
        '看看',
        '有',
    ];

    /** @var list<string> Trailing tokens removed after core phrase is preserved (e.g. 日本旅遊 → 日本). */
    private const SUFFIX_NOISE = [
        '有嗎',
        '行程',
        '旅遊',
        '旅行',
        '便宜',
        '嗎',
        '呢',
    ];

    /** @var list<string> */
    private const INLINE_NOISE = [
        '有哪些行程',
        '有什麼行程',
        '有哪些',
        '有什麼',
        '旅遊推薦',
        '行程推薦',
        '便宜的',
        '便宜',
        '推薦',
        '相關',
        '一下',
        '嗎',
        '呢',
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
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $text = trim($message);
        if ($text === '') {
            return $this->result(false, null, 0.0, 'empty_message');
        }

        $lower = mb_strtolower($text, 'UTF-8');

        if ($this->matchesExclusion($lower)) {
            return $this->result(false, null, 0.05, 'excluded_non_tour_topic');
        }

        $bareKeyword = $this->detectBareDestinationKeyword($text);
        if ($bareKeyword !== null) {
            return $this->result(true, $bareKeyword, 0.85, 'bare_destination_name');
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

    /**
     * Standalone Chinese destination (e.g. 東京, 北海道) with no 行程/旅遊 markers.
     */
    private function detectBareDestinationKeyword(string $text): ?string
    {
        $working = trim($text);
        $working = preg_replace('/\s+/u', '', $working) ?? $working;
        $working = preg_replace('/^[，。！？、；："\']+|[，。！？、；："\']+$/u', '', $working) ?? $working;
        $working = trim($working);

        if ($working === '') {
            return null;
        }

        foreach (self::EXACT_NON_TOUR_PHRASES as $phrase) {
            if ($working === $phrase) {
                return null;
            }
        }

        $len = mb_strlen($working, 'UTF-8');
        if ($len < self::BARE_DESTINATION_MIN_LEN || $len > self::BARE_DESTINATION_MAX_LEN) {
            return null;
        }

        if (preg_match('/^\p{Han}+$/u', $working) !== 1) {
            return null;
        }

        if (preg_match('/(?:團|遊)$/u', $working) === 1) {
            return null;
        }

        foreach (['旅遊', '行程', '旅行'] as $marker) {
            if (mb_strpos($working, $marker, 0, 'UTF-8') !== false) {
                return null;
            }
        }

        return $working;
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

        if (mb_strpos($lower, '想找') !== false
            || mb_strpos($lower, '有沒有') !== false
            || mb_strpos($lower, '你們有沒有') !== false
            || mb_strpos($lower, '請幫我找') !== false
            || mb_strpos($lower, '幫我找') !== false
            || mb_strpos($lower, '請找') !== false
            || mb_strpos($lower, '看一下') !== false
            || mb_strpos($lower, '查一下') !== false
            || mb_strpos($lower, '找一下') !== false
            || mb_strpos($lower, '幫我看看') !== false
            || mb_strpos($lower, '幫我看') !== false
            || mb_strpos($lower, '幫我查') !== false
            || mb_strpos($lower, '我想看') !== false
            || mb_strpos($lower, '順便') !== false) {
            $score += 0.2;
        }

        return $score;
    }

    private function extractKeyword(string $text): string
    {
        $working = trim($text);
        $working = $this->stripLeadingMonthQualifier($working);
        $working = $this->stripLeadingPriceModifiers($working);

        $prefixes = self::PREFIX_NOISE;
        usort($prefixes, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });

        $prefixChanged = true;
        while ($prefixChanged) {
            $prefixChanged = false;
            foreach ($prefixes as $prefix) {
                if (mb_strpos($working, $prefix, 0, 'UTF-8') === 0) {
                    $working = trim(mb_substr($working, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
                    $working = $this->stripLeadingMonthQualifier($working);
                    $working = $this->stripLeadingPriceModifiers($working);
                    $prefixChanged = true;
                    break;
                }
            }
        }

        $moods = self::QUERY_MOOD_START;
        usort($moods, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });

        $moodChanged = true;
        while ($moodChanged) {
            $moodChanged = false;
            foreach ($moods as $mood) {
                if ($mood !== '' && mb_strpos($working, $mood, 0, 'UTF-8') === 0) {
                    $working = trim(mb_substr($working, mb_strlen($mood, 'UTF-8'), null, 'UTF-8'));
                    $working = $this->stripLeadingMonthQualifier($working);
                    $working = $this->stripLeadingPriceModifiers($working);
                    $moodChanged = true;
                    break;
                }
            }
        }

        // 有沒有 may remain; strip full phrase at start repeatedly.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::INLINE_NOISE as $noise) {
                $pos = mb_strpos($working, $noise, 0, 'UTF-8');
                if ($pos !== false) {
                    $working = trim(mb_substr($working, 0, $pos, 'UTF-8') . mb_substr($working, $pos + mb_strlen($noise, 'UTF-8'), null, 'UTF-8'));
                    $changed = true;
                    break;
                }
            }
            if (!$changed) {
                $beforeDe = $working;
                $working = $this->stripSandwichedParticleDe($working);
                if ($working !== $beforeDe) {
                    $changed = true;
                    continue;
                }
            }
            if (!$changed) {
                $trimDe = preg_replace('/\x{7684}+$/u', '', $working) ?? $working;
                $trimDe = trim($trimDe);
                if ($trimDe !== $working) {
                    $working = $trimDe;
                    $changed = true;
                    continue;
                }
            }
            $charLen = mb_strlen($working, 'UTF-8');
            if ($charLen > 0 && mb_substr($working, $charLen - 1, null, 'UTF-8') === '團') {
                $working = trim(mb_substr($working, 0, $charLen - 1, 'UTF-8'));
                $changed = true;
            }
        }

        $suffixChanged = true;
        $suffixes = self::SUFFIX_NOISE;
        usort($suffixes, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });
        while ($suffixChanged) {
            $suffixChanged = false;
            foreach ($suffixes as $suffix) {
                $len = mb_strlen($suffix, 'UTF-8');
                $charLen = mb_strlen($working, 'UTF-8');
                if ($len > 0 && $charLen > $len && mb_substr($working, $charLen - $len, null, 'UTF-8') === $suffix) {
                    $working = trim(mb_substr($working, 0, $charLen - $len, 'UTF-8'));
                    $suffixChanged = true;
                    break;
                }
            }
        }

        // e.g. 東京五日 → 東京 (keep 大阪五日遊 intact — ends with 遊, not 日)
        $working = preg_replace('/(?:\d+|[一二三四五六七八九十百千兩]+)\s*日$/u', '', $working) ?? $working;
        $working = trim($working);

        $working = preg_replace('/\s+/u', '', $working) ?? $working;
        $working = preg_replace('/^[，。！？、；："\']+|[，。！？、；："\']+$/u', '', $working) ?? $working;
        $working = trim($working);

        $working = $this->collapseRepeatedSingleCharKeyword($working);

        return $working;
    }

    /**
     * 安全剝除「的」助詞（位於兩個漢字之間），避免盲刪 substring 造成字元重疊（例：韓國 → 韓韓）。
     */
    private function stripSandwichedParticleDe(string $text): string
    {
        $working = $text;
        for ($i = 0; $i < 64; ++$i) {
            $count = 0;
            $next = preg_replace('/([\p{Han}])\x{7684}(?=[\p{Han}])/u', '$1', $working, -1, $count);
            if ($next === null || $count === 0) {
                break;
            }
            $working = trim($next);
        }

        return $working;
    }

    /**
     * 若整段 keyword 為同一字元重複（例如錯誤處理後的「韓韓」），收斂為單一字元。
     */
    private function collapseRepeatedSingleCharKeyword(string $text): string
    {
        $len = mb_strlen($text, 'UTF-8');
        if ($len < 2) {
            return $text;
        }
        $first = mb_substr($text, 0, 1, 'UTF-8');
        for ($i = 1; $i < $len; ++$i) {
            if (mb_substr($text, $i, 1, 'UTF-8') !== $first) {
                return $text;
            }
        }

        return $first;
    }

    /**
     * 剝除句首「便宜的／便宜」等價格修飾（可重複），便於「便宜的韓國旅遊」→ 先從「便宜…」開頭處理。
     */
    private function stripLeadingPriceModifiers(string $text): string
    {
        $working = $text;
        /** @var list<string> */
        $modifiers = ['便宜的', '便宜'];
        for ($iter = 0; $iter < 16; ++$iter) {
            $changed = false;
            foreach ($modifiers as $mod) {
                if ($mod !== '' && mb_strpos($working, $mod, 0, 'UTF-8') === 0) {
                    $working = trim(mb_substr($working, mb_strlen($mod, 'UTF-8'), null, 'UTF-8'));
                    $changed = true;
                    break;
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $working;
    }

    /**
     * 剝除句首「六月份／6月／六月」等時間限定，便於「你們有沒有六月份東京旅遊的行程」→ 東京。
     */
    private function stripLeadingMonthQualifier(string $text): string
    {
        $working = $text;
        $iter = 0;
        while ($iter < 8) {
            ++$iter;
            if (preg_match('/^(\d{1,2}|[一二三四五六七八九十百千兩]{1,3})月(?:份)?/u', $working, $m) !== 1) {
                break;
            }
            $take = $m[0];
            $len = mb_strlen($take, 'UTF-8');
            $working = trim(mb_substr($working, $len, null, 'UTF-8'));
        }

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
