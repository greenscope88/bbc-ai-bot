<?php
declare(strict_types=1);

/**
 * BDS Phase 6C-1 — Drive file classification (owner_scope + data_category).
 *
 * @see docs/BATS_DRIVE_METADATA_CONTRACT.md §13
 */
final class BdsDriveFileClassifier
{
    public const OWNER_TENANT = 'tenant';

    public const OWNER_INDUSTRY = 'industry';

    public const OWNER_GLOBAL = 'global';

    public const OWNER_PLATFORM = 'platform';

    public const CATEGORY_ITINERARY = 'itinerary_data';

    public const CATEGORY_SHARED = 'shared_knowledge';

    public const CATEGORY_REGISTRATION = 'customer_registration';

    public const CATEGORY_ARCHIVE = 'archive';

    public const WARNING_EXCEL_AMBIGUOUS = 'W_CLS_EXCEL_AMBIGUOUS';

    /** @var list<string> */
    public const VALID_OWNER_SCOPES = [
        self::OWNER_TENANT,
        self::OWNER_INDUSTRY,
        self::OWNER_GLOBAL,
        self::OWNER_PLATFORM,
    ];

    /**
     * @return array{
     *   owner_scope: string,
     *   data_category: string,
     *   warnings: list<string>
     * }
     */
    public function classify(string $ownerScope, string $mimeType, string $fileName = ''): array
    {
        $ownerScope = trim($ownerScope);
        $mimeType = trim($mimeType);
        $fileName = trim($fileName);

        if (!in_array($ownerScope, self::VALID_OWNER_SCOPES, true)) {
            throw new \InvalidArgumentException('Invalid owner_scope: ' . $ownerScope);
        }

        $warnings = [];

        if ($ownerScope === self::OWNER_PLATFORM) {
            return [
                'owner_scope' => self::OWNER_PLATFORM,
                'data_category' => self::CATEGORY_REGISTRATION,
                'warnings' => $warnings,
            ];
        }

        $fileKind = $this->resolveFileKind($mimeType, $fileName);
        $dataCategory = $this->resolveDataCategory($ownerScope, $fileKind);

        if ($ownerScope === self::OWNER_TENANT && $fileKind === 'spreadsheet') {
            if (!$this->isLikelyItinerarySpreadsheet($fileName)) {
                $warnings[] = self::WARNING_EXCEL_AMBIGUOUS;
            }
        }

        return [
            'owner_scope' => $ownerScope,
            'data_category' => $dataCategory,
            'warnings' => $warnings,
        ];
    }

    private function resolveFileKind(string $mimeType, string $fileName): string
    {
        $mime = strtolower($mimeType);
        $lowerName = strtolower($fileName);

        if ($mime === 'application/pdf' || $this->endsWith($lowerName, '.pdf')) {
            return 'pdf';
        }

        if (strpos($mime, 'image/') === 0) {
            return 'image';
        }

        if (
            $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            || $mime === 'application/vnd.ms-excel'
            || $mime === 'text/csv'
            || $this->endsWith($lowerName, '.xlsx')
            || $this->endsWith($lowerName, '.xls')
            || $this->endsWith($lowerName, '.csv')
        ) {
            return 'spreadsheet';
        }

        if (
            $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            || $mime === 'application/msword'
            || $this->endsWith($lowerName, '.docx')
            || $this->endsWith($lowerName, '.doc')
        ) {
            return 'word';
        }

        if (
            $mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            || $mime === 'application/vnd.ms-powerpoint'
            || $this->endsWith($lowerName, '.pptx')
            || $this->endsWith($lowerName, '.ppt')
        ) {
            return 'ppt';
        }

        if ($mime === 'application/zip' || $this->endsWith($lowerName, '.zip')) {
            return 'zip';
        }

        return 'unknown';
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $length = strlen($needle);
        if ($length === 0) {
            return true;
        }

        return substr($haystack, -$length) === $needle;
    }

    private function resolveDataCategory(string $ownerScope, string $fileKind): string
    {
        if ($fileKind === 'pdf' || $fileKind === 'image') {
            if ($ownerScope === self::OWNER_TENANT) {
                return self::CATEGORY_ITINERARY;
            }

            return self::CATEGORY_SHARED;
        }

        if ($fileKind === 'spreadsheet') {
            if ($ownerScope === self::OWNER_TENANT) {
                return self::CATEGORY_ITINERARY;
            }

            return self::CATEGORY_SHARED;
        }

        if ($fileKind === 'word' || $fileKind === 'ppt' || $fileKind === 'zip') {
            if ($ownerScope === self::OWNER_TENANT) {
                return self::CATEGORY_ARCHIVE;
            }

            return self::CATEGORY_SHARED;
        }

        if ($ownerScope === self::OWNER_TENANT) {
            return self::CATEGORY_ARCHIVE;
        }

        return self::CATEGORY_SHARED;
    }

    private function isLikelyItinerarySpreadsheet(string $fileName): bool
    {
        if ($fileName === '') {
            return false;
        }

        return (bool) preg_match('/itinerary|行程|商品|tour|product/i', $fileName);
    }
}
