<?php
declare(strict_types=1);

/**
 * BDS Phase 6A — Header contract runtime wiring tests (bds-sync CLI path).
 */

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

function remove_directory_recursive(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            remove_directory_recursive($path);
            continue;
        }
        unlink($path);
    }

    rmdir($directory);
}

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function column_letter(int $index): string
{
    $letters = '';
    while ($index > 0) {
        --$index;
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intdiv($index, 26);
    }

    return $letters;
}

/**
 * @param list<list<string>> $rows
 */
function worksheet_xml_from_rows(array $rows): string
{
    $sheetRows = [];
    $rowNumber = 1;
    foreach ($rows as $row) {
        $cells = [];
        $columnIndex = 0;
        foreach ($row as $value) {
            ++$columnIndex;
            $cellRef = column_letter($columnIndex) . $rowNumber;
            $cells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . xml_escape((string) $value) . '</t></is></c>';
        }
        $sheetRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
        ++$rowNumber;
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';
}

/**
 * @param array<string, list<list<string>>> $sheets
 */
function build_test_xlsx(string $targetPath, array $sheets): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive required for test xlsx builder');
    }

    $sheetEntries = [];
    $sheetIndex = 1;
    $workbookSheets = [];
    $workbookRels = [];
    $contentOverrides = [
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
    ];

    foreach ($sheets as $sheetName => $rows) {
        $sheetFile = 'sheet' . $sheetIndex . '.xml';
        $sheetPath = 'xl/worksheets/' . $sheetFile;
        $rId = 'rId' . $sheetIndex;

        $sheetEntries[$sheetPath] = worksheet_xml_from_rows($rows);
        $workbookSheets[] = '<sheet name="' . xml_escape($sheetName) . '" sheetId="' . $sheetIndex . '" r:id="' . $rId . '"/>';
        $workbookRels[] = '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/' . $sheetFile . '"/>';
        $contentOverrides[] = '<Override PartName="/xl/worksheets/' . $sheetFile . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        ++$sheetIndex;
    }

    $zip = new ZipArchive();
    if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create test xlsx');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . implode('', $contentOverrides)
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $workbookSheets) . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $workbookRels)
        . '</Relationships>');

    foreach ($sheetEntries as $path => $xml) {
        $zip->addFromString($path, $xml);
    }

    $zip->close();
}

/**
 * @return array<string, list<list<string>>>
 */
function build_valid_sheet_grids(): array
{
    return [
        'company_profile' => [
            ['company_name', 'summary', 'phone', 'address', 'line_official', 'email', 'website', 'business_hours'],
            ['Runtime Travel', 'Travel agency', '02-9999-8888', 'Taipei', '@travel_b', 'info@example.com', 'https://example.com', 'Mon-Fri 9-18'],
        ],
        'qa' => [
            ['question', 'answer'],
            ['Where are you?', 'Taipei office'],
        ],
        'external_product_links' => [
            ['name', 'url'],
            ['Partner', 'https://example.com/partner'],
        ],
        'service_items' => [
            ['name'],
            ['Visa Help'],
        ],
        'special_prices' => [
            ['item_name', 'price_amount'],
            ['Visa Help', '1100'],
        ],
    ];
}

