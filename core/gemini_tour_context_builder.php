<?php
declare(strict_types=1);

/**
 * Stage 1-B-14: Build Gemini-ready Chinese context from TourSearchApiClient results.
 * No Gemini API, no DB, no webhook wiring.
 */
final class GeminiTourContextBuilder
{
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
     * @param array<string, mixed> $options maxItems (int), title (string), includeInstructions (bool)
     */
    public function build(array $apiResult, array $options = []): string
    {
        $maxItems = isset($options['maxItems']) ? max(1, (int) $options['maxItems']) : 5;
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

        $slice = array_slice($normalizedItems, 0, $maxItems);
        if ($slice !== []) {
            $lines[] = '以下是部分可推薦給客人的行程：';
            $index = 1;
            foreach ($slice as $item) {
                $lines[] = '';
                $lines[] = $this->formatItemBlock($index, $item);
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
            $lines[] = '完整搜尋結果連結：';
            $lines[] = $searchUrl;
            return;
        }

        $lines[] = '完整搜尋結果連結：目前未提供搜尋結果連結';
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
     * @param array<string, mixed> $item
     */
    private function formatItemBlock(int $index, array $item): string
    {
        $safe = $this->stripSensitiveKeys($item);

        $name = $this->fieldValue($safe, ['title', 'couponName', 'name', '行程名稱']);
        $date = $this->fieldValue($safe, ['tourDate', 'departure_date', '出團日期']);
        $qty = $this->fieldValue($safe, ['stock', 'qty', 'quantity', '可售數量', 'availableQty']);
        $price = $this->fieldValue($safe, ['price', '直售價', 'retailPrice']);
        $rebate = $this->fieldValue($safe, ['tradeRefund', 'agent_rebate', '同業後退', 'rebate']);

        $block = [];
        $block[] = $index . '. 行程名稱：' . $name;
        $block[] = '   出團日期：' . $date;
        $block[] = '   可售數量：' . $qty;
        $block[] = '   直售價：' . $this->formatPrice($price);
        $block[] = '   同業後退：' . $this->formatPrice($rebate);

        return implode("\n", $block);
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
        return "請 Gemini 回覆客人時：\n"
            . "- 優先用自然中文整理推薦\n"
            . "- 不要捏造不存在的行程\n"
            . "- 若可售數量不足，需提醒客人以實際客服確認為準\n"
            . "- 必須附上完整搜尋結果連結\n"
            . "- 不要暴露內部 API、depID、storeNo、provider_id_no、api key、traceId";
    }
}
