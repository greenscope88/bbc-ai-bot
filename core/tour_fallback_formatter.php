<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

/**
 * LINE-friendly reply when Gemini fails but tour prompt context exists.
 * Parses the Chinese block produced by GeminiTourContextBuilder (no Gemini call).
 */
final class TourFallbackFormatter
{
    /** LINE fixed-list product separator (output only; context may use ======== from builder). */
    private const LINE_ITEM_SEPARATOR = '──────────────';

    /** LINE fixed-list output labels (emoji + space before text); input context uses plain labels from GeminiTourContextBuilder. */
    private const LINE_TITLE_FLAG = '🚩';
    private const LINE_DEPARTURE_LABEL = '📅 最近出團：';
    private const LINE_PRICE_LABEL = '💰 售價：';
    private const LINE_ORIGIN_LABEL = '🛫 出發地：';
    private const LINE_DETAIL_LABEL = '📄 詳細內容：';
    private const LINE_SCHEDULE_LABEL = '🗓️ 行程表：';

    /**
     * Build a customer-visible message from tour context text (Gemini adjunct block).
     */
    public static function formatFromTourContext(string $tourContext): string
    {
        $ctx = trim($tourContext);
        if ($ctx === '') {
            return '';
        }

        $body = self::stripInstructionBlock($ctx);
        $url = self::extractSearchUrl($body);

        $lines = [];
        $lines[] = '您好，以下為行程參考資訊（系統自動整理，實際以官網與客服確認為準）：';
        $lines[] = '';

        $items = self::parseItemBlocks($body);
        if ($items === []) {
            foreach (explode("\n", $body) as $ln) {
                $t = trim($ln);
                if ($t !== '' && strpos($t, '請 Gemini') === false) {
                    $lines[] = $t;
                }
            }
        } else {
            $sliceItems = array_slice($items, 0, 5);
            foreach ($sliceItems as $it) {
                $lines[] = self::LINE_TITLE_FLAG . ' ' . $it['title'];
                $lines[] = '';
                $lines[] = self::LINE_DEPARTURE_LABEL . GeminiTourContextBuilder::formatDepartureDatesDisplay(
                    self::normalizeDatesDisplay($it['date'])
                );
                $lines[] = self::LINE_PRICE_LABEL . $it['price'];
                $lines[] = self::LINE_ORIGIN_LABEL . $it['departure'];
                if ($it['detail_page'] !== '') {
                    $lines[] = self::LINE_DETAIL_LABEL . $it['detail_page'];
                }
                foreach ($it['schedule_lines'] as $sl) {
                    $lines[] = self::formatScheduleOutputLine($sl);
                }
                $lines[] = self::LINE_ITEM_SEPARATOR;
            }
        }

        if ($url !== '') {
            $lines[] = '';
            $lines[] = GeminiTourContextBuilder::SEARCH_URL_LABEL;
            $lines[] = $url;
        }

        $lines[] = '';
        $lines[] = '如需更多協助，歡迎再告訴我們。';

        return trim(implode("\n", $lines));
    }

    private static function stripInstructionBlock(string $ctx): string
    {
        $pos = mb_strpos($ctx, '請 Gemini', 0, 'UTF-8');
        if ($pos !== false) {
            return trim(mb_substr($ctx, 0, $pos, 'UTF-8'));
        }

        return $ctx;
    }

    private static function extractSearchUrl(string $body): string
    {
        foreach ([GeminiTourContextBuilder::SEARCH_URL_LABEL, '更多參考行程及出團日期：', '完整搜尋結果：'] as $label) {
            $labelPos = strpos($body, $label);
            if ($labelPos === false) {
                continue;
            }
            $after = substr($body, $labelPos + strlen($label));
            if (preg_match('/https?:\/\/\S+/u', $after, $m) === 1) {
                return trim($m[0]);
            }
        }

        if (preg_match('/https?:\/\/(?:www\.)?(?:bbcshops|bonusmee)\.com\/[A-Za-z0-9]+/u', $body, $shortMatch) === 1) {
            return trim($shortMatch[0]);
        }

        if (preg_match('/https?:\/\/[^\s]*cloud_store_tourdate\.php[^\s]*/u', $body, $longMatch) === 1) {
            return trim(rtrim($longMatch[0], '.,;)]'));
        }

        if (preg_match('/https?:\/\/\S+/u', $body, $anyMatch) === 1) {
            return trim(rtrim($anyMatch[0], '.,;)]'));
        }

        return '';
    }

