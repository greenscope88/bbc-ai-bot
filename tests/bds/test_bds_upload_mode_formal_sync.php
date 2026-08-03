<?php
declare(strict_types=1);

/**
 * BDS Phase 7-1c-3a — Upload mode formal GCS sync tests.
 *
 * Matrix:
 * A — valid upload mode formal sync (live GCS when gate open)
 * B — invalid Excel → validation fail, no GCS write
 * C — read-back failure simulation → no promote
 * D — sheet mode regression
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsUploadStagingResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsWorksheetNameMapper.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsXlsxReader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsKnowledgeDocumentBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function env_string(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
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
            ['Upload Mode Travel', 'Formal sync demo'],
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
        'upload_session_id' => 'UPLOAD-20260614-130000-a1b2c3',
        'tenant_key' => 'travel_b',
        'tenant_sno' => '5f99b8d665e8444d',
        'stored_filename' => 'knowledge_UPLOAD-20260614-130000-a1b2c3.xlsx',
        'status' => 'received_only',
        'sync_triggered' => false,
    ], $overrides);

    file_put_contents(
        $stagingDir . DIRECTORY_SEPARATOR . 'upload_session.json',
        json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

function run_cli(array $args): array
{
    $phpBinary = PHP_BINARY;
    $syncScript = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bds-sync.php';
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($syncScript) . ' ' . implode(' ', $args) . ' 2>&1';
    exec($command, $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

/**
 * @param array<string, string|false|null> $savedEnv
 */
function restore_env(array $savedEnv): void
{
    foreach ($savedEnv as $key => $value) {
        if ($value === false || $value === null) {
            putenv($key);
            continue;
        }
        putenv($key . '=' . (string) $value);
    }
}

function prepare_project_staging(string $sessionId, string $xlsxPath, string $stagingDir): void
{
    $projectUploadsRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'uploads';
    $projectStagingDir = $projectUploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . '5f99b8d665e8444d'
        . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $sessionId;
    remove_directory_recursive($projectStagingDir);
    mkdir($projectStagingDir, 0777, true);
    copy($xlsxPath, $projectStagingDir . DIRECTORY_SEPARATOR . basename($xlsxPath));
    write_upload_session($projectStagingDir, [
        'upload_session_id' => $sessionId,
        'stored_filename' => basename($xlsxPath),
    ]);
}

$tenantSno = '5f99b8d665e8444d';
$sessionId = 'UPLOAD-20260614-130000-a1b2c3';
$testRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'upload_mode_7_1c_3a';
$stagingDir = $testRoot . DIRECTORY_SEPARATOR . 'staging';
remove_directory_recursive($testRoot);
mkdir($stagingDir, 0777, true);

$xlsxPath = $stagingDir . DIRECTORY_SEPARATOR . 'knowledge_' . $sessionId . '.xlsx';
build_test_xlsx($xlsxPath, build_valid_sheet_grids());
write_upload_session($stagingDir);

// Unit — sync metadata enrichment
$parser = new BdsMockSheetParser();
$validator = new BdsValidator();
$reader = new BdsXlsxReader();
$tabs = $reader->readFile($xlsxPath);
$normalized = $parser->parse($tenantSno, $tabs);
$validation = $validator->validate($normalized);
$knowledgeJson = BdsKnowledgeDocumentBuilder::fromNormalized($tenantSno, $normalized, null);
$syncId = 'SYNC-20260614-153025';
$publishedAt = gmdate('Y-m-d\TH:i:s\Z');
$uploadSource = [
    'source_type' => 'upload_portal',
    'upload_session_id' => $sessionId,
    'stored_filename' => basename($xlsxPath),
];
$enriched = BdsKnowledgeDocumentBuilder::applyFormalSyncMetadata($knowledgeJson, $syncId, $publishedAt, $uploadSource);