function write_upload_session(string $stagingDir, string $sessionId, string $storedFilename): void
{
    $session = [
        'upload_session_id' => $sessionId,
        'tenant_key' => 'travel_b',
        'tenant_sno' => '5f99b8d665e8444d',
        'stored_filename' => $storedFilename,
        'status' => 'received_only',
        'sync_triggered' => false,
    ];

    file_put_contents(
        $stagingDir . DIRECTORY_SEPARATOR . 'upload_session.json',
        json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

/**
 * @return array{exit_code: int, output: string}
 */
function run_bds_sync_upload(string $sessionId): array
{
    $phpBinary = PHP_BINARY;
    $syncScript = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bds-sync.php';
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($syncScript)
        . ' --tenant=travel_b --upload-session-id=' . escapeshellarg($sessionId) . ' --dry-run 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

function setup_staging_workbook(string $uploadsRoot, string $tenantSno, string $sessionId, array $sheetGrids): string
{
    $stagingDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
        . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $sessionId;
    remove_directory_recursive($stagingDir);
    mkdir($stagingDir, 0777, true);

    $storedFilename = 'knowledge_' . $sessionId . '.xlsx';
    $xlsxPath = $stagingDir . DIRECTORY_SEPARATOR . $storedFilename;
    build_test_xlsx($xlsxPath, $sheetGrids);
    write_upload_session($stagingDir, $sessionId, $storedFilename);

    return $stagingDir;
}

function error_report_has_code(string $errorReportPath, string $code, ?string $field = null): bool
{
    if (!is_file($errorReportPath)) {
        return false;
    }

    $data = json_decode((string) file_get_contents($errorReportPath), true);
    if (!is_array($data) || !isset($data['errors']) || !is_array($data['errors'])) {
        return false;
    }

    foreach ($data['errors'] as $error) {
        if (!is_array($error) || ($error['code'] ?? '') !== $code) {
            continue;
        }
        if ($field !== null && ($error['field'] ?? '') !== $field) {
            continue;
        }
        return true;
    }

    return false;
}

function knowledge_json_count(string $knowledgeDir): int
{
    if (!is_dir($knowledgeDir)) {
        return 0;
    }

    $count = 0;
    foreach (scandir($knowledgeDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $knowledgeDir . DIRECTORY_SEPARATOR . $item;
        if (is_file($path) && str_ends_with($item, '.json')) {
            ++$count;
        }
    }

    return $count;
}

$projectRoot = dirname(__DIR__, 2);
$uploadsRoot = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'uploads';
$bdsOutputRoot = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds';
$tenantSno = '5f99b8d665e8444d';
$reportsDir = $bdsOutputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno . DIRECTORY_SEPARATOR . 'reports';
$knowledgeDir = $bdsOutputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno . DIRECTORY_SEPARATOR . 'knowledge';
$errorReportPath = $reportsDir . DIRECTORY_SEPARATOR . 'error_report.json';

if (!is_dir($uploadsRoot)) {
    mkdir($uploadsRoot, 0777, true);
}

// 1. bds-sync.php uses header tabs metadata in validation
$syncSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bds-sync.php');
test_assert(strpos($syncSource, '$validator->validate($normalized, $tabs)') !== false, '1: bds-sync passes tabs to validator');

// 2. wrong header hello causes sync fail
$sessionHello = 'UPLOAD-20260620-120001-a1b2c3';
$helloGrids = build_valid_sheet_grids();
$helloGrids['company_profile'][0] = ['company_name', 'summary', 'hello', 'address', 'line_official', 'email', 'website', 'business_hours'];
$helloGrids['company_profile'][1] = ['Runtime Travel', 'Travel agency', '02-9999-8888', 'Taipei', '@travel_b', 'info@example.com', 'https://example.com', 'Mon-Fri 9-18'];
setup_staging_workbook($uploadsRoot, $tenantSno, $sessionHello, $helloGrids);
$helloRun = run_bds_sync_upload($sessionHello);
test_assert($helloRun['exit_code'] !== 0, '2: hello header sync exits non-zero');
test_assert(strpos($helloRun['output'], 'Validation: FAIL') !== false, '2: hello header reports validation fail');
test_assert(strpos($helloRun['output'], 'GCS not modified') !== false, '2: hello header reports GCS not modified');
test_assert(error_report_has_code($errorReportPath, 'BDC_HEADER_NOT_ALLOWED', 'hello'), '2: error_report contains hello header error');
test_assert(knowledge_json_count($knowledgeDir) === 0, '6: validation fail leaves no knowledge json files');
test_assert(is_file($errorReportPath), '6: validation fail writes error_report.json');
test_assert(strpos($helloRun['output'], 'GCS Upload: SKIP') !== false, '6: validation fail skips GCS upload');

// 3. missing header phone causes sync fail
$sessionMissingPhone = 'UPLOAD-20260620-120002-b2c3d4';
$missingPhoneGrids = build_valid_sheet_grids();
$missingPhoneGrids['company_profile'][0] = ['company_name', 'summary', 'address', 'line_official', 'email', 'website', 'business_hours'];
$missingPhoneGrids['company_profile'][1] = ['Runtime Travel', 'Travel agency', 'Taipei', '@travel_b', 'info@example.com', 'https://example.com', 'Mon-Fri 9-18'];
setup_staging_workbook($uploadsRoot, $tenantSno, $sessionMissingPhone, $missingPhoneGrids);
$missingPhoneRun = run_bds_sync_upload($sessionMissingPhone);
test_assert($missingPhoneRun['exit_code'] !== 0, '3: missing phone sync exits non-zero');
test_assert(error_report_has_code($errorReportPath, 'BDC_REQUIRED_HEADER_MISSING', 'phone'), '3: error_report contains missing phone');

// 4. service_items service_id + name causes sync fail
$sessionServiceId = 'UPLOAD-20260620-120003-c3d4e5';
$serviceIdGrids = build_valid_sheet_grids();
$serviceIdGrids['service_items'] = [
    ['service_id', 'name'],
    ['svc_visa', 'Visa Help'],
];
setup_staging_workbook($uploadsRoot, $tenantSno, $sessionServiceId, $serviceIdGrids);
$serviceIdRun = run_bds_sync_upload($sessionServiceId);
test_assert($serviceIdRun['exit_code'] !== 0, '4: service_id header sync exits non-zero');
test_assert(error_report_has_code($errorReportPath, 'BDC_HEADER_NOT_ALLOWED', 'service_id'), '4: error_report contains service_id header error');

// 7. upload portal displays Chinese validation message from error_report
$portalMessage = BdsUploadPortalValidationMessageFormatter::formatFromErrorReport(
    $errorReportPath,
    'ERROR: validation failed; GCS not modified'
);
test_assert(is_string($portalMessage), '7: portal formatter returns string');
test_assert(strpos($portalMessage, '格式檢查未通過') !== false, '7: portal message in Chinese');
test_assert(strpos($portalMessage, '本分頁允許使用的欄位名稱：') !== false, '7: portal message lists allowed headers');

// 5. valid corrected workbook causes sync pass
$sessionValid = 'UPLOAD-20260620-120004-d4e5f6';
setup_staging_workbook($uploadsRoot, $tenantSno, $sessionValid, build_valid_sheet_grids());
$validRun = run_bds_sync_upload($sessionValid);
test_assert($validRun['exit_code'] === 0, '5: valid workbook sync exits 0');
test_assert(strpos($validRun['output'], 'Validation: PASS') !== false, '5: valid workbook reports validation pass');
test_assert(strpos($validRun['output'], 'Knowledge Build: PASS') !== false, '5: valid workbook builds knowledge');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_bds_sync_header_contract_runtime (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
