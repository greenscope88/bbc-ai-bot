<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'gemini_tour_context_builder.php';

/**
 * LINE-friendly reply when Gemini fails but tour prompt context exists.
 * Parses the Chinese block produced by GeminiTourContextBuilder (no Gemini call).
 */
final class TourFallbackFormatter
{
    /** Same string as Gemini tour context numbered blocks. */
    private const LINE_TOUR_ITEM_SEPARATOR = GeminiTourContextBuilder::LINE_TOUR_ITEM_SEPARATOR;

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
            $n = 1;
            $sliceItems = array_slice($items, 0, 5);
            foreach ($sliceItems as $idx => $it) {
                if ($idx > 0) {
                    $lines[] = self::LINE_TOUR_ITEM_SEPARATOR;
                    $lines[] = '';
                }
                $lines[] = $n . '. ' . $it['title'];
                $lines[] = '   出團日期：' . self::normalizeDatesDisplay($it['date']);
                $lines[] = '   直售價：' . $it['price'];
                $lines[] = '   出發地：' . $it['departure'];
                ++$n;
            }
        }

        if ($url !== '') {
            $lines[] = '';
            $lines[] = '完整搜尋結果：';
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
        $needle = 'https://bonusmee.com';
        $pos = strpos($body, $needle);
        if ($pos === false) {
            return '';
        }
        $rest = substr($body, $pos);
        $token = strtok($rest, " \t\r\n");
        if (!is_string($token)) {
            return '';
        }

        return trim($token);
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
     * @return list<array{title: string, date: string, price: string, departure: string}>
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
                || (preg_match('/^=+$/', $line) === 1 && strlen($line) >= 20)) {
                continue;
            }
            $titleCandidate = '';
            if (preg_match('/^\d+\.\s*行程名稱：(.+)$/u', $line, $m) === 1) {
                $titleCandidate = trim($m[1]);
            } elseif (preg_match('/^\d+\.\s+(.+)$/u', $line, $m) === 1) {
                $titleCandidate = trim($m[1]);
            }
            if ($titleCandidate !== '') {
                if (mb_strpos($titleCandidate, '出團日期', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '直售價', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '價格', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '售價', 0, 'UTF-8') === 0
                    || mb_strpos($titleCandidate, '出發地', 0, 'UTF-8') === 0) {
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
                ];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^出團日期：(.+)$/u', $line, $m) === 1) {
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
        }
        if ($current !== null) {
            $out[] = $current;
        }

        return $out;
    }
}