test_assert(count($enriched) === 5, 'metadata: five documents enriched');
foreach ($enriched as $filename => $document) {
    test_assert(($document['sync_id'] ?? '') === $syncId, 'metadata: sync_id in ' . $filename);
    test_assert(($document['published_at'] ?? '') === $publishedAt, 'metadata: published_at in ' . $filename);
    test_assert(($document['source_type'] ?? '') === 'upload_portal', 'metadata: source_type in ' . $filename);
    test_assert(($document['upload_session_id'] ?? '') === $sessionId, 'metadata: upload_session_id in ' . $filename);
}
test_assert(preg_match('/^SYNC-\d{8}-\d{6}$/', $syncId) === 1, 'metadata: sync_id format');

$outputRoot = $testRoot . DIRECTORY_SEPARATOR . 'bds';
mkdir($outputRoot, 0777, true);
$writer = new BdsJsonWriter($parser, $validator, $outputRoot);
$syncReportPath = $writer->writeFormalSyncReport(
    $tenantSno,
    $syncId,
    $publishedAt,
    gmdate('Y-m-d\TH:i:s\Z'),
    gmdate('Y-m-d\TH:i:s\Z'),
    $uploadSource,
    [
        'uploaded_objects' => [
            ['object_path' => 'tenants/' . $tenantSno . '/knowledge/company_profile.json'],
        ],
    ]
);
$syncReport = json_decode((string) file_get_contents($syncReportPath), true);
test_assert(is_array($syncReport), 'formal sync_report written');
test_assert(($syncReport['sync_id'] ?? '') === $syncId, 'formal sync_report sync_id');
test_assert(($syncReport['status'] ?? '') === 'success', 'formal sync_report status success');
test_assert(($syncReport['dry_run'] ?? true) === false, 'formal sync_report dry_run false');
test_assert(($syncReport['source_type'] ?? '') === 'upload_portal', 'formal sync_report source_type');
test_assert(($syncReport['upload_session_id'] ?? '') === $sessionId, 'formal sync_report upload_session_id');

// Matrix E — GCS Write Gate single-authority identity validation (pure function, no external I/O)
$travelBEntry = BdsSourceRegistryLoader::loadByTenantKey('travel_b');
$travelDEntry = BdsSourceRegistryLoader::loadByTenantKey('travel_d');
test_assert($travelBEntry !== null, 'E: travel_b resolves from BDS Authority');
test_assert($travelDEntry !== null, 'E: travel_d resolves from BDS Authority');

