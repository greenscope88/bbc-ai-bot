<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsWorksheetNameMapper.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsHeaderContract.php';

/**
 * Phase 6A — Upload Portal validation error display (Chinese UX).
 *
 * Reads BDS error_report.json; does not change validator or sync flow.
 *
 * @see docs/BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md §13.1.9
 */
final class BdsUploadPortalValidationMessageFormatter
{
    /** @var list<string> */
    private const HEADER_CONTRACT_ERROR_CODES = [
        'BDC_INVALID_FIELD_NAME',
        'BDC_HEADER_NOT_ALLOWED',
        'BDC_REQUIRED_HEADER_MISSING',
    ];

    /**
     * Common Chinese header mistakes => English field (applied only when field exists on tab).
     *
     * @var array<string, string>
     */
    private const CHINESE_FIELD_HINTS = [
        '公司名稱' => 'company_name',
        '地址' => 'address',
        '電話' => 'phone',
        '問題' => 'question',
        '答案' => 'answer',
        '項目名稱' => 'item_name',
        '價格' => 'price_amount',
        '價格金額' => 'price_amount',
        '服務名稱' => 'name',
        '連結名稱' => 'name',
        '網址' => 'url',
    ];

    /**
     * @return string|null User-facing reason block, or null to fall back to CLI text.
     */
    public static function formatFromErrorReport(string $errorReportPath, string $cliReason = ''): ?string
    {
        $headerErrors = self::loadHeaderContractErrors($errorReportPath);
        if ($headerErrors !== []) {
            return self::formatHeaderContractErrors($headerErrors);
        }

        if (self::isValidationFailureReason($cliReason)) {
            return self::formatGenericValidationFailure();
        }

        return null;
    }

    /**
     * @return list<array{code?: string, tab?: ?string, field?: ?string, row_index?: ?int, message?: string}>
     */
    public static function loadHeaderContractErrors(string $errorReportPath): array
    {
        $matched = [];
        foreach (self::HEADER_CONTRACT_ERROR_CODES as $code) {
            foreach (self::loadErrorsByCode($errorReportPath, $code) as $error) {
                $matched[] = $error;
            }
        }

        return $matched;
    }

    /**
     * @return list<array{code?: string, tab?: ?string, field?: ?string, row_index?: ?int, message?: string}>
     */
    public static function loadErrorsByCode(string $errorReportPath, string $errorCode): array
    {
        $errorReportPath = trim($errorReportPath);
        if ($errorReportPath === '' || !is_file($errorReportPath)) {
            return [];
        }

        $raw = @file_get_contents($errorReportPath);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['errors']) || !is_array($data['errors'])) {
            return [];
        }

        $matched = [];
        foreach ($data['errors'] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $code = isset($error['code']) ? (string) $error['code'] : '';
            if ($code === $errorCode) {
                $matched[] = $error;
            }
        }

        return $matched;
    }

    /**
     * @param list<array{code?: string, tab?: ?string, field?: ?string, row_index?: ?int, message?: string}> $errors
     */
    public static function formatHeaderContractErrors(array $errors): string
    {
        $blocks = [];
        $blocks[] = '本次上傳資料格式檢查未通過。';
        $blocks[] = '';
        $blocks[] = '您修改了系統保留欄位名稱，或缺少必要欄位。';

        $grouped = [];
        foreach ($errors as $error) {
            $tab = isset($error['tab']) ? trim((string) $error['tab']) : '';
            if (!isset($grouped[$tab])) {
                $grouped[$tab] = [];
            }
            $grouped[$tab][] = $error;
        }

        foreach ($grouped as $tab => $tabErrors) {
            $blocks[] = '';
            $blocks[] = '分頁：';
            $blocks[] = self::tabDisplayName($tab);
            $blocks[] = '';
            $blocks[] = '錯誤欄位：';

            $fields = [];
            foreach ($tabErrors as $error) {
                $field = isset($error['field']) ? trim((string) $error['field']) : '';
                if ($field === '' && isset($error['message'])) {
                    $field = self::extractFieldFromMessage((string) $error['message']);
                }
                if ($field !== '' && !in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }

            $blocks[] = $fields !== [] ? implode("\n", $fields) : '（無法辨識）';
            $blocks[] = '';
            $blocks[] = '本分頁允許使用的欄位名稱：';

            $allowed = self::tabFieldNames($tab);
            $blocks[] = $allowed !== [] ? implode("\n", $allowed) : '（請使用英文欄位名稱）';
        }

        $blocks[] = '';
        $blocks[] = '請勿自行新增、刪除或修改系統欄位名稱。';
        $blocks[] = '';
        $blocks[] = '只需修改第 2 列以後的資料即可。';
        $blocks[] = '';
        $blocks[] = '修正後請重新上傳。';

        return implode("\n", $blocks);
    }

    /**
     * @param list<array{code?: string, tab?: ?string, field?: ?string, row_index?: ?int, message?: string}> $errors
     */
    public static function formatInvalidFieldNameErrors(array $errors): string
    {
        return self::formatHeaderContractErrors($errors);
    }

    public static function formatGenericValidationFailure(): string
    {
        return "本次上傳資料格式檢查未通過。\n\n"
            . "請確認各分頁第 1 列欄位名稱為系統保留的英文名稱，\n"
            . "資料請填寫於第 2 列以後。\n\n"
            . "修正後請重新上傳。";
    }

    public static function isValidationFailureReason(string $reason): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            return false;
        }

        $patterns = [
            '/validation\s+failed/i',
            '/BDC_INVALID_FIELD_NAME/i',
            '/BDC_HEADER_NOT_ALLOWED/i',
            '/BDC_REQUIRED_HEADER_MISSING/i',
            '/Validation:\s*FAIL/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $reason) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function tabDisplayName(string $canonicalTab): string
    {
        if ($canonicalTab !== '' && isset(BdsWorksheetNameMapper::DISPLAY_NAMES[$canonicalTab])) {
            return (string) BdsWorksheetNameMapper::DISPLAY_NAMES[$canonicalTab];
        }

        return $canonicalTab !== '' ? $canonicalTab : '（未知分頁）';
    }

    /**
     * @return list<string>
     */
    public static function tabFieldNames(string $canonicalTab): array
    {
        return BdsHeaderContract::allowedHeaders($canonicalTab);
    }

    public static function suggestCorrectFieldName(string $canonicalTab, string $invalidField): ?string
    {
        $invalidField = trim($invalidField);
        if ($invalidField === '') {
            return null;
        }

        $tabFields = self::tabFieldNames($canonicalTab);
        if ($tabFields === []) {
            return null;
        }

        if (isset(self::CHINESE_FIELD_HINTS[$invalidField])) {
            $hint = self::CHINESE_FIELD_HINTS[$invalidField];
            if (in_array($hint, $tabFields, true)) {
                return $hint;
            }
        }

        foreach ($tabFields as $field) {
            if (strcasecmp($invalidField, $field) === 0) {
                return $field;
            }
        }

        return null;
    }

    private static function extractFieldFromMessage(string $message): string
    {
        $message = trim($message);

        if (preg_match('/Field name must be English snake_case in v1:\s*(.+)$/u', $message, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/header is not allowed:\s*(.+)$/u', $message, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/required header is missing:\s*(.+)$/u', $message, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return '';
    }
}
