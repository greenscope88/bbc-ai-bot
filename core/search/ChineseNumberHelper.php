<?php
declare(strict_types=1);

/**
 * Minimal Chinese / Arabic numerals for budget and date parsing (Phase 2-A).
 */
final class ChineseNumberHelper
{
    /** @var array<string, int> */
    private const DIGIT_MAP = [
        '零' => 0, '〇' => 0,
        '一' => 1, '二' => 2, '兩' => 2, '三' => 3, '四' => 4,
        '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9,
    ];

    public static function parseInteger(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return self::parseChineseInt($raw);
    }

    private static function parseChineseInt(string $s): ?int
    {
        if ($s === '十') {
            return 10;
        }

        if (preg_match('/^十(\d|[一二三四五六七八九])$/u', $s, $m) === 1) {
            $tail = self::digitCharToInt($m[1]);

            return $tail !== null ? 10 + $tail : null;
        }

        if (preg_match('/^(\d|[一二三四五六七八九])十$/u', $s, $m) === 1) {
            $head = self::digitCharToInt($m[1]);

            return $head !== null ? $head * 10 : null;
        }

        if (preg_match('/^(\d|[一二三四五六七八九])十(\d|[一二三四五六七八九])$/u', $s, $m) === 1) {
            $head = self::digitCharToInt($m[1]);
            $tail = self::digitCharToInt($m[2]);
            if ($head === null || $tail === null) {
                return null;
            }

            return $head * 10 + $tail;
        }

        if (mb_strlen($s, 'UTF-8') === 1) {
            return self::digitCharToInt($s);
        }

        return null;
    }

    private static function digitCharToInt(string $c): ?int
    {
        if (preg_match('/^\d$/', $c) === 1) {
            return (int) $c;
        }

        return self::DIGIT_MAP[$c] ?? null;
    }

    /**
     * Parse amount like 三萬、5萬、兩萬五 → TWD integer.
     */
    public static function parseWanAmount(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*萬/u', $raw, $m) === 1) {
            return (int) round((float) $m[1] * 10000);
        }

        if (preg_match('/^([一二三四五六七八九兩十]+)\s*萬(?:([一二三四五六七八九])千)?/u', $raw, $m) === 1) {
            $wan = self::parseChineseInt($m[1]);
            if ($wan === null) {
                return null;
            }
            $amount = $wan * 10000;
            if (isset($m[2]) && $m[2] !== '') {
                $qian = self::digitCharToInt($m[2]);
                if ($qian !== null) {
                    $amount += $qian * 1000;
                }
            }

            return $amount;
        }

        $plain = self::parseInteger($raw);
        if ($plain !== null && $plain >= 1000) {
            return $plain;
        }

        return null;
    }
}