if ($travelBEntry !== null && $travelDEntry !== null) {
    $travelBContext = [
        'tenant_key' => (string) $travelBEntry['tenant_key'],
        'sno' => (string) $travelBEntry['sno'],
        'gcs_prefix' => (string) $travelBEntry['gcs_prefix'],
    ];
    $travelDContext = [
        'tenant_key' => (string) $travelDEntry['tenant_key'],
        'sno' => (string) $travelDEntry['sno'],
        'gcs_prefix' => (string) $travelDEntry['gcs_prefix'],
    ];
    test_assert($travelBContext['sno'] !== $travelDContext['sno'], 'E: travel_b and travel_d have distinct sno');
    test_assert($travelBContext['gcs_prefix'] !== $travelDContext['gcs_prefix'], 'E: travel_b and travel_d have distinct namespace');

    $savedGateEnv = [
        'BDS_DRY_RUN' => getenv('BDS_DRY_RUN'),
        'BDS_GCS_WRITE_ENABLED' => getenv('BDS_GCS_WRITE_ENABLED'),
        'BDS_TARGET_SNO' => getenv('BDS_TARGET_SNO'),
    ];
    putenv('BDS_DRY_RUN=false');
    putenv('BDS_GCS_WRITE_ENABLED=true');

    // E1 — travel_b passes the Gate with its own resolved Context.
    putenv('BDS_TARGET_SNO=' . $travelBContext['sno']);
    $gateB = BdsGcsUploader::evaluateResolvedIdentityGate($travelBContext);
    test_assert($gateB['open'] === true, 'E1: travel_b resolved Context opens the Gate');
    test_assert($gateB['reason'] === 'gate_open', 'E1: travel_b reason is gate_open');

    // E2 — travel_d passes the same Gate with its own resolved Context (no allowlist, no branch).
    putenv('BDS_TARGET_SNO=' . $travelDContext['sno']);
    $gateD = BdsGcsUploader::evaluateResolvedIdentityGate($travelDContext);
    test_assert($gateD['open'] === true, 'E2: travel_d resolved Context opens the Gate');
    test_assert($gateD['reason'] === 'gate_open', 'E2: travel_d reason is gate_open');

    // E3 — target sno mismatch (operational target sno points at a different tenant) fails closed.
    putenv('BDS_TARGET_SNO=' . $travelDContext['sno']);
    $gateMismatch = BdsGcsUploader::evaluateResolvedIdentityGate($travelBContext);
    test_assert($gateMismatch['open'] === false, 'E3: target sno mismatch fails closed');
    test_assert($gateMismatch['reason'] === 'target_sno_mismatch', 'E3: target sno mismatch reason');

    // E4 — tampered namespace (sno matches, gcs_prefix borrowed from the other tenant) fails closed.
    putenv('BDS_TARGET_SNO=' . $travelDContext['sno']);
    $tamperedContext = $travelDContext;
    $tamperedContext['gcs_prefix'] = $travelBContext['gcs_prefix'];
    $gateNamespaceTamper = BdsGcsUploader::evaluateResolvedIdentityGate($tamperedContext);
    test_assert($gateNamespaceTamper['open'] === false, 'E4: tampered namespace fails closed');
    test_assert($gateNamespaceTamper['reason'] === 'namespace_mismatch', 'E4: tampered namespace reason');

    // E5 — incomplete resolved Context (missing tenant_key) fails closed before any GCS call.
    putenv('BDS_TARGET_SNO=' . $travelBContext['sno']);
    $incompleteContext = ['tenant_key' => '', 'sno' => $travelBContext['sno'], 'gcs_prefix' => $travelBContext['gcs_prefix']];
    $gateIncomplete = BdsGcsUploader::evaluateResolvedIdentityGate($incompleteContext);
    test_assert($gateIncomplete['open'] === false, 'E5: incomplete resolved Context fails closed');
    test_assert($gateIncomplete['reason'] === 'resolved_context_incomplete', 'E5: incomplete resolved Context reason');

    // E6 — Gate rejection propagates to uploadKnowledge(): no GCS call, uploaded_objects=[].
    putenv('BDS_TARGET_SNO=' . $travelDContext['sno']);
    $blockedUploader = new BdsGcsUploader(null, null, $testRoot . DIRECTORY_SEPARATOR . 'gate_reject_probe');
    $blockedResult = $blockedUploader->uploadKnowledge($travelBContext['sno'], [], ['ok' => true], $travelBContext);
    test_assert(($blockedResult['ok'] ?? true) === false, 'E6: mismatched Context upload result not ok');
    test_assert(($blockedResult['uploaded_objects'] ?? null) === [], 'E6: mismatched Context uploads no objects');
    test_assert(($blockedResult['blocked_reason'] ?? '') === 'target_sno_mismatch', 'E6: mismatched Context blocked_reason');

    restore_env($savedGateEnv);
}

