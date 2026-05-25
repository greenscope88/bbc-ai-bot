<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Rule-based area / destination extraction (Phase 2-A). No Gemini.
 */
final class AreaParser
{
    /**
     * @var array<string, array{area: ?string, destination: ?string, keyword: string, parent_area: ?string}>
     */
    private const REGION_NODES = [
        '歐洲' => ['area' => '歐洲', 'destination' => null, 'keyword' => '歐洲', 'parent_area' => null],
        '東歐' => ['area' => '歐洲', 'destination' => '東歐', 'keyword' => '東歐', 'parent_area' => '歐洲'],
        '西歐' => ['area' => '歐洲', 'destination' => '西歐', 'keyword' => '西歐', 'parent_area' => '歐洲'],
        '北歐' => ['area' => '歐洲', 'destination' => '北歐', 'keyword' => '北歐', 'parent_area' => '歐洲'],
        '南歐' => ['area' => '歐洲', 'destination' => '南歐', 'keyword' => '南歐', 'parent_area' => '歐洲'],
        '日本' => ['area' => '日本', 'destination' => null, 'keyword' => '日本', 'parent_area' => null],
        '關東' => ['area' => '日本', 'destination' => '關東', 'keyword' => '關東', 'parent_area' => '日本'],
        '關西' => ['area' => '日本', 'destination' => '關西', 'keyword' => '關西', 'parent_area' => '日本'],
        '北海道' => ['area' => '日本', 'destination' => '北海道', 'keyword' => '北海道', 'parent_area' => '日本'],
        '東京' => ['area' => '日本', 'destination' => '東京', 'keyword' => '東京', 'parent_area' => '關東'],
        '大阪' => ['area' => '日本', 'destination' => '大阪', 'keyword' => '大阪', 'parent_area' => '關西'],
        '韓國' => ['area' => '韓國', 'destination' => null, 'keyword' => '韓國', 'parent_area' => null],
        '東南亞' => ['area' => '東南亞', 'destination' => null, 'keyword' => '東南亞', 'parent_area' => null],
    ];

    /** Longest phrase first for greedy match. */
    private const MATCH_ORDER = [
        '東南亞', '北海道', '東歐', '西歐', '北歐', '南歐', '關東', '關西',
        '歐洲', '日本', '東京', '大阪', '韓國',
    ];

    public function parse(string $message, ?SearchCondition $base = null): SearchCondition
    {
        $base = $base ?? SearchCondition::empty($message);
        $text = trim($message);
        if ($text === '') {
            return $base;
        }

        foreach (self::MATCH_ORDER as $phrase) {
            if (mb_strpos($text, $phrase, 0, 'UTF-8') === false) {
                continue;
            }

            $node = self::REGION_NODES[$phrase];
            $keyword = $node['destination'] ?? $node['area'] ?? $node['keyword'];

            return $base
                ->with([
                    'area' => $node['area'],
                    'destination' => $node['destination'],
                    'keyword' => $keyword,
                ])
                ->flag('area_parsed')
                ->bumpConfidence(0.75);
        }

        return $base;
    }

    /**
     * @return array<string, array{area: ?string, destination: ?string, keyword: string, parent_area: ?string}>
     */
    public static function regionCatalog(): array
    {
        return self::REGION_NODES;
    }
}
