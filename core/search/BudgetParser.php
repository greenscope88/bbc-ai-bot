<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchCondition.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ChineseNumberHelper.php';

/**
 * Rule-based budget extraction (Phase 2-A). No Gemini.
 */
final class BudgetParser
{
    public function parse(string $message, ?SearchCondition $base = null): SearchCondition
    {
        $base = $base ?? SearchCondition::empty($message);
        $text = trim($message);
        if ($text === '') {
            return $base;
        }

        if (preg_match('/預算\s*([一二三四五六七八九十兩\d]+(?:\.\d+)?)\s*萬/u', $text, $m) === 1) {
            $amount = ChineseNumberHelper::parseWanAmount($m[1] . '萬');
            if ($amount !== null) {
                return $this->applyAround($base, $amount, $m[0]);
            }
        }

        if (preg_match('/([一二三四五六七八九十兩\d]+(?:\.\d+)?)\s*萬\s*(左右|上下)/u', $text, $m) === 1) {
            $amount = ChineseNumberHelper::parseWanAmount($m[1] . '萬');
            if ($amount !== null) {
                return $this->applyAround($base, $amount, $m[0]);
            }
        }

        if (preg_match('/([一二三四五六七八九十兩\d]+(?:\.\d+)?)\s*萬元?\s*(以下|內|以內)/u', $text, $m) === 1) {
            $amount = ChineseNumberHelper::parseWanAmount($m[1] . '萬');
            if ($amount !== null) {
                return $this->applyMax($base, $amount, $m[0]);
            }
        }

        if (preg_match('/([一二三四五六七八九十兩\d]+(?:\.\d+)?)\s*萬\s*到\s*([一二三四五六七八九十兩\d]+(?:\.\d+)?)\s*萬/u', $text, $m) === 1) {
            $min = ChineseNumberHelper::parseWanAmount($m[1] . '萬');
            $max = ChineseNumberHelper::parseWanAmount($m[2] . '萬');
            if ($min !== null && $max !== null) {
                return $base
                    ->with([
                        'budget_min' => min($min, $max),
                        'budget_max' => max($min, $max),
                    ])
                    ->flag('budget_parsed')
                    ->bumpConfidence(0.8);
            }
        }

        if (preg_match('/([一二三四五六七八九十兩\d]+(?:\.\d+)?)\s*萬/u', $text, $m) === 1) {
            $amount = ChineseNumberHelper::parseWanAmount($m[1] . '萬');
            if ($amount !== null) {
                return $this->applyAround($base, $amount, $m[0]);
            }
        }

        return $base;
    }

    private function applyMax(SearchCondition $base, int $max, string $label): SearchCondition
    {
        return $base
            ->with([
                'budget_max' => $max,
                'budget_min' => null,
            ])
            ->flag('budget_parsed')
            ->bumpConfidence(0.75);
    }

    private function applyAround(SearchCondition $base, int $center, string $label): SearchCondition
    {
        $delta = (int) round($center * 0.15);
        $min = max(0, $center - $delta);
        $max = $center + $delta;

        return $base
            ->with([
                'budget_min' => $min,
                'budget_max' => $max,
            ])
            ->flag('budget_parsed')
            ->bumpConfidence(0.72);
    }
}
