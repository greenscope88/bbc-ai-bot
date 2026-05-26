<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'TravelIntentLexicon.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'TourIntentDecisionLogger.php';

/**
 * Stage 1-B-13 tour search intent + keyword extraction (rule-based, no API/DB/Gemini).
 * Phase 2-C.1: travel intent lexicon (date/area/budget composites).
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
     *   intent: string,
     *   keyword: string|null,
     *   confidence: float,
     *   reason: string,
     *   intent_source: string,
     *   matched_lexicon: string|null,
     *   matched_date: bool,
     *   matched_area: string|null,
     *   matched_budget: bool
     * }
     */
    public function detect(string $message): array
    {
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $text = trim($message);
        if ($text === '') {
            return $this->finalize($this->result(false, null, 0.0, 'empty_message'), $text);
        }

        $lower = mb_strtolower($text, 'UTF-8');

        if ($this->matchesExclusion($lower)) {
            return $this->finalize($this->result(false, null, 0.05, 'excluded_non_tour_topic'), $text);
        }

        $bareKeyword = $this->detectBareDestinationKeyword($text);
        if ($bareKeyword !== null) {
            return $this->finalize($this->result(true, $bareKeyword, 0.85, 'bare_destination_name', [
                'intent_source' => 'bare_destination_name',
                'matched_lexicon' => $bareKeyword,
                'matched_date' => TravelIntentLexicon::hasDateSignal($text),
                'matched_area' => $bareKeyword,
                'matched_budget' => TravelIntentLexicon::hasBudgetSignal($text),
            ]), $text);
        }

        $lexicon = TravelIntentLexicon::analyze($text);
        if (($lexicon['is_tour_search'] ?? false) === true) {
            $keyword = $this->extractKeyword($text);
            if ($keyword === '' && !empty($lexicon['destination'])) {
                $keyword = (string) $lexicon['destination'];
            }
            if ($keyword !== '') {
                $confidence = 0.88;
                if ($lexicon['has_date'] ?? false) {
                    $confidence += 0.04;
                }
                if ($lexicon['has_budget'] ?? false) {
                    $confidence += 0.03;
                }

                return $this->finalize($this->result(
                    true,
                    $keyword,
                    min(0.99, $confidence),
                    (string) ($lexicon['reason'] ?? 'lexicon_destination'),
                    [
                        'intent_source' => (string) ($lexicon['reason'] ?? 'lexicon'),
                        'matched_lexicon' => $lexicon['matched_lexicon'] ?? $lexicon['destination'] ?? null,
                        'matched_date' => (bool) ($lexicon['has_date'] ?? false),
                        'matched_area' => $lexicon['destination'] ?? null,
                        'matched_budget' => (bool) ($lexicon['has_budget'] ?? false),
                    ]
                ), $text);
            }
        }

        $tourScore = $this->scoreTourIntent($lower, $text);
        if ($tourScore <= 0) {
            return $this->finalize($this->result(false, null, 0.1, 'no_tour_search_signal'), $text);
        }

        $keyword = $this->extractKeyword($text);
        if ($keyword === '') {
            return $this->finalize($this->result(false, null, 0.35, 'tour_signal_without_keyword'), $text);
        }

        $confidence = min(0.99, 0.55 + $tourScore);

        return $this->finalize($this->result(true, $keyword, $confidence, 'tour_search_detected', [
            'intent_source' => 'tour_markers',
            'matched_lexicon' => TravelIntentLexicon::findLongestDestination($text),
            'matched_date' => TravelIntentLexicon::hasDateSignal($text),
            'matched_area' => TravelIntentLexicon::findLongestDestination($text),
            'matched_budget' => TravelIntentLexicon::hasBudgetSignal($text),
        ]), $text);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function finalize(array $result, string $message): array
    {
        if (TourIntentDecisionLogger::isLoggingEnabled()) {
            TourIntentDecisionLogger::log([
                'message' => $message,
                'is_tour_query' => $result['is_tour_query'] ?? false,
                'intent' => $result['intent'] ?? 'non_tour',
                'keyword' => $result['keyword'] ?? null,
                'confidence' => $result['confidence'] ?? 0,
                'reason' => $result['reason'] ?? '',
                'intent_source' => $result['intent_source'] ?? null,
                'matched_lexicon' => $result['matched_lexicon'] ?? null,
                'matched_date' => $result['matched_date'] ?? false,
                'matched_area' => $result['matched_area'] ?? null,
                'matched_budget' => $result['matched_budget'] ?? false,
            ]);
        }

        return $result;
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

    private function scoreTourIntent(string $lower, string $text): float
    {
        $score = 0.0;

        foreach (self::TOUR_MARKERS as $marker) {
            if (mb_strpos($lower, mb_strtolower($marker, 'UTF-8')) !== false) {
                $score += 0.35;
            }
        }

        foreach (TravelIntentLexicon::TOUR_PRODUCT_TERMS as $marker) {
            if (mb_strpos($lower, mb_strtolower($marker, 'UTF-8')) !== false) {
                $score += 0.25;
                break;
            }
        }

        if (TravelIntentLexicon::findLongestDestination($text) !== null) {
            $score += 0.4;
        }

        if (TravelIntentLexicon::hasDateSignal($text)) {
            $score += 0.2;
        }

        if (TravelIntentLexicon::hasBudgetSignal($text)) {
            $score += 0.15;
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
        $working = $this->stripDeparturePrefix($working);
        $working = $this->stripLeadingDateTokens($working);
        $working = $this->stripTrailingBudgetTokens($working);
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
                    $working = $this->stripLeadingDateTokens($working);
                    $working = $this->stripTrailingBudgetTokens($working);
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
                    $working = $this->stripLeadingDateTokens($working);
                    $working = $this->stripTrailingBudgetTokens($working);
                    $working = $this->stripLeadingMonthQualifier($working);
                    $working = $this->stripLeadingPriceModifiers($working);
                    $moodChanged = true;
                    break;
                }
            }
        }

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

        $working = $this->stripTrailingBudgetTokens($working);
        $working = $this->stripTrailingStyleTerms($working);
        $working = preg_replace('/(?:\d+|[一二三四五六七八九十百千兩]+)\s*日$/u', '', $working) ?? $working;
        $working = trim($working);

        $working = preg_replace('/\s+/u', '', $working) ?? $working;
        $working = preg_replace('/^[，。！？、；："\']+|[，。！？、；："\']+$/u', '', $working) ?? $working;
        $working = trim($working);

        if ($working === '') {
            $fallback = TravelIntentLexicon::findLongestDestination($text);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        $working = $this->collapseRepeatedSingleCharKeyword($working);

        return $working;
    }

    /**
     * 剝除句首日期語意（六月底、六月初、6月…）。
     */
    private function stripLeadingDateTokens(string $text): string
    {
        $working = $text;
        for ($iter = 0; $iter < 12; ++$iter) {
            $before = $working;
            $working = preg_replace(
                '/^(\d{1,2}|[一二三四五六七八九十百千兩]{1,3})月(底|初|中|份)?/u',
                '',
                $working
            ) ?? $working;
            $working = preg_replace(
                '/^(\d{1,2}|[一二三四五六七八九十]{1,3})月(\d{1,2}|[一二三四五六七八九十]{1,3})日/u',
                '',
                $working
            ) ?? $working;
            $working = trim($working);
            if ($working === $before) {
                break;
            }
        }

        return $working;
    }

    /**
     * 剝除句尾預算語意（三萬以下、五萬以內…）。
     */
    private function stripDeparturePrefix(string $text): string
    {
        $trimmed = preg_replace('/^(台北|高雄|台中|桃園|松山|花蓮|台南)出發/u', '', trim($text)) ?? trim($text);

        return trim($trimmed);
    }

    /**
     * 剝除句尾旅遊風格詞（親子、蜜月…）便於關鍵字收斂至目的地。
     */
    private function stripTrailingStyleTerms(string $text): string
    {
        /** @var list<string> */
        $styles = ['親子', '蜜月', '賞櫻', '賞楓', '滑雪'];
        $working = trim($text);
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($styles as $style) {
                $len = mb_strlen($style, 'UTF-8');
                $charLen = mb_strlen($working, 'UTF-8');
                if ($len > 0 && $charLen > $len && mb_substr($working, $charLen - $len, null, 'UTF-8') === $style) {
                    $working = trim(mb_substr($working, 0, $charLen - $len, 'UTF-8'));
                    $changed = true;
                    break;
                }
            }
        }

        return $working;
    }

    private function stripTrailingBudgetTokens(string $text): string
    {
        $working = trim($text);
        for ($iter = 0; $iter < 8; ++$iter) {
            $before = $working;
            $working = preg_replace(
                '/(?:預算)?(?:\d+|[一二三四五六七八九十兩]{1,3})?\s*萬(?:元)?(?:以下|以內|內|左右)?$/u',
                '',
                $working
            ) ?? $working;
            $working = trim($working);
            if ($working === $before) {
                break;
            }
        }

        return $working;
    }

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
     * @param array{intent_source?: string, matched_lexicon?: ?string, matched_date?: bool, matched_area?: ?string, matched_budget?: bool} $meta
     * @return array<string, mixed>
     */
    private function result(bool $isTourQuery, ?string $keyword, float $confidence, string $reason, array $meta = []): array
    {
        return [
            'is_tour_query' => $isTourQuery,
            'intent' => $isTourQuery ? 'tour_search' : 'non_tour',
            'keyword' => $keyword,
            'confidence' => round($confidence, 2),
            'reason' => $reason,
            'intent_source' => $meta['intent_source'] ?? $reason,
            'matched_lexicon' => $meta['matched_lexicon'] ?? null,
            'matched_date' => (bool) ($meta['matched_date'] ?? false),
            'matched_area' => $meta['matched_area'] ?? null,
            'matched_budget' => (bool) ($meta['matched_budget'] ?? false),
        ];
    }
}