test_assert(
    stripos((string) file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php'), 'PILOT_TENANT_SNO') === false,
    'E: BdsGcsUploader source contains no PILOT_TENANT_SNO'
);
test_assert(
    stripos((string) file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php'), 'target_sno_not_pilot') === false,
    'E: BdsGcsUploader source contains no target_sno_not_pilot'
);

prepare_project_staging($sessionId, $xlsxPath, $stagingDir);

putenv('BDS_DRY_RUN=false');
putenv('BDS_GCS_WRITE_ENABLED=true');
putenv('BDS_TARGET_SNO=' . $tenantSno);
$resolvedContext = [
    'tenant_key' => 'travel_b',
    'sno' => $tenantSno,
    'gcs_prefix' => 'tenants/' . $tenantSno . '/',
];
$gate = BdsGcsUploader::evaluateResolvedIdentityGate($resolvedContext);
fwrite(STDOUT, 'GATE_OPEN|' . ($gate['open'] ? 'yes' : 'no') . '|' . $gate['reason'] . PHP_EOL);

$reportsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds'
    . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno . DIRECTORY_SEPARATOR . 'reports';
$beforeSyncReport = is_file($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json')
    ? (string) file_get_contents($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json')
    : '';
$beforeSyncId = '';
if ($beforeSyncReport !== '') {
    $beforeDecoded = json_decode($beforeSyncReport, true);
    if (is_array($beforeDecoded) && isset($beforeDecoded['sync_id'])) {
        $beforeSyncId = (string) $beforeDecoded['sync_id'];
    }
}

// C — read-back failure simulation (no promote) — run before other CLI cases
if ($gate['open'] === true) {
    $savedSimEnv = [
        'BDS_SYNC_SIMULATE_READBACK_FAIL' => getenv('BDS_SYNC_SIMULATE_READBACK_FAIL'),
    ];
    putenv('BDS_SYNC_SIMULATE_READBACK_FAIL=1');
    $readbackFailCli = run_cli([
        '--tenant=travel_b',
        '--upload-session-id=' . $sessionId,
        '--dry-run=false',
        '--write-gcs',
    ]);
    restore_env($savedSimEnv);
    test_assert($readbackFailCli['exit_code'] !== 0, 'C: read-back fail exits non-zero');
    test_assert(strpos($readbackFailCli['output'], 'Read-back Verification: FAIL') !== false, 'C: read-back fail reported');
    test_assert(strpos($readbackFailCli['output'], 'Sync ID:') === false, 'C: no sync_id printed on read-back fail');

    $afterSyncReport = is_file($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json')
        ? (string) file_get_contents($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json')
        : '';
    if ($beforeSyncReport !== '') {
        test_assert($afterSyncReport === $beforeSyncReport, 'C: sync_report unchanged on read-back fail');
    }
    if ($beforeSyncId !== '') {
        $afterDecoded = json_decode($afterSyncReport, true);
        test_assert(is_array($afterDecoded) && ($afterDecoded['sync_id'] ?? '') === $beforeSyncId, 'C: sync_id unchanged on read-back fail');
    } else {
        $decodedAfter = json_decode($afterSyncReport, true);
        test_assert(!is_array($decodedAfter) || ($decodedAfter['status'] ?? '') !== 'success', 'C: no success promote without prior report');
    }
} else {
    fwrite(STDERR, "SKIP C live read-back simulation: gate not open ({$gate['reason']})\n");
}

// B — invalid Excel (validation fail) with formal sync flags
$invalidSessionId = 'UPLOAD-20260614-130001-b1b2c3';
$invalidStaging = $testRoot . DIRECTORY_SEPARATOR . 'invalid_staging';
mkdir($invalidStaging, 0777, true);
$invalidSheets = build_valid_sheet_grids();
$invalidSheets['company_profile'] = [
    ['company_name', 'summary'],
    ['', 'Missing required company_name'],
];
$invalidXlsx = $invalidStaging . DIRECTORY_SEPARATOR . 'knowledge_' . $invalidSessionId . '.xlsx';
build_test_xlsx($invalidXlsx, $invalidSheets);
prepare_project_staging($invalidSessionId, $invalidXlsx, $invalidStaging);

$invalidCli = run_cli([
    '--tenant=travel_b',
    '--upload-session-id=' . $invalidSessionId,
    '--dry-run=false',
    '--write-gcs',
]);
test_assert($invalidCli['exit_code'] !== 0, 'B: invalid excel exits non-zero');
test_assert(strpos($invalidCli['output'], 'Validation: FAIL') !== false, 'B: validation fail reported');
test_assert(strpos($invalidCli['output'], 'GCS Upload: SKIP') !== false, 'B: no GCS upload attempted');

// D — sheet mode dry-run regression
$sheetCli = run_cli(['--tenant=travel_b', '--dry-run']);
test_assert($sheetCli['exit_code'] === 0, 'D: sheet mode dry-run exits 0');
test_assert(strpos($sheetCli['output'], 'Input Mode: sheet') !== false, 'D: sheet mode reported');
test_assert(strpos($sheetCli['output'], 'GCS Upload: SKIP (dry-run)') !== false, 'D: sheet dry-run skips GCS');

// A — valid upload mode formal sync (live GCS when gate open)
if ($gate['open'] === true) {
    prepare_project_staging($sessionId, $xlsxPath, $stagingDir);
    $formalCli = run_cli([
        '--tenant=travel_b',
        '--upload-session-id=' . $sessionId,
        '--dry-run=false',
        '--write-gcs',
    ]);
    test_assert($formalCli['exit_code'] === 0, 'A: formal upload sync exits 0');
    test_assert(strpos($formalCli['output'], 'Validation: PASS') !== false, 'A: validation pass');
    test_assert(strpos($formalCli['output'], 'GCS Upload: PASS') !== false, 'A: GCS upload pass');
    test_assert(strpos($formalCli['output'], 'Read-back Verification: PASS') !== false, 'A: read-back pass');
    test_assert(preg_match('/Sync ID: (SYNC-\d{8}-\d{6})/', $formalCli['output'], $matches) === 1, 'A: sync_id printed');

    if (preg_match('/Sync ID: (SYNC-\d{8}-\d{6})/', $formalCli['output'], $matches) === 1) {
        $liveSyncId = $matches[1];
        fwrite(STDOUT, 'LIVE_SYNC_ID|' . $liveSyncId . PHP_EOL);

        $liveSyncReport = json_decode((string) file_get_contents($reportsDir . DIRECTORY_SEPARATOR . 'sync_report.json'), true);
        test_assert(is_array($liveSyncReport), 'A: live sync_report exists');
        test_assert(($liveSyncReport['sync_id'] ?? '') === $liveSyncId, 'A: live sync_report sync_id matches');
        test_assert(($liveSyncReport['status'] ?? '') === 'success', 'A: live sync_report status success');
        test_assert(($liveSyncReport['dry_run'] ?? true) === false, 'A: live sync_report dry_run false');
        test_assert(($liveSyncReport['source_type'] ?? '') === 'upload_portal', 'A: live sync_report source_type');

        $credentialsPath = env_string('BDS_GOOGLE_APPLICATION_CREDENTIALS');
        $credentialsPath = $credentialsPath !== '' ? $credentialsPath : null;
        $uploader = new BdsGcsUploader(null, $credentialsPath, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds');
        foreach (BdsJsonWriter::TAB_OUTPUT_FILES as $filename) {
            $objectPath = 'tenants/' . $tenantSno . '/knowledge/' . $filename;
            $getResp = $uploader->getObject($objectPath);
            $decoded = json_decode($getResp['body'], true);
            test_assert($getResp['status'] === 200 && is_array($decoded), 'A: read-back object: ' . $objectPath);
            test_assert(($decoded['sync_id'] ?? '') === $liveSyncId, 'A: GCS object sync_id: ' . $objectPath);
            test_assert(($decoded['source_type'] ?? '') === 'upload_portal', 'A: GCS object source_type: ' . $objectPath);
            fwrite(STDOUT, 'GCS_PATH|' . $objectPath . PHP_EOL);
        }
    }
} else {
    fwrite(STDERR, "SKIP A live GCS: gate not open ({$gate['reason']})\n");
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "All upload mode formal sync tests passed.\n");
exit(0);
