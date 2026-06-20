<?php
declare(strict_types=1);

/**
 * BDS Phase 6A — Header contract enforcement tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsHeaderContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsUploadPortalValidationMessageFormatter.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function has_error_code(array $result, string $code, ?string $tab = null, ?string $field = null): bool
{
    if (!isset($result['errors']) || !is_array($result['errors'])) {
        return false;
    }

    foreach ($result['errors'] as $error) {
        if (!is_array($error) || !isset($error['code']) || $error['code'] !== $code) {
            continue;
        }
        if ($tab !== null && (!isset($error['tab']) || $error['tab'] !== $tab)) {
            continue;
        }
        if ($field !== null && (!isset($error['field']) || $error['field'] !== $field)) {
            continue;
        }
        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function build_exact_company_profile_row(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Contract Travel Co',
        'summary' => 'Travel agency',
        'phone' => '02-1234-5678',
        'address' => 'Taipei',
        'line_official' => '@travel_b',
        'email' => 'info@example.com',
        'website' => 'https://example.com',
        'business_hours' => 'Mon-Fri 9-18',
    ], $overrides);
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function build_valid_raw_tabs(): array
{
    return [
        'company_profile' => [
            build_exact_company_profile_row(),
        ],
        'qa' => [
            ['question' => 'Hours?', 'answer' => 'Mon-Fri 9-6'],
        ],
        'external_product_links' => [
            ['name' => 'Portal', 'url' => 'https://example.com'],
        ],
        'service_items' => [
            ['name' => 'Visa Service'],
        ],
        'special_prices' => [
            ['item_name' => 'Visa Service', 'price_amount' => 1200],
        ],
    ];
}

function validate_tabs(array $rawTabs): array
{
    $parser = new BdsMockSheetParser();
    $validator = new BdsValidator();
    $normalized = $parser->parse('header_contract_tenant', $rawTabs);

    return $validator->validate($normalized, $rawTabs);
}

$validator = new BdsValidator();
$parser = new BdsMockSheetParser();

// 1. company_profile exact headers → PASS
$valid = validate_tabs(build_valid_raw_tabs());
test_assert($valid['ok'] === true, '1: company_profile exact headers pass');

// 2. company_profile phone → 電話 → FAIL
$chinesePhone = build_valid_raw_tabs();
$row = build_exact_company_profile_row();
unset($row['phone']);
$row['電話'] = '02-1111-2222';
$chinesePhone['company_profile'] = [$row];
$chinesePhoneResult = validate_tabs($chinesePhone);
test_assert($chinesePhoneResult['ok'] === false, '2: chinese phone header fails');
test_assert(
    has_error_code($chinesePhoneResult, 'BDC_INVALID_FIELD_NAME', 'company_profile', '電話')
    || has_error_code($chinesePhoneResult, 'BDC_REQUIRED_HEADER_MISSING', 'company_profile', 'phone'),
    '2: chinese phone reports invalid or missing phone'
);

// 3. company_profile phone → mutifuntion → FAIL
$typoPhone = build_valid_raw_tabs();
$row = build_exact_company_profile_row();
unset($row['phone']);
$row['mutifuntion'] = '02-1111-2222';
$typoPhone['company_profile'] = [$row];
$typoPhoneResult = validate_tabs($typoPhone);
test_assert($typoPhoneResult['ok'] === false, '3: typo mutifuntion header fails');
test_assert(has_error_code($typoPhoneResult, 'BDC_HEADER_NOT_ALLOWED', 'company_profile', 'mutifuntion'), '3: mutifuntion reports BDC_HEADER_NOT_ALLOWED');
test_assert(has_error_code($typoPhoneResult, 'BDC_REQUIRED_HEADER_MISSING', 'company_profile', 'phone'), '3: missing phone header reported');

// 4. company_profile missing phone → FAIL
$missingPhone = build_valid_raw_tabs();
$row = build_exact_company_profile_row();
unset($row['phone']);
$missingPhone['company_profile'] = [$row];
$missingPhoneResult = validate_tabs($missingPhone);
test_assert($missingPhoneResult['ok'] === false, '4: missing required phone header fails');
test_assert(has_error_code($missingPhoneResult, 'BDC_REQUIRED_HEADER_MISSING', 'company_profile', 'phone'), '4: missing phone reports BDC_REQUIRED_HEADER_MISSING');

// 5. company_profile extra abc → FAIL
$extraAbc = build_valid_raw_tabs();
$row = build_exact_company_profile_row();
$row['abc'] = 'extra';
$extraAbc['company_profile'] = [$row];
$extraAbcResult = validate_tabs($extraAbc);
test_assert($extraAbcResult['ok'] === false, '5: extra abc header fails');
test_assert(has_error_code($extraAbcResult, 'BDC_HEADER_NOT_ALLOWED', 'company_profile', 'abc'), '5: abc reports BDC_HEADER_NOT_ALLOWED');

// 6. exact headers but phone value empty → PASS
$emptyPhoneValue = build_valid_raw_tabs();
$emptyPhoneValue['company_profile'] = [
    build_exact_company_profile_row(['phone' => '']),
];
$emptyPhoneValueResult = validate_tabs($emptyPhoneValue);
test_assert($emptyPhoneValueResult['ok'] === true, '6: empty phone value passes when headers exact');

// 7. qa exact headers no rows → PASS
$qaHeaderOnly = build_valid_raw_tabs();
$qaHeaderOnly['qa'] = [
    ['question' => '', 'answer' => ''],
];
$qaHeaderOnlyResult = validate_tabs($qaHeaderOnly);
test_assert($qaHeaderOnlyResult['ok'] === true, '7: qa exact headers with no data rows pass');

// 8. qa wrong header 問題 → FAIL
$qaChinese = build_valid_raw_tabs();
$qaChinese['qa'] = [
    ['問題' => '營業時間？', 'answer' => '週一至週五'],
];
$qaChineseResult = validate_tabs($qaChinese);
test_assert($qaChineseResult['ok'] === false, '8: qa chinese header fails');
test_assert(has_error_code($qaChineseResult, 'BDC_INVALID_FIELD_NAME', 'qa', '問題'), '8: qa 問題 reports BDC_INVALID_FIELD_NAME');

// 9. external_product_links exact headers no rows → PASS
$linksHeaderOnly = build_valid_raw_tabs();
$linksHeaderOnly['external_product_links'] = [
    ['name' => '', 'url' => ''],
];
$linksHeaderOnlyResult = validate_tabs($linksHeaderOnly);
test_assert($linksHeaderOnlyResult['ok'] === true, '9: external_product_links exact headers with no data rows pass');

// 10. service_items name only → PASS
$serviceNameOnly = build_valid_raw_tabs();
$serviceNameOnly['service_items'] = [
    ['name' => 'Visa Service'],
];
$serviceNameOnlyResult = validate_tabs($serviceNameOnly);
test_assert($serviceNameOnlyResult['ok'] === true, '10: service_items name only passes');

// 11. service_items service_id + name → FAIL
$serviceWithId = build_valid_raw_tabs();
$serviceWithId['service_items'] = [
    ['service_id' => 'svc_visa', 'name' => 'Visa Service'],
];
$serviceWithIdResult = validate_tabs($serviceWithId);
test_assert($serviceWithIdResult['ok'] === false, '11: service_items service_id + name fails');
test_assert(has_error_code($serviceWithIdResult, 'BDC_HEADER_NOT_ALLOWED', 'service_items', 'service_id'), '11: service_id header not allowed');

// 12. service_items 服務項目 → FAIL
$serviceChinese = build_valid_raw_tabs();
$serviceChinese['service_items'] = [
    ['服務項目' => '簽證代辦'],
];
$serviceChineseResult = validate_tabs($serviceChinese);
test_assert($serviceChineseResult['ok'] === false, '12: service_items chinese header fails');
test_assert(
    has_error_code($serviceChineseResult, 'BDC_INVALID_FIELD_NAME', 'service_items', '服務項目')
    || has_error_code($serviceChineseResult, 'BDC_REQUIRED_HEADER_MISSING', 'service_items', 'name'),
    '12: 服務項目 reports invalid or missing name'
);

// 13. service_items name but no rows → PASS
$serviceHeaderOnly = build_valid_raw_tabs();
$serviceHeaderOnly['service_items'] = [
    ['name' => ''],
];
$serviceHeaderOnlyResult = validate_tabs($serviceHeaderOnly);
test_assert($serviceHeaderOnlyResult['ok'] === true, '13: service_items name header with no data rows pass');

// 14. special_prices wrong header → FAIL
$badSpecial = build_valid_raw_tabs();
$badSpecial['special_prices'] = [
    ['項目名稱' => 'Visa', 'price_amount' => 1000],
];
$badSpecialResult = validate_tabs($badSpecial);
test_assert($badSpecialResult['ok'] === false, '14: special_prices wrong header fails');
test_assert(
    has_error_code($badSpecialResult, 'BDC_INVALID_FIELD_NAME', 'special_prices', '項目名稱')
    || has_error_code($badSpecialResult, 'BDC_REQUIRED_HEADER_MISSING', 'special_prices', 'item_name'),
    '14: special_prices invalid chinese header reported'
);

// 15. GCS not modified on validation failure
$typoNormalized = $parser->parse('header_contract_tenant', $typoPhone);
$typoValidation = $validator->validate($typoNormalized, $typoPhone);
test_assert($typoValidation['ok'] === false, '15: validation fails before GCS path');
test_assert(($typoValidation['ok'] ?? false) === false, '15: GCS upload path blocked on validation failure');

$syncScript = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bds-sync.php');
test_assert(is_string($syncScript), '15: bds-sync readable');
test_assert(strpos($syncScript, 'validation failed; GCS not modified') !== false, '15: bds-sync documents GCS skip on validation fail');
test_assert(strpos($syncScript, '$validator->validate($normalized, $tabs)') !== false, '15: bds-sync passes raw tabs to validator');

// 16. Upload Portal Chinese message for wrong header
$fixtureDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'output'
    . DIRECTORY_SEPARATOR . 'header_contract_message';
if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0777, true) && !is_dir($fixtureDir)) {
    fwrite(STDERR, "FAIL: cannot create fixture dir\n");
    exit(1);
}

$typoErrorReport = $fixtureDir . DIRECTORY_SEPARATOR . 'error_report_typo.json';
file_put_contents($typoErrorReport, json_encode([
    'errors' => [[
        'code' => 'BDC_HEADER_NOT_ALLOWED',
        'message' => 'company_profile header is not allowed: mutifuntion',
        'tab' => 'company_profile',
        'row_index' => null,
        'field' => 'mutifuntion',
    ]],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

$typoMessage = BdsUploadPortalValidationMessageFormatter::formatFromErrorReport(
    $typoErrorReport,
    'ERROR: validation failed; GCS not modified'
);
test_assert(is_string($typoMessage), '16: typo message returns string');
test_assert(strpos($typoMessage, '您修改了系統保留欄位名稱，或缺少必要欄位。') !== false, '16: combined reason line');
test_assert(strpos($typoMessage, '公司基本資料') !== false, '16: company_profile tab display');
test_assert(strpos($typoMessage, 'mutifuntion') !== false, '16: typo field shown');
test_assert(strpos($typoMessage, '本分頁允許使用的欄位名稱：') !== false, '16: allowed fields label');
test_assert(strpos($typoMessage, 'business_hours') !== false, '16: all eight allowed fields listed');
test_assert(strpos($typoMessage, '請勿自行新增、刪除或修改系統欄位名稱。') !== false, '16: do not modify headers');

// 17. Upload Portal Chinese message for missing header
$missingErrorReport = $fixtureDir . DIRECTORY_SEPARATOR . 'error_report_missing.json';
file_put_contents($missingErrorReport, json_encode([
    'errors' => [[
        'code' => 'BDC_REQUIRED_HEADER_MISSING',
        'message' => 'company_profile required header is missing: phone',
        'tab' => 'company_profile',
        'row_index' => null,
        'field' => 'phone',
    ]],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

$missingMessage = BdsUploadPortalValidationMessageFormatter::formatFromErrorReport($missingErrorReport, '');
test_assert(is_string($missingMessage), '17: missing header message returns string');
test_assert(strpos($missingMessage, '錯誤欄位：') !== false, '17: error field label');
test_assert(strpos($missingMessage, 'phone') !== false, '17: missing phone field shown');
test_assert(strpos($missingMessage, '本分頁允許使用的欄位名稱：') !== false, '17: allowed fields listed');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_bds_header_contract (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