    /**
     * 將「2026-06-01、2026-08-01」等轉成 MM/DD；已為 MM/DD 或含「、」者盡量保留結構。
     */
    private static function normalizeDatesDisplay(string $raw): string
    {
        $s = trim($raw);
        if ($s === '' || $s === '未提供') {
            return '未提供';
        }

        $parts = preg_split('/\s*、\s*/u', $s) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $mmdd = self::tokenToMmDd($p);
            $out[] = $mmdd ?? $p;
        }

        return $out === [] ? $s : implode('、', $out);
    }

    private static function tokenToMmDd(string $token): ?string
    {
        $s = trim($token);
        if ($s === '' || $s === '未提供') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/\.\s年](\d{1,2})[-\/\.\s月](\d{1,2})/u', $s, $m) === 1) {
            return sprintf('%02d/%02d', (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/](\d{1,2})$/u', $s, $m) === 1) {
            return sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})\b/u', $s, $m) === 1) {
            return sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $s, $m) === 1) {
            return sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /**
     * @return list<array{title: string, date: string, price: string, departure: string, detail_page: string, schedule_lines: list<string>}>
     */
    private static function parseItemBlocks(string $body): array
    {
        $out = [];
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $current = null;

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^-{20}$/', $line) === 1
                || preg_match('/^─+$/u', $line) === 1
                || (preg_match('/^=+$/', $line) === 1 && strlen($line) >= 20)) {
                continue;
            }
            if ($current !== null && !empty($current['schedule_mode'])) {
                if (preg_match('/^\d+\.\s+https?:\/\//iu', $line) === 1) {
                    $current['schedule_lines'][] = $line;
                    continue;
                }
                $current['schedule_mode'] = false;
            }
            $titleCandidate = '';
            if (preg_match('/^\d+\.\s*行程名稱：(.+)$/u', $line, $m) === 1) {
                $titleCandidate = trim($m[1]);
            } elseif (preg_match('/^\d+\.\s+(.+)$/u', $line, $m) === 1) {
                $titleCandidate = trim($m[1]);
            }
            if ($titleCandidate !== '') {
                if (mb_strpos($titleCandidate, '最近出團', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '出團日期', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '直售價', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '價格', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '售價', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '出發地', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '詳細內容', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '行程內頁', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '行程表', 0, 'UTF-8') === 0) {
                    continue;
                }
                if ($current !== null) {
                    $out[] = $current;
                }
                $current = [
                    'title' => $titleCandidate,
                    'date' => '未提供',
                    'price' => '未提供',
                    'departure' => '未提供',
                    'detail_page' => '',
                    'schedule_lines' => [],
                    'schedule_mode' => false,
                ];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^(?:最近出團|出團日期)：(.+)$/u', $line, $m) === 1) {
                $current['date'] = trim($m[1]);
                continue;
            }
            if (preg_match('/^(?:直售價|價格|售價)：(.+)$/u', $line, $m) === 1) {
                $current['price'] = trim($m[1]);
                continue;
            }
            if (preg_match('/^出發地：(.+)$/u', $line, $m) === 1) {
                $current['departure'] = trim($m[1]);
                continue;
            }
            if (preg_match('/^(?:詳細內容|行程內頁)：(.+)$/u', $line, $m) === 1) {
                $current['detail_page'] = trim($m[1]);
                continue;
            }
            if ($line === '行程表：') {
                $current['schedule_mode'] = true;
                $current['schedule_lines'][] = '行程表：';
                continue;
            }
            if (preg_match('/^行程表：(.+)$/u', $line, $m) === 1) {
                $rest = trim($m[1]);
                if ($rest !== '') {
                    $current['schedule_lines'][] = '行程表：' . $rest;
                }
                continue;
            }
        }
        if ($current !== null) {
            $out[] = $current;
        }

        return $out;
    }

    private static function formatScheduleOutputLine(string $line): string
    {
        if ($line === '行程表：') {
            return self::LINE_SCHEDULE_LABEL;
        }
        if (strpos($line, '行程表：') === 0) {
            return self::LINE_SCHEDULE_LABEL . substr($line, strlen('行程表：'));
        }

        return $line;
    }
}
