<?php
declare(strict_types=1);

/**
 * BDS Phase 7-1c-2a — Upload mode tests (Resolver, XlsxReader, CLI dry-run path).
 *
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md §8.8.6
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsUploadStagingResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsWorksheetNameMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsXlsxReader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsKnowledgeDocumentBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGoogleSheetReader.php';

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
            $columnIndex++;
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

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * @return array<string, list<list<string>>>
 */
function build_valid_sheet_grids(): array
{
    return [
        'company_profile' => [
            ['company_name', 'summary'],
            ['Upload Mode Travel', 'Dry-run demo'],
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
            ['name', 'price_amount'],
            ['Visa Help', '1200'],
        ],
        'special_prices' => [
            ['item_name', 'price_amount'],
            ['Visa Help', '1100'],
        ],
    ];
}

function write_upload_session(string $stagingDir, array $overrides = []): void
{
    $session = array_merge([
        'upload_session_id' => 'UPLOAD-20260614-120000-a1b2c3',
        'tenant_key' => 'travel_b',
        'tenant_sno' => '5f99b8d665e8444d',
        'stored_filename' => 'knowledge_UPLOAD-20260614-120000-a1b2c3.xlsx',
        'status' => 'received_only',
        'sync_triggered' => false,
    ], $overrides);

    file_put_contents(
        $stagingDir . DIRECTORY_SEPARATOR . 'upload_session.json',
        json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

$testRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'upload_mode_7_1c_2a';
$uploadsRoot = $testRoot . DIRECTORY_SEPARATOR . 'uploads';
$outputRoot = $testRoot . DIRECTORY_SEPARATOR . 'bds';
remove_directory_recursive($testRoot);
mkdir($uploadsRoot, 0777, true);
mkdir($outputRoot, 0777, true);

$tenantKey = 'travel_b';
$tenantSno = '5f99b8d665e8444d';
$sessionId = 'UPLOAD-20260614-120000-a1b2c3';
$stagingDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
    . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $sessionId;
mkdir($stagingDir, 0777, true);

$xlsxPath = $stagingDir . DIRECTORY_SEPARATOR . 'knowledge_' . $sessionId . '.xlsx';
build_test_xlsx($xlsxPath, build_valid_sheet_grids());
write_upload_session($stagingDir);

$resolver = new BdsUploadStagingResolver($uploadsRoot);

// A — valid upload_session_id
$resolveA = $resolver->resolve($tenantKey, $tenantSno, $sessionId);
test_assert(($resolveA['ok'] ?? false) === true, 'A: valid upload_session_id resolves');
test_assert(is_file((string) ($resolveA['xlsx_path'] ?? '')), 'A: xlsx path exists');

// B — upload_session.json missing
$missingSessionDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
    . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . 'UPLOAD-20260614-120001-b1b2c3';
mkdir($missingSessionDir, 0777, true);
$resolveB = $resolver->resolve($tenantKey, $tenantSno, 'UPLOAD-20260614-120001-b1b2c3');
test_assert(($resolveB['ok'] ?? false) === false, 'B: missing upload_session.json fails');

// C — stored_filename missing
$sessionC = 'UPLOAD-20260614-120002-c1b2c3';
$stagingDirC = $uploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
    . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $sessionC;
mkdir($stagingDirC, 0777, true);
write_upload_session($stagingDirC, [
    'upload_session_id' => $sessionC,
    'stored_filename' => 'missing_file.xlsx',
]);
$resolveC = $resolver->resolve($tenantKey, $tenantSno, $sessionC);
test_assert(($resolveC['ok'] ?? false) === false, 'C: missing stored_filename fails');

// D — tenant_key mismatch
$resolveD = $resolver->resolve('invalid_tenant', $tenantSno, $sessionId);
test_assert(($resolveD['ok'] ?? false) === false, 'D: tenant_key mismatch fails');

// E — Excel missing required tabs
$sessionE = 'UPLOAD-20260614-120003-d1b2c3';
$stagingDirE = $uploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
    . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $sessionE;
mkdir($stagingDirE, 0777, true);
$partialSheets = build_valid_sheet_grids();
unset($partialSheets['qa']);
build_test_xlsx($stagingDirE . DIRECTORY_SEPARATOR . 'knowledge_' . $sessionE . '.xlsx', $partialSheets);
write_upload_session($stagingDirE, ['upload_session_id' => $sessionE]);
$reader = new BdsXlsxReader();
$failedE = false;
try {
    $reader->readFile($stagingDirE . DIRECTORY_SEPARATOR . 'knowledge_' . $sessionE . '.xlsx');
} catch (Throwable $e) {
    $failedE = true;
}
test_assert($failedE, 'E: missing required worksheet tab fails');

// F — valid Excel through Validator / Builder / dry-run metadata
$tabs = $reader->readFile($xlsxPath);
$parser = new BdsMockSheetParser();
$validator = new BdsValidator();
$normalized = $parser->parse($tenantSno, $tabs);
$validation = $validator->validate($normalized);
test_assert(($validation['ok'] ?? false) === true, 'F: valid excel passes validation');

$knowledgeJson = BdsKnowledgeDocumentBuilder::fromNormalized($tenantSno, $normalized, null);
test_assert(count($knowledgeJson) === 5, 'F: knowledge builder produces five documents');

$writer = new BdsJsonWriter($parser, $validator, $outputRoot);
$uploadSource = [
    'source_type' => 'upload_portal',
    'upload_session_id' => $sessionId,
    'stored_filename' => 'knowledge_' . $sessionId . '.xlsx',
];
$dryRun = $writer->writeDryRun($tenantSno, $normalized, $validation, null, $uploadSource);
test_assert(($dryRun['ok'] ?? false) === true, 'F: dry-run writer succeeds');

$syncReportPath = $outputRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
    . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'sync_report.json';
$syncReport = json_decode((string) file_get_contents($syncReportPath), true);
test_assert(is_array($syncReport), 'F: sync_report.json written');
test_assert(($syncReport['source_type'] ?? '') === 'upload_portal', 'F: sync_report source_type');
test_assert(($syncReport['upload_session_id'] ?? '') === $sessionId, 'F: sync_report upload_session_id');
test_assert(($syncReport['stored_filename'] ?? '') === 'knowledge_' . $sessionId . '.xlsx', 'F: sync_report stored_filename');

// CLI integration — staging must exist under project var/bds/uploads
$projectUploadsRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'uploads';
$projectStagingDir = $projectUploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
    . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $sessionId;
remove_directory_recursive($projectStagingDir);
mkdir($projectStagingDir, 0777, true);
copy($xlsxPath, $projectStagingDir . DIRECTORY_SEPARATOR . basename($xlsxPath));
write_upload_session($projectStagingDir);

$phpBinary = PHP_BINARY;
$syncScript = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bds-sync.php';
$command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($syncScript)
    . ' --tenant=travel_b --upload-session-id=' . escapeshellarg($sessionId) . ' --dry-run 2>&1';
exec($command, $cliOutput, $cliExitCode);
$cliText = implode("\n", $cliOutput);
test_assert($cliExitCode === 0, 'CLI upload mode dry-run exits 0');
test_assert(strpos($cliText, 'Input Mode: upload') !== false, 'CLI reports upload mode');
test_assert(strpos($cliText, 'Upload Staging: PASS') !== false, 'CLI staging pass');
test_assert(strpos($cliText, 'Upload Excel: PASS') !== false, 'CLI excel pass');
test_assert(strpos($cliText, 'Validation: PASS') !== false, 'CLI validation pass');
test_assert(strpos($cliText, 'Knowledge Build: PASS') !== false, 'CLI knowledge build pass');
test_assert(strpos($cliText, 'GCS Upload: SKIP (dry-run)') !== false, 'CLI skips GCS in dry-run');

// G — Google Sheet mode regression (existing reader test)
$googleReaderTest = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'test_bds_google_sheet_reader.php';
exec(escapeshellarg($phpBinary) . ' ' . escapeshellarg($googleReaderTest) . ' 2>&1', $sheetOutput, $sheetExitCode);
test_assert($sheetExitCode === 0, 'G: Google Sheet reader tests still pass');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "All upload mode tests passed.\n");
exit(0);
