<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds'
    . DIRECTORY_SEPARATOR . 'BdsUploadPortalValidationMessageFormatter.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$fixtureDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'output'
    . DIRECTORY_SEPARATOR . 'portal_validation_message';
if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0777, true) && !is_dir($fixtureDir)) {
    fwrite(STDERR, "FAIL: cannot create fixture dir\n");
    exit(1);
}

$addressErrorReport = $fixtureDir . DIRECTORY_SEPARATOR . 'error_report_address.json';
file_put_contents($addressErrorReport, json_encode([
    'errors' => [[
        'code' => 'BDC_INVALID_FIELD_NAME',
        'message' => 'Field name must be English snake_case in v1: 地址',
        'tab' => 'company_profile',
        'row_index' => null,
        'field' => '地址',
    ]],
    'generated_at' => '2026-06-19T09:10:18Z',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

$qaErrorReport = $fixtureDir . DIRECTORY_SEPARATOR . 'error_report_qa.json';
file_put_contents($qaErrorReport, json_encode([
    'errors' => [[
        'code' => 'BDC_INVALID_FIELD_NAME',
        'message' => 'Field name must be English snake_case in v1: 問題',
        'tab' => 'qa',
        'row_index' => 0,
        'field' => '問題',
    ]],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

$addressMessage = BdsUploadPortalValidationMessageFormatter::formatFromErrorReport(
    $addressErrorReport,
    'ERROR: validation failed; GCS not modified'
);
test_assert(is_string($addressMessage), 'address: returns string');
test_assert(strpos($addressMessage, '公司基本資料') !== false, 'address: tab display name');
test_assert(strpos($addressMessage, '錯誤欄位：') !== false, 'address: error field label');
test_assert(strpos($addressMessage, '地址') !== false, 'address: invalid field shown');
test_assert(strpos($addressMessage, 'address') !== false, 'address: suggested field');
test_assert(strpos($addressMessage, 'company_name') !== false, 'address: reserved fields listed');
test_assert(strpos($addressMessage, 'BDC_INVALID_FIELD_NAME') === false, 'address: no raw error code');
test_assert(strpos($addressMessage, 'GCS not modified') === false, 'address: no CLI english');

$qaMessage = BdsUploadPortalValidationMessageFormatter::formatFromErrorReport($qaErrorReport, '');
test_assert(is_string($qaMessage), 'qa: returns string');
test_assert(strpos($qaMessage, '問與答') !== false, 'qa: tab display name');
test_assert(strpos($qaMessage, 'question') !== false, 'qa: suggested question field');
test_assert(strpos($qaMessage, 'answer') !== false, 'qa: reserved answer field');

$generic = BdsUploadPortalValidationMessageFormatter::formatFromErrorReport(
    $fixtureDir . DIRECTORY_SEPARATOR . 'missing.json',
    'ERROR: validation failed; GCS not modified'
);
test_assert(is_string($generic), 'generic: returns string');
test_assert(strpos($generic, '格式檢查未通過') !== false, 'generic: chinese validation message');

$noMatch = BdsUploadPortalValidationMessageFormatter::formatFromErrorReport(
    $fixtureDir . DIRECTORY_SEPARATOR . 'missing.json',
    'CLI process creation failed.'
);
test_assert($noMatch === null, 'non-validation: returns null for fallback');

test_assert(
    BdsUploadPortalValidationMessageFormatter::suggestCorrectFieldName('special_prices', '項目名稱') === 'item_name',
    'special_prices: 項目名稱 -> item_name'
);
test_assert(
    BdsUploadPortalValidationMessageFormatter::suggestCorrectFieldName('external_product_links', '網址') === 'url',
    'external_product_links: 網址 -> url'
);

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_upload_portal_validation_message (all passed)\n");
exit(0);
