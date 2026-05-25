<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Rule-based synonym expansion for keywords / styles (Phase 2-A). No Gemini.
 */
final class KeywordNormalizer
{
    /** @var array<string, list<string>> destination → tags */
    private const DESTINATION_TAGS = [
        '東京' => ['關東', '富士山', '迪士尼'],
        '大阪' => ['關西', '京都', '神戶', '奈良'],
    ];

    /** @var array<string, string> alias → canonical destination */
    private const DESTINATION_ALIASES = [
        '關東' => '東京',
        '富士山' => '東京',
        '迪士尼' => '東京',
        '關西' => '大阪',
        '京都' => '大阪',
        '神戶' => '大阪',
        '奈良' => '大阪',
    ];

    /** @var array<string, list<string>> style phrase → canonical style */
    private const STYLE_ALIASES = [
        '親子' => ['小孩', '家庭', '樂園'],
        '長輩' => ['慢活', '輕鬆', '不累'],
        '蜜月' => ['浪漫', '雙人'],
        '賞雪' => ['滑雪', '雪景'],
    ];

    public function normalize(string $message, ?SearchCondition $base = null): SearchCondition
    {
        $base = $base ?? SearchCondition::empty($message);
        $text = trim($message);
        if ($text === '') {
            return $base;
        }

        $destination = $base->getDestination() ?? $base->getKeyword();
        $tags = $base->getSpecialTags();
        $styles = $base->getTravelStyle();
        $keyword = $base->getKeyword();
        $changed = false;

        foreach (self::STYLE_ALIASES as $canonical => $aliases) {
            $needles = array_merge([$canonical], $aliases);
            foreach ($needles as $needle) {
                if ($needle !== '' && mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
                    if (!in_array($canonical, $styles, true)) {
                        $styles[] = $canonical;
                    }
                    $changed = true;
                    break;
                }
            }
        }

        foreach (self::DESTINATION_ALIASES as $alias => $canonical) {
            if (mb_strpos($text, $alias, 0, 'UTF-8') !== false) {
                $destination = $canonical;
                $keyword = $canonical;
                $changed = true;
            }
        }

        if ($destination !== null && isset(self::DESTINATION_TAGS[$destination])) {
            foreach (self::DESTINATION_TAGS[$destination] as $tag) {
                if (mb_strpos($text, $tag, 0, 'UTF-8') !== false && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                    $changed = true;
                }
            }
        }

        if (!$changed) {
            return $base;
        }

        return $base
            ->with([
                'keyword' => $keyword ?? $destination,
                'destination' => $destination ?? $base->getDestination(),
                'special_tags' => $tags,
                'travel_style' => $styles,
            ])
            ->flag('keyword_normalized')
            ->bumpConfidence(0.65);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function destinationTagCatalog(): array
    {
        return self::DESTINATION_TAGS;
    }
}
