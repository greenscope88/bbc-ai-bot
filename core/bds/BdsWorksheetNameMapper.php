<?php
declare(strict_types=1);

/**
 * BDS Phase 7-1e.7 — Worksheet display name → Canonical Key mapping for Upload Portal.
 *
 * @see docs/BATS_DATA_CONTRACT.md §4.4
 * @see docs/BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md §13.1.9
 */
final class BdsWorksheetNameMapper
{
    public const FILE_READ_ERROR_MESSAGE = '無法讀取 Excel 檔案，請確認檔案未損壞且為 .xlsx 格式。';

    /** @var list<string> */
    public const CANONICAL_KEYS = [
        'company_profile',
        'qa',
        'external_product_links',
        'service_items',
        'special_prices',
    ];

    /** @var array<string, string> canonical key => 中文顯示名 */
    public const DISPLAY_NAMES = [
        'company_profile' => '公司基本資料',
        'qa' => '問與答',
        'external_product_links' => '其他商品源',
        'service_items' => '服務項目',
        'special_prices' => '特殊價格',
    ];

    /** @var array<string, string> lowercase english alias => canonical */
    private static array $englishAliasMap = [
        'company_profile' => 'company_profile',
        'qa' => 'qa',
        'external_product_links' => 'external_product_links',
        'service_items' => 'service_items',
        'special_prices' => 'special_prices',
    ];

    /** @var array<string, string> exact chinese alias => canonical */
    private static array $chineseAliasMap = [
        '公司基本資料' => 'company_profile',
        '公司資料' => 'company_profile',
        '問與答' => 'qa',
        '常見問題' => 'qa',
        '產品連結' => 'external_product_links',
        '其他商品源' => 'external_product_links',
        '服務項目' => 'service_items',
        '特殊價格' => 'special_prices',
    ];

    /**
     * @param list<string> $rawSheetNames Worksheet names from the workbook (order irrelevant)
     * @return array{
     *   mapped: array<string, string>,
     *   ignored: list<string>
     * }
     */
    public function resolve(array $rawSheetNames): array
    {
        /** @var array<string, list<string>> */
        $canonicalToSheets = [];
        $ignored = [];

        foreach ($rawSheetNames as $rawName) {
            $displayName = trim((string) $rawName);
            if ($displayName === '') {
                continue;
            }

            $canonical = $this->matchCanonicalKey($displayName);
            if ($canonical === null) {
                $ignored[] = $displayName;
                continue;
            }

            if (!isset($canonicalToSheets[$canonical])) {
                $canonicalToSheets[$canonical] = [];
            }
            $canonicalToSheets[$canonical][] = $displayName;
        }

        foreach ($canonicalToSheets as $canonical => $sheets) {
            if (count($sheets) > 1) {
                throw new \InvalidArgumentException($this->formatDuplicateError($sheets, $canonical));
            }
        }

        $missing = [];
        foreach (self::CANONICAL_KEYS as $canonical) {
            if (!isset($canonicalToSheets[$canonical])) {
                $missing[] = $canonical;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException($this->formatMissingError($missing));
        }

        $mapped = [];
        foreach ($canonicalToSheets as $canonical => $sheets) {
            $mapped[$canonical] = $sheets[0];
        }

        return [
            'mapped' => $mapped,
            'ignored' => $ignored,
        ];
    }

    public function matchCanonicalKey(string $rawName): ?string
    {
        $trimmed = trim($rawName);
        if ($trimmed === '') {
            return null;
        }

        $lower = strtolower($trimmed);
        if (isset(self::$englishAliasMap[$lower])) {
            return self::$englishAliasMap[$lower];
        }

        if (isset(self::$chineseAliasMap[$trimmed])) {
            return self::$chineseAliasMap[$trimmed];
        }

        return null;
    }

    /**
     * @param list<string> $missingCanonicalKeys
     */
    public function formatMissingError(array $missingCanonicalKeys): string
    {
        $displayNames = [];
        foreach ($missingCanonicalKeys as $canonical) {
            $displayNames[] = self::DISPLAY_NAMES[$canonical] ?? $canonical;
        }

        if (count($displayNames) === 1) {
            return '缺少「' . $displayNames[0] . '」工作表';
        }

        return "缺少必要工作表：\n\n" . implode("\n", $displayNames);
    }

    /**
     * @param list<string> $sheetNames
     */
    private function formatDuplicateError(array $sheetNames, string $canonical): string
    {
        $display = self::DISPLAY_NAMES[$canonical] ?? $canonical;
        if (count($sheetNames) < 2) {
            return '工作表名稱重複對應「' . $display . '」。';
        }

        $first = $sheetNames[0];
        $second = $sheetNames[1];

        return '工作表「' . $first . '」與「' . $second . '」皆對應「' . $display . '」，請保留其中一個並重新命名。';
    }
}
