<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tour_detail_url_builder.php';

/**
 * Stage 1-B-14: Build Gemini-ready Chinese context from TourSearchApiClient results.
 * No Gemini API, no DB, no webhook wiring.
 */
final class GeminiTourContextBuilder
{
    private ?int $contextStoreNo = null;

    private ?TourDetailUrlBuilder $detailUrlBuilder = null;
    /** Max raw API items to merge before capping display rows (see TourPromptContextService pageSize). */
    private const DEFAULT_API_RAW_LIMIT = 30;

    /** Between numbered tour rows in LINE-facing context (= ×40, ~2× former 20-dash line). */
    public const LINE_TOUR_ITEM_SEPARATOR = '========================================';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'api_key',
        'apiKey',
        'x-api-key',
        'traceId',
        'trace_id',
        'depID',
        'dep_id',
        'storeNo',
        'store_no',
        'store_uid',
        'storeUid',
        'provider_id_no',
        'providerIdNo',
        'internal_url',
        'upstream_url',
        'sql',
        'stack',
        'stackTrace',
    ];

    /**
     * @param array<string, mixed> $apiResult TourSearchApiClient::search() payload
     * @param array<string, mixed> $options maxItems (int), apiRawLimit (int), title (string), includeInstructions (bool), storeNo (int), detailUrlBuilder (TourDetailUrlBuilder)
     */
    public function build(array $apiResult, array $options = []): string
    {
        $this->contextStoreNo = $this->resolveStoreNoOption($options);
        if (isset($options['detailUrlBuilder']) && $options['detailUrlBuilder'] instanceof TourDetailUrlBuilder) {
            $this->detailUrlBuilder = $options['detailUrlBuilder'];
        } elseif ($this->contextStoreNo !== null) {
            $this->detailUrlBuilder = new TourDetailUrlBuilder();
        } else {
            $this->detailUrlBuilder = null;
        }

        $maxItems = isset($options['maxItems']) ? max(1, (int) $options['maxItems']) : 5;
        $apiRawLimit = isset($options['apiRawLimit']) ? max(1, min(100, (int) $options['apiRawLimit'])) : self::DEFAULT_API_RAW_LIMIT;
        $title = isset($options['title']) && is_string($options['title']) && trim($options['title']) !== ''
            ? trim($options['title'])
            : '旅遊產品搜尋結果';
        $includeInstructions = !array_key_exists('includeInstructions', $options)
            || (bool) $options['includeInstructions'];

        $lines = [];
        $lines[] = '【' . $title . '】';

        if (($apiResult['success'] ?? false) !== true) {
            $lines[] = '目前未能取得可推薦的行程資料，請以禮貌用語回覆客人，並建議稍後再試或聯繫客服。';
            $this->appendSearchUrlLines($lines, $this->safeSearchUrl($apiResult));
            if ($includeInstructions) {
                $lines[] = '';
                $lines[] = $this->instructionBlock();
            }

            return implode("\n", $lines);
        }

        $pagination = $apiResult['pagination'] ?? null;
        $total = 0;
        if (is_array($pagination) && isset($pagination['total'])) {
            $total = (int) $pagination['total'];
        }

        $items = $apiResult['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $normalizedItems = [];
        foreach ($items as $row) {
            if (is_array($row)) {
                $normalizedItems[] = $row;
            }
        }

        if ($total <= 0 && $normalizedItems === []) {
            $lines[] = '目前沒有找到符合條件的行程。';
            $this->appendSearchUrlLines($lines, $this->safeSearchUrl($apiResult));
            if ($includeInstructions) {
                $lines[] = '';
                $lines[] = $this->instructionBlock();
            }

            return implode("\n", $lines);
        }

        if ($total > 0) {
            $lines[] = '本次查詢共找到 ' . $total . ' 筆相關行程。';
        }

        $slice = array_slice($normalizedItems, 0, $apiRawLimit);
        if ($slice !== []) {
            $lines[] = '以下為系統整理的可引用行程（已將日期改為 MM/DD，不含年份；相同行程名稱＋相同出發地＋相同商品編號之多日出團會合併為一筆；以下最多列出 ' . $maxItems . ' 筆）：';
            $displayRows = $this->buildMergedDisplayRows($slice);
            $displayRows = array_slice($displayRows, 0, $maxItems);
            $lines[] = '';
            $index = 1;
            foreach ($displayRows as $i => $row) {
                if ($i > 0) {
                    $lines[] = self::LINE_TOUR_ITEM_SEPARATOR;
                    $lines[] = '';
                }
                $lines[] = $this->formatItemBlock($index, $row['item'], $row['dates_display']);
                ++$index;
            }
        }

        $lines[] = '';
        $this->appendSearchUrlLines($lines, $this->safeSearchUrl($apiResult));

        if ($includeInstructions) {
            $lines[] = '';
            $lines[] = $this->instructionBlock();
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function appendSearchUrlLines(array &$lines, ?string $searchUrl): void
    {
        if ($searchUrl !== null && $searchUrl !== '') {
            $lines[] = '完整搜尋結果：';
            $lines[] = $searchUrl;
            return;
        }

        $lines[] = '完整搜尋結果：目前未提供連結';
    }

    /**
     * @param array<string, mixed> $apiResult
     */
    private function safeSearchUrl(array $apiResult): ?string
    {
        $url = $apiResult['search_url'] ?? null;
        if (!is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $lower = strtolower($trimmed);
        foreach (['api_key=', 'traceid=', 'depid=', 'provider_id'] as $bad) {
            if (strpos($lower, $bad) !== false) {
                return null;
            }
        }

        return $trimmed;
    }

    /**
     * Merge consecutive API rows with the same merge key (title + departure + couponNo); combine dates as MM/DD、MM/DD.
     *
     * @param list<array<string, mixed>> $slice
     * @return list<array{item: array<string, mixed>, dates_display: string}>
     */
    private function buildMergedDisplayRows(array $slice): array
    {
        /** @var list<array{item: array<string, mixed>, dates_display: string}> $out */
        $out = [];

        /** @var array<string, mixed>|null $pendingItem */
        $pendingItem = null;
        /** @var list<string> $pendingDates */
        $pendingDates = [];
        /** @var string|null $pendingMergeKey */
        $pendingMergeKey = null;

        $flush = static function () use (&$out, &$pendingItem, &$pendingDates, &$pendingMergeKey): void {
            if ($pendingItem === null || $pendingMergeKey === null) {
                return;
            }
            $uniq = [];
            foreach ($pendingDates as $d) {
                if ($d === '') {
                    continue;
                }
                if (!in_array($d, $uniq, true)) {
                    $uniq[] = $d;
                }
            }
            $display = $uniq === [] ? '未提供' : implode('、', $uniq);
            $out[] = ['item' => $pendingItem, 'dates_display' => $display];
            $pendingItem = null;
            $pendingDates = [];
            $pendingMergeKey = null;
        };

        foreach ($slice as $item) {
            if (!is_array($item)) {
                continue;
            }
            $safe = $this->stripSensitiveKeys($item);
            $mergeKey = $this->mergeGroupKey($safe);
            $rawDate = $this->fieldValue($safe, ['tourDate', 'departure_date', '出團日期']);
            $mmdd = $this->formatDateAsMmDd($rawDate);

            if ($pendingMergeKey !== null && $mergeKey !== $pendingMergeKey) {
                $flush();
            }

            if ($pendingMergeKey === null) {
                $pendingMergeKey = $mergeKey;
                $pendingItem = $safe;
            }

            if ($mmdd !== null) {
                $pendingDates[] = $mmdd;
            }
        }

        $flush();

        return $out;
    }

    /**
     * Merge key: visible title + normalized departure label + couponNo (string).
     *
     * @param array<string, mixed> $safe
     */
    private function mergeGroupKey(array $safe): string
    {
        $title = $this->fieldValue($safe, ['title', 'couponName', 'name', '行程名稱']);
        $dep = $this->resolveDepartureLabelForMerge($safe);
        $coupon = $this->couponMergeToken($safe);

        return $title . "\0" . $dep . "\0" . $coupon;
    }

    /**
     * @param array<string, mixed> $safe
     */
    private function couponMergeToken(array $safe): string
    {
        if (!array_key_exists('couponNo', $safe)) {
            return '';
        }
        $v = $safe['couponNo'];
        if ($v === null || $v === '') {
            return '';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', trim($v)) === 1) {
            return (string) (int) $v;
        }

        return trim((string) $v);
    }

    /**
     * Same rules as customer-facing departure, without mapping to「未提供」for merge grouping when missing (empty string groups together).
     *
     * @param array<string, mixed> $safe
     */
    private function resolveDepartureLabelForMerge(array $safe): string
    {
        $display = $this->resolveDepartureDisplayLabel($safe);

        return $display === '未提供' ? '' : $display;
    }

    /**
     * Priority: departureStr (trim / strip trailing「出發」) → title prefix「〇〇出發│」→ 未提供.
     *
     * @param array<string, mixed> $safe
     */
    private function resolveDepartureDisplayLabel(array $safe): string
    {
        $fromField = '';
        if (array_key_exists('departureStr', $safe)) {
            $v = $safe['departureStr'];
            if (is_scalar($v) && trim((string) $v) !== '') {
                $fromField = $this->stripTrailingDepartureWord(trim((string) $v));
            }
        }

        if ($fromField !== '') {
            return $fromField;
        }

        $title = $this->fieldValue($safe, ['title', 'couponName', 'name', '行程名稱']);
        if ($title !== '未提供' && $title !== '') {
            if (preg_match('/^(.+?)出發\s*[│｜|]/u', $title, $m) === 1) {
                $city = trim($m[1]);
                if ($city !== '') {
                    return $this->stripTrailingDepartureWord($city);
                }
            }
        }

        return '未提供';
    }

    private function stripTrailingDepartureWord(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(.+?)出發$/u', $s, $m) === 1 && trim($m[1]) !== '') {
            return trim($m[1]);
        }

        return $s;
    }

    /**
     * Normalize a single date token to MM/DD (no year). Returns null when not parseable / 未提供.
     */
    private function formatDateAsMmDd(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '' || $s === '未提供') {
            return null;
        }

        // 2026-06-01, 2026/6/1, 2026.06.01, 2026年6月1日
        if (preg_match('/^(\d{4})[-\/\.\s年](\d{1,2})[-\/\.\s月](\d{1,2})/u', $s, $m) === 1) {
            return sprintf('%02d/%02d', (int) $m[2], (int) $m[3]);
        }

        // Already MM/DD or M/D
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})\b/u', $s, $m) === 1) {
            return sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $s, $m) === 1) {
            return sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item Baseline row (first occurrence in merge group)
     */
    private function formatItemBlock(int $index, array $item, string $datesDisplay): string
    {
        $safe = $this->stripSensitiveKeys($item);

        $name = $this->fieldValue($safe, ['title', 'couponName', 'name', '行程名稱']);
        $price = $this->fieldValue($safe, ['price', '直售價', 'retailPrice']);
        $priceLine = $this->formatDirectSaleDisplay($price);
        $departure = $this->resolveDepartureDisplayLabel($safe);

        $block = [];
        $block[] = $index . '. ' . $name;
        $block[] = '   出團日期：' . $datesDisplay;
        $block[] = '   直售價：' . $priceLine;
        $block[] = '   出發地：' . $departure;

        $detailUrl = $this->resolveDetailUrlForItem($safe);
        if ($detailUrl !== null) {
            $block[] = '   行程內頁：' . $detailUrl;
        }

        $schLink = $this->resolveSchLinkForItem($safe);
        if ($schLink !== null) {
            $block[] = '   行程表：' . $schLink;
        }

        return implode("\n", $block);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveStoreNoOption(array $options): ?int
    {
        if (!array_key_exists('storeNo', $options)) {
            return null;
        }

        $v = $options['storeNo'];
        if (is_int($v) && $v > 0) {
            return $v;
        }

        if (is_string($v) && preg_match('/^\d+$/', trim($v)) === 1) {
            $n = (int) trim($v);

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $safe
     */
    private function resolveDetailUrlForItem(array $safe): ?string
    {
        if ($this->contextStoreNo === null || $this->detailUrlBuilder === null) {
            return null;
        }

        $couponNo = $safe['couponNo'] ?? null;
        $tourSeqNo = $safe['tourSeqNo'] ?? ($safe['tour_seq_no'] ?? null);

        return $this->detailUrlBuilder->buildDetailUrl($this->contextStoreNo, $couponNo, $tourSeqNo);
    }

    /**
     * @param array<string, mixed> $safe
     */
    private function resolveSchLinkForItem(array $safe): ?string
    {
        if (!array_key_exists('schLink', $safe)) {
            return null;
        }

        $v = $safe['schLink'];
        if (!is_scalar($v)) {
            return null;
        }

        $trimmed = trim((string) $v);
        if ($trimmed === '') {
            return null;
        }

        $lower = strtolower($trimmed);
        foreach (['api_key=', 'traceid=', 'depid='] as $bad) {
            if (strpos($lower, $bad) !== false) {
                return null;
            }
        }

        return $trimmed;
    }

    private function formatDirectSaleDisplay(string $raw): string
    {
        $p = $this->formatPrice($raw);
        if ($p === '未提供') {
            return '未提供';
        }
        if (preg_match('/起\s*$/u', $p) === 1) {
            return $p;
        }

        return $p . ' 起';
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     */
    private function fieldValue(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }
            if ($this->isSensitiveKey($key)) {
                continue;
            }
            $value = $item[$key];
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if (is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return '未提供';
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function stripSensitiveKeys(array $item): array
    {
        $out = [];
        foreach ($item as $key => $value) {
            if (!is_string($key) || $this->isSensitiveKey($key)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($lower === strtolower($sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function formatPrice(string $raw): string
    {
        if ($raw === '未提供') {
            return '未提供';
        }

        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return 'NT$' . number_format((int) $raw);
        }

        if (is_numeric($raw)) {
            return 'NT$' . number_format((float) $raw);
        }

        return $raw;
    }

    private function instructionBlock(): string
    {
        return "請 Gemini 回覆客人時（LINE 版面優先，務必遵守）：\n"
            . "- 以固定清單呈現行程；每筆前必須有清楚編號 1. 2. 3.（與下方參考列點格式一致）；最多呈現 5 筆合併後行程。\n"
            . "- 每兩筆行程之間必須保留單獨一行「" . self::LINE_TOUR_ITEM_SEPARATOR . "」分隔線（僅連續「=」組成，與參考文字逐字一致）；勿刪除、勿改成虛線或其他符號；勿在最後一筆行程後再加一道分隔線（「完整搜尋結果：」前不可再出現該分隔線）。\n"
            . "- 第一行為完整行程標題（單行）；禁止使用「想玩○○？」「想體驗…」這類分類式小標。\n"
            . "- 第二行縮排：出團日期：僅使用 MM/DD（例如 06/01、06/10）；不要顯示年份（例如 2026）；同一商品多個出團日可合併為「06/01、06/10」。\n"
            . "- 第三行縮排：直售價：沿用參考中的金額與幣別格式，若參考已含「起」字則保留，否則可加上「起」使語意一致。\n"
            . "- 第四行縮排：每筆行程必須保留「出發地：」，格式為「出發地：台北」或「出發地：高雄」等（與參考一致）；不要把出發地和目的地／景區名稱混淆。\n"
            . "- 若參考中有「行程內頁：」後的 URL，必須逐字保留該行（不可改寫、不可縮短、不可替換成其他網址）。\n"
            . "- 若參考中有「行程表：」後的 URL，必須逐字保留該行；若參考無此行則不要自行新增行程表連結。\n"
            . "- 若「完整搜尋結果」連結存在於參考中，回覆結尾必須原樣附上該 URL（不可省略）。\n"
            . "- 只引用參考區塊出現過的行程；不得捏造、改寫行程名稱或杜撰日期/價格/出發地。\n"
            . "- 若無法合併多日期，仍須維持每筆相同欄位順序與排版（編號＋標題＋出團日期＋直售價＋出發地；參考若有則接行程內頁、行程表）。\n"
            . "- 不要暴露內部 API、depID、storeNo、provider_id_no、api key、traceId 等敏感欄位。";
    }
}
