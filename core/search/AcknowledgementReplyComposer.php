<?php
declare(strict_types=1);

/**
 * Phase 9-C-1d-β2 acknowledgement reply composer (AD-004 / RD-005).
 *
 * Semantic intent is fixed (query in progress); wording varies across a safe pool.
 */
final class AcknowledgementReplyComposer
{
    /** @var list<string> */
    private const MESSAGE_POOL = [
        '已了解您的需求，正在為您查詢適合的行程 😊',
        '收到，我先幫您整理符合條件的旅遊商品，請稍候一下 ✈️',
        '好的，我來幫您看看目前有哪些適合的行程，請稍等 😊',
        '了解，我這邊正在為您搜尋合適的旅遊方案，請稍候 📌',
        '收到您的需求了，我馬上幫您查詢相關行程，請稍等一下 😊',
    ];

    /**
     * @return list<string>
     */
    public function getCandidatePool(): array
    {
        return self::MESSAGE_POOL;
    }

    public function compose(?string $seed = null): string
    {
        $pool = self::MESSAGE_POOL;
        if ($pool === []) {
            return '';
        }

        if ($seed === null || $seed === '') {
            $index = random_int(0, count($pool) - 1);
        } else {
            $index = abs(crc32($seed)) % count($pool);
        }

        return $pool[$index];
    }

    public static function isQueryInProgressSemantic(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $needles = ['查詢', '搜尋', '整理', '稍候', '稍等', '看看'];
        foreach ($needles as $needle) {
            if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }
}
