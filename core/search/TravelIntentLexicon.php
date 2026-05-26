<?php
declare(strict_types=1);

/**
 * Travel search intent lexicon (Phase 2-C.1). Rule-based; no DB/Gemini.
 */
final class TravelIntentLexicon
{
    /**
     * Destinations / regions (longest match first).
     *
     * @var list<string>
     */
    public const DESTINATIONS = [
        '東南亞',
        '北海道',
        '馬爾地夫',
        '新加坡',
        '加拿大',
        '義大利',
        '德國',
        '法國',
        '釜山',
        '首爾',
        '京都',
        '沖繩',
        '大阪',
        '東京',
        '北歐',
        '東歐',
        '西歐',
        '南歐',
        '韓國',
        '日本',
        '泰國',
        '越南',
        '美國',
        '歐洲',
        '北京',
        '上海',
        '關東',
        '關西',
    ];

    /**
     * Product / style terms that reinforce tour-search intent.
     *
     * @var list<string>
     */
    public const TOUR_PRODUCT_TERMS = [
        '自由行',
        '跟團',
        '行程',
        '旅遊',
        '旅行',
        '賞櫻',
        '賞楓',
        '滑雪',
        '蜜月',
        '親子',
        '郵輪',
        '團',
    ];

    /**
     * @return array{
     *   destination: ?string,
     *   matched_lexicon: ?string,
     *   has_date: bool,
     *   has_budget: bool,
     *   has_tour_term: bool,
     *   is_tour_search: bool,
     *   reason: string
     * }
     */
    public static function analyze(string $message): array
    {
        $text = trim($message);
        if ($text === '') {
            return self::emptyAnalysis('empty_message');
        }

        $destination = self::findLongestDestination($text);
        $hasDate = self::hasDateSignal($text);
        $hasBudget = self::hasBudgetSignal($text);
        $hasTourTerm = self::hasTourProductTerm($text);

        if ($destination === null) {
            return self::emptyAnalysis('no_destination_lexicon');
        }

        $reason = 'lexicon_destination';
        if ($hasDate && $hasBudget) {
            $reason = 'lexicon_date_budget_destination';
        } elseif ($hasDate) {
            $reason = 'lexicon_date_destination';
        } elseif ($hasBudget) {
            $reason = 'lexicon_budget_destination';
        } elseif ($hasTourTerm) {
            $reason = 'lexicon_tour_term_destination';
        }

        return [
            'destination' => $destination,
            'matched_lexicon' => $destination,
            'has_date' => $hasDate,
            'has_budget' => $hasBudget,
            'has_tour_term' => $hasTourTerm,
            'is_tour_search' => true,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{destination: ?string, matched_lexicon: ?string, has_date: bool, has_budget: bool, has_tour_term: bool, is_tour_search: bool, reason: string}
     */
    private static function emptyAnalysis(string $reason): array
    {
        return [
            'destination' => null,
            'matched_lexicon' => null,
            'has_date' => false,
            'has_budget' => false,
            'has_tour_term' => false,
            'is_tour_search' => false,
            'reason' => $reason,
        ];
    }

    public static function findLongestDestination(string $text): ?string
    {
        $found = null;
        $foundLen = 0;

        foreach (self::DESTINATIONS as $phrase) {
            if ($phrase === '') {
                continue;
            }
            if (mb_strpos($text, $phrase, 0, 'UTF-8') === false) {
                continue;
            }
            $len = mb_strlen($phrase, 'UTF-8');
            if ($len > $foundLen) {
                $found = $phrase;
                $foundLen = $len;
            }
        }

        return $found;
    }

    public static function hasDateSignal(string $text): bool
    {
        if (preg_match('/(\d{1,2}|[一二三四五六七八九十百千兩]{1,3})月(底|初|中|份)?/u', $text) === 1) {
            return true;
        }
        if (preg_match('/(\d{1,2}|[一二三四五六七八九十]{1,3})月(\d{1,2}|[一二三四五六七八九十]{1,3})日/u', $text) === 1) {
            return true;
        }
        if (preg_match('/\d{1,2}\/\d{1,2}/u', $text) === 1) {
            return true;
        }
        foreach (['暑假', '寒假', '春節', '過年', '端午', '中秋', '連假'] as $label) {
            if (mb_strpos($text, $label, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    public static function hasBudgetSignal(string $text): bool
    {
        if (preg_match('/\d+\s*萬/u', $text) === 1) {
            return true;
        }
        if (preg_match('/[一二三四五六七八九十兩]+\s*萬/u', $text) === 1) {
            return true;
        }
        if (mb_strpos($text, '預算', 0, 'UTF-8') !== false) {
            return true;
        }
        if (mb_strpos($text, '以下', 0, 'UTF-8') !== false && mb_strpos($text, '萬', 0, 'UTF-8') !== false) {
            return true;
        }

        return false;
    }

    public static function hasTourProductTerm(string $text): bool
    {
        $lower = mb_strtolower($text, 'UTF-8');
        foreach (self::TOUR_PRODUCT_TERMS as $term) {
            if ($term === '') {
                continue;
            }
            if (mb_strpos($lower, mb_strtolower($term, 'UTF-8'), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }
}
