<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TravelIntentLexicon.php';

/**
 * Phase 9-C-2B-1 — Knowledge vs product search intent (rule-based, no GCS/Gemini).
 */
final class KnowledgeIntentDetector
{
    public const INTENT_PRODUCT_SEARCH = 'product_search';
    public const INTENT_KNOWLEDGE_QUERY = 'knowledge_query';
    public const INTENT_AMBIGUOUS = 'ambiguous';

    /** @var list<string> */
    private const KNOWLEDGE_PHRASE_MARKERS = [
        '客服電話',
        '聯絡電話',
        '公司電話',
        '公司地址',
        '有哪些服務',
        '服務項目',
        '取消規定',
        '可以刷卡',
        '台胞證',
        '護照代辦',
    ];

    /**
     * External product link list queries (Phase 9-C-2B-6A).
     *
     * @var list<string>
     */
    private const EXTERNAL_PRODUCT_LINK_KNOWLEDGE_MARKERS = [
        '有哪些旅遊商品入口',
        '哪些旅遊商品入口',
        '有哪些商品連結',
        '哪些商品連結',
        '有哪些商品入口',
        '哪些商品入口',
        '旅遊商品入口',
        '旅遊商品連結',
    ];

    /** @var list<string> */
    private const AMBIGUOUS_PHRASE_MARKERS = [
        '想出去玩',
        '幫我看看',
        '有推薦嗎',
        '我想旅遊',
        '我要去',
        '怎麼安排',
        '出國玩',
        '想出去',
    ];

    /** @var list<string> */
    private const AMBIGUOUS_TRAVEL_MARKERS = [
        '旅遊',
        '出國',
        '出去玩',
        '推薦',
        '幫我看看',
        '看一下',
        '想玩',
    ];

    /**
     * @return array{intent_type: string}
     */
    public function detect(string $message): array
    {
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $text = trim($message);
        if ($text === '') {
            return ['intent_type' => self::INTENT_AMBIGUOUS];
        }

        if ($this->matchesKnowledgeQuery($text)) {
            return ['intent_type' => self::INTENT_KNOWLEDGE_QUERY];
        }

        if ($this->matchesProductSearch($text)) {
            return ['intent_type' => self::INTENT_PRODUCT_SEARCH];
        }

        if ($this->matchesAmbiguous($text)) {
            return ['intent_type' => self::INTENT_AMBIGUOUS];
        }

        return ['intent_type' => self::INTENT_KNOWLEDGE_QUERY];
    }

    private function matchesKnowledgeQuery(string $text): bool
    {
        foreach (self::KNOWLEDGE_PHRASE_MARKERS as $marker) {
            if (mb_strpos($text, $marker, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        if (mb_strpos($text, '護照', 0, 'UTF-8') !== false) {
            return true;
        }

        if (mb_strpos($text, '地址', 0, 'UTF-8') !== false) {
            return true;
        }

        if (mb_strpos($text, '電話', 0, 'UTF-8') !== false) {
            return true;
        }

        if (mb_strpos($text, '刷卡', 0, 'UTF-8') !== false) {
            return true;
        }

        if (mb_strpos($text, '簽證', 0, 'UTF-8') !== false
            && (mb_strpos($text, '多少', 0, 'UTF-8') !== false
                || mb_strpos($text, '費用', 0, 'UTF-8') !== false
                || mb_strpos($text, '價格', 0, 'UTF-8') !== false
                || mb_strpos($text, '請問', 0, 'UTF-8') !== false)) {
            return true;
        }

        if (mb_strpos($text, '服務', 0, 'UTF-8') !== false
            && (mb_strpos($text, '有哪些', 0, 'UTF-8') !== false
                || mb_strpos($text, '什麼服務', 0, 'UTF-8') !== false
                || mb_strpos($text, '哪些服務', 0, 'UTF-8') !== false)) {
            return true;
        }

        if (mb_strpos($text, '取消', 0, 'UTF-8') !== false
            && (mb_strpos($text, '規定', 0, 'UTF-8') !== false
                || mb_strpos($text, '政策', 0, 'UTF-8') !== false
                || mb_strpos($text, '辦法', 0, 'UTF-8') !== false)) {
            return true;
        }

        if (mb_strpos($text, '費用', 0, 'UTF-8') !== false
            && (mb_strpos($text, '護照', 0, 'UTF-8') !== false
                || mb_strpos($text, '台胞證', 0, 'UTF-8') !== false)) {
            return true;
        }

        if ($this->matchesExternalProductLinkKnowledge($text)) {
            return true;
        }

        return false;
    }

    /**
     * External product link / portal queries should route to knowledge runtime (Phase 9-C-2B-6A).
     */
    private function matchesExternalProductLinkKnowledge(string $text): bool
    {
        foreach (self::EXTERNAL_PRODUCT_LINK_KNOWLEDGE_MARKERS as $marker) {
            if (mb_strpos($text, $marker, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        if (preg_match('/(哪些|什麼|提供).*(商品|旅遊).*(入口|連結|链接)/u', $text) === 1) {
            return true;
        }

        if (preg_match('/(哪些|什麼).*(商品入口|商品連結|商品链接|旅遊商品)/u', $text) === 1) {
            return true;
        }

        if (!$this->hasExternalProductLinkIntent($text)) {
            return false;
        }

        return mb_strpos($text, '商品', 0, 'UTF-8') !== false
            || mb_strpos($text, '旅遊', 0, 'UTF-8') !== false
            || mb_strpos($text, '行程', 0, 'UTF-8') !== false;
    }

    private function hasExternalProductLinkIntent(string $text): bool
    {
        foreach (['連結', '链接', '入口', '網址', '网址'] as $marker) {
            if (mb_strpos($text, $marker, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function matchesProductSearch(string $text): bool
    {
        $destination = TravelIntentLexicon::findLongestDestination($text);
        if ($destination === null) {
            return false;
        }

        return TravelIntentLexicon::hasDateSignal($text)
            || TravelIntentLexicon::hasBudgetSignal($text)
            || TravelIntentLexicon::hasTourProductTerm($text)
            || mb_strpos($text, '近期', 0, 'UTF-8') !== false
            || $this->hasDepartureSignal($text);
    }

    private function matchesAmbiguous(string $text): bool
    {
        foreach (self::AMBIGUOUS_PHRASE_MARKERS as $marker) {
            if (mb_strpos($text, $marker, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        if (TravelIntentLexicon::findLongestDestination($text) !== null) {
            return true;
        }

        foreach (self::AMBIGUOUS_TRAVEL_MARKERS as $marker) {
            if (mb_strpos($text, $marker, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function hasDepartureSignal(string $text): bool
    {
        return preg_match('/(台北|高雄|台中|桃園|松山|花蓮|台南)出發/u', $text) === 1;
    }
}
