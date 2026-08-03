#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * BDS Phase 6A — Manual Sync Command.
 *
 * Tenant Registry → Google Sheet Reader **or** Upload Staging (Xlsx) → Parser → Validator
 * → Knowledge Builder → JSON Writer → GCS Upload → Read-back Verification → Console Result
 *
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md §Phase 6A
 * @see docs/BATS_DATA_SYNC_RUNBOOK.md §13
 */

$projectRoot = dirname(__DIR__);

require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGoogleSheetReader.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsMockSheetParser.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsValidator.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsKnowledgeDocumentBuilder.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsJsonWriter.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsUploadStagingResolver.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsWorksheetNameMapper.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsXlsxReader.php';

require_once $projectRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';

/**
 * @return array{
 *   tenant_key: string,
 *   sno: string,
 *   upload_session_id: string,
 *   dry_run: bool,
 *   write_gcs: bool
 * }
 */
function parse_cli_args(array $argv): array
{
    $tenantKey = '';
    $sno = '';
    $uploadSessionId = '';
    $dryRun = true;
    $writeGcs = false;

    for ($i = 1, $count = count($argv); $i < $count; ++$i) {
        $arg = $argv[$i];
        if ($arg === '--write-gcs') {
            $writeGcs = true;
            continue;
        }
        if (strpos($arg, '--tenant=') === 0) {
            $tenantKey = substr($arg, 9);
            continue;
        }
        if (strpos($arg, '--sno=') === 0) {
            $sno = substr($arg, 6);
            continue;
        }
        if (strpos($arg, '--upload-session-id=') === 0) {
            $uploadSessionId = substr($arg, 20);
            continue;
        }
        if (strpos($arg, '--dry-run=') === 0) {
            $value = strtolower(trim(substr($arg, 10)));
            $dryRun = !in_array($value, ['false', '0', 'no', 'off'], true);
            continue;
        }
        if ($arg === '--dry-run') {
            $dryRun = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            print_usage();
            exit(0);
        }
        if (strpos($arg, '--') === 0) {
            continue;
        }
        if ($tenantKey === '') {
            $tenantKey = $arg;
        }
    }

    return [
        'tenant_key' => trim($tenantKey),
        'sno' => trim($sno),
        'upload_session_id' => trim($uploadSessionId),
        'dry_run' => $dryRun,
        'write_gcs' => $writeGcs,
    ];
}

function print_usage(): void
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php bin/bds-sync.php <tenant_key>\n");
    fwrite(STDERR, "  php bin/bds-sync.php --tenant=<tenant_key>\n");
    fwrite(STDERR, "  php bin/bds-sync.php --sno=<tenant_sno>\n");
    fwrite(STDERR, "  php bin/bds-sync.php --tenant=travel_b --upload-session-id=UPLOAD-YYYYMMDD-HHMMSS-abcdef --dry-run\n");
    fwrite(STDERR, "  php bin/bds-sync.php --tenant=travel_b --upload-session-id=UPLOAD-YYYYMMDD-HHMMSS-abcdef --dry-run=false --write-gcs\n");
    fwrite(STDERR, "  php bin/bds-sync.php --tenant=travel_b --dry-run=false --write-gcs\n");
}

function generate_sync_id(): string
{
    return 'SYNC-' . date('Ymd-His');
}

/**
 * @return array<string, string>
 */
function build_upload_source_metadata(string $uploadSessionId, string $storedFilename): array
{
    return [
        'source_type' => 'upload_portal',
        'upload_session_id' => $uploadSessionId,
        'stored_filename' => $storedFilename,
    ];
}

function env_string(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

function print_step(string $label, string $status): void
{
    fwrite(STDOUT, $label . ': ' . $status . PHP_EOL);
}

/**
 * @param array<string, mixed> $entry
 */
function write_registry_error_report(string $outputRoot, array $entry, string $code, string $message): void
{
    $tenantSno = isset($entry['sno']) ? (string) $entry['sno'] : 'unknown';
    $reportsDir = $outputRoot
        . DIRECTORY_SEPARATOR . 'tenants'
        . DIRECTORY_SEPARATOR . $tenantSno
        . DIRECTORY_SEPARATOR . 'reports';

    if (!is_dir($reportsDir) && !mkdir($reportsDir, 0777, true) && !is_dir($reportsDir)) {
        return;
    }

    $payload = [
        'schema_version' => 'bds_error_report.v1',
        'code' => $code,
        'message' => $message,
        'tenant_sno' => $tenantSno,
        'reported_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded !== false) {
        file_put_contents($reportsDir . DIRECTORY_SEPARATOR . 'error_report.json', $encoded . PHP_EOL);
    }
}

/**
 * @return array{ok: bool, failures: list<string>}
 */
function verify_readback(BdsGcsUploader $uploader, string $tenantSno, ?string $expectedSyncId = null): array
{
    $expectedFiles = array_values(BdsJsonWriter::TAB_OUTPUT_FILES);
    $expectedPrefix = 'tenants/' . $tenantSno . '/knowledge/';
    $expectedPaths = [];
    foreach ($expectedFiles as $filename) {
        $expectedPaths[] = $expectedPrefix . $filename;
    }

    $failures = [];

    $listResp = $uploader->listKnowledgeObjects($tenantSno);
    $listItems = $listResp['items'];
    sort($listItems);
    sort($expectedPaths);

    if ($listResp['status'] !== 200) {
        $failures[] = 'objects.list http ' . $listResp['status'];
    }
    if (count($listItems) !== 5) {
        $failures[] = 'objects.list count is not five';
    }
    if ($listItems !== $expectedPaths) {
        $failures[] = 'objects.list paths mismatch';
    }

    foreach ($expectedFiles as $filename) {
        $objectPath = $expectedPrefix . $filename;
        $getResp = $uploader->getObject($objectPath);
        $decoded = json_decode($getResp['body'], true);

        if ($getResp['status'] !== 200 || !is_array($decoded)) {
            $failures[] = 'read-back failed: ' . $objectPath;
            continue;
        }

        if (!isset($decoded['tenant_sno']) || $decoded['tenant_sno'] !== $tenantSno) {
            $failures[] = 'tenant_sno mismatch: ' . $objectPath;
        }
        if (!isset($decoded['data_category']) || $decoded['data_category'] !== BdsJsonWriter::DATA_CATEGORY) {
            $failures[] = 'data_category mismatch: ' . $objectPath;
        }
        if (!isset($decoded['schema_version']) || !is_string($decoded['schema_version']) || $decoded['schema_version'] === '') {
            $failures[] = 'schema_version missing: ' . $objectPath;
        }

        if ($expectedSyncId !== null && $expectedSyncId !== '') {
            if (!isset($decoded['sync_id']) || $decoded['sync_id'] !== $expectedSyncId) {
                $failures[] = 'sync_id mismatch: ' . $objectPath;
            }
        }

        $contentOk = false;
        if ($filename === 'company_profile.json') {
            $profileFieldCount = isset($decoded['profile']) && is_array($decoded['profile']) ? count($decoded['profile']) : 0;
            $contentOk = $profileFieldCount > 0;
        } else {
            $contentOk = isset($decoded['items']) && is_array($decoded['items']) && strlen($getResp['body']) > 10;
        }
        if (!$contentOk) {
            $failures[] = 'content empty: ' . $objectPath;
        }

        if (strpos($objectPath, 'shared/') !== false || strpos($objectPath, 'shared_knowledge/') !== false) {
            $failures[] = 'forbidden shared path: ' . $objectPath;
        }
    }

    return [
        'ok' => count($failures) === 0,
        'failures' => $failures,
    ];
}

$args = parse_cli_args($argv);
$outputRoot = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds';

fwrite(STDOUT, '[BDS]' . PHP_EOL);

if ($args['tenant_key'] === '' && $args['sno'] === '') {
    fwrite(STDERR, "ERROR: missing tenant parameter (BDS_MISSING_TENANT_PARAM)\n");
    print_usage();
    exit(1);
}

$entry = BdsSourceRegistryLoader::resolve(
    $args['tenant_key'] !== '' ? $args['tenant_key'] : null,
    $args['sno'] !== '' ? $args['sno'] : null
);

if ($entry === null) {
    print_step('Registry', 'FAIL');
    fwrite(STDERR, "ERROR: registry entry not found or tenant/sno mismatch\n");
    write_registry_error_report($outputRoot, ['sno' => $args['sno'] !== '' ? $args['sno'] : 'unknown'], 'BDS_REGISTRY_NOT_FOUND', 'registry entry not found');
    exit(1);
}

$tenantKey = (string) $entry['tenant_key'];
$tenantSno = (string) $entry['sno'];
$sheetId = (string) $entry['private_knowledge_sheet_id'];
$uploadSessionId = $args['upload_session_id'];
$isUploadMode = $uploadSessionId !== '';

fwrite(STDOUT, 'Tenant: ' . $tenantKey . PHP_EOL);
fwrite(STDOUT, 'Input Mode: ' . ($isUploadMode ? 'upload' : 'sheet') . PHP_EOL);

if ($entry['enabled'] !== true) {
    print_step('Registry', 'FAIL');
    fwrite(STDERR, "ERROR: tenant disabled in registry\n");
    write_registry_error_report($outputRoot, $entry, 'BDS_TENANT_DISABLED', 'tenant disabled');
    exit(1);
}

if ($tenantSno === '') {
    print_step('Registry', 'FAIL');
    fwrite(STDERR, "ERROR: registry missing sno\n");
    write_registry_error_report($outputRoot, $entry, 'BDS_REGISTRY_INCOMPLETE', 'missing required registry fields');
    exit(1);
}

if (!$isUploadMode && $sheetId === '') {
    print_step('Registry', 'FAIL');
    fwrite(STDERR, "ERROR: registry missing private_knowledge_sheet_id\n");
    write_registry_error_report($outputRoot, $entry, 'BDS_REGISTRY_INCOMPLETE', 'missing required registry fields');
    exit(1);
}

print_step('Registry', 'PASS');

$parser = new BdsMockSheetParser();
$validator = new BdsValidator();
$writer = new BdsJsonWriter($parser, $validator, $outputRoot);
$sourceSheetId = null;
$uploadSource = null;
$tabs = [];

if ($isUploadMode) {
    print_step('Google Sheet', 'SKIP (upload mode)');

    $uploadsRoot = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'uploads';
    $resolver = new BdsUploadStagingResolver($uploadsRoot);
    $staging = $resolver->resolve($tenantKey, $tenantSno, $uploadSessionId);

    if (($staging['ok'] ?? false) !== true) {
        print_step('Upload Staging', 'FAIL');
        $reason = isset($staging['reason']) ? (string) $staging['reason'] : 'staging resolve failed';
        fwrite(STDERR, 'ERROR: upload staging resolve failed: ' . $reason . PHP_EOL);
        write_registry_error_report($outputRoot, $entry, 'BDS_UPLOAD_STAGING_FAILED', $reason);
        exit(1);
    }

    print_step('Upload Staging', 'PASS');

    try {
        $xlsxReader = new BdsXlsxReader();
        $tabs = $xlsxReader->readFile((string) $staging['xlsx_path']);
    } catch (Throwable $e) {
        print_step('Upload Excel', 'FAIL');
        fwrite(STDERR, 'ERROR: upload excel read failed: ' . $e->getMessage() . PHP_EOL);
        write_registry_error_report($outputRoot, $entry, 'BDS_UPLOAD_XLSX_READ_FAILED', $e->getMessage());
        exit(1);
    }

    print_step('Upload Excel', 'PASS');
    $uploadSource = build_upload_source_metadata(
        $uploadSessionId,
        (string) ($staging['stored_filename'] ?? '')
    );
} else {
    $credentialsPath = env_string('BDS_GOOGLE_APPLICATION_CREDENTIALS');
    $credentialsPath = $credentialsPath !== '' ? $credentialsPath : null;

    try {
        $reader = new BdsGoogleSheetReader($credentialsPath);
        $tabs = $reader->readSheet($sheetId);
    } catch (Throwable $e) {
        print_step('Google Sheet', 'FAIL');
        fwrite(STDERR, 'ERROR: sheet read failed: ' . $e->getMessage() . PHP_EOL);
        write_registry_error_report($outputRoot, $entry, 'BDS_SHEET_READ_FAILED', $e->getMessage());
        exit(1);
    }

    print_step('Google Sheet', 'PASS');
    $sourceSheetId = $sheetId;
}

$normalized = $parser->parse($tenantSno, $tabs);
$validation = $validator->validate($normalized, $tabs);

if (($validation['ok'] ?? false) !== true) {
    $writer->writeDryRun($tenantSno, $normalized, $validation, $sourceSheetId, $uploadSource);
    print_step('Validation', 'FAIL');
    print_step('Knowledge Build', 'SKIP');
    print_step('GCS Upload', 'SKIP');
    print_step('Read-back Verification', 'SKIP');
    fwrite(STDERR, "ERROR: validation failed; GCS not modified\n");
    exit(1);
}

print_step('Validation', 'PASS');

$knowledgeJson = BdsKnowledgeDocumentBuilder::fromNormalized($tenantSno, $normalized, $sourceSheetId);
if (count($knowledgeJson) !== 5) {
    print_step('Knowledge Build', 'FAIL');
    print_step('GCS Upload', 'SKIP');
    print_step('Read-back Verification', 'SKIP');
    fwrite(STDERR, "ERROR: knowledge build did not produce five documents\n");
    exit(1);
}

print_step('Knowledge Build', 'PASS');

$writeGcs = $args['write_gcs'] === true;
$dryRun = $args['dry_run'] === true;

if ($writeGcs && $dryRun) {
    print_step('GCS Upload', 'FAIL');
    print_step('Read-back Verification', 'SKIP');
    fwrite(STDERR, "ERROR: --write-gcs requires --dry-run=false\n");
    exit(1);
}

if (!$writeGcs || $dryRun) {
    $dryRunResult = $writer->writeDryRun($tenantSno, $normalized, $validation, $sourceSheetId, $uploadSource);
    if (($dryRunResult['ok'] ?? false) !== true) {
        print_step('GCS Upload', 'SKIP');
        print_step('Read-back Verification', 'SKIP');
        fwrite(STDERR, "ERROR: JSON writer dry-run failed\n");
        exit(1);
    }

    print_step('GCS Upload', 'SKIP (dry-run)');
    print_step('Read-back Verification', 'SKIP (dry-run)');
    fwrite(STDOUT, 'Completed.' . PHP_EOL);
    exit(0);
}

$credentialsPath = env_string('BDS_GOOGLE_APPLICATION_CREDENTIALS');
$credentialsPath = $credentialsPath !== '' ? $credentialsPath : null;
putenv('BDS_DRY_RUN=false');
putenv('BDS_GCS_WRITE_ENABLED=true');
putenv('BDS_TARGET_SNO=' . $tenantSno);

$resolvedContext = [
    'tenant_key' => $tenantKey,
    'sno' => $tenantSno,
    'gcs_prefix' => (string) ($entry['gcs_prefix'] ?? ''),
];

$gate = BdsGcsUploader::evaluateResolvedIdentityGate($resolvedContext);
if ($gate['open'] !== true) {
    print_step('GCS Upload', 'FAIL');
    print_step('Read-back Verification', 'SKIP');
    fwrite(STDERR, 'ERROR: GCS write gate not open (' . $gate['reason'] . ')' . PHP_EOL);
    exit(1);
}

$bucket = env_string('BDS_GCS_BUCKET');
$bucket = $bucket !== '' ? $bucket : null;
$uploader = new BdsGcsUploader($bucket, $credentialsPath, $outputRoot);

$syncId = null;
$publishedAt = null;
$uploadStartedAt = gmdate('Y-m-d\TH:i:s\Z');

if ($isUploadMode) {
    $syncId = generate_sync_id();
    $publishedAt = gmdate('Y-m-d\TH:i:s\Z');
    $knowledgeJson = BdsKnowledgeDocumentBuilder::applyFormalSyncMetadata(
        $knowledgeJson,
        $syncId,
        $publishedAt,
        $uploadSource
    );
}

$uploadResult = $uploader->uploadKnowledge($tenantSno, $knowledgeJson, $validation, $resolvedContext, $sourceSheetId);

if (($uploadResult['ok'] ?? false) !== true) {
    print_step('GCS Upload', 'FAIL');
    print_step('Read-back Verification', 'SKIP');
    $blocked = isset($uploadResult['blocked_reason']) ? (string) $uploadResult['blocked_reason'] : 'upload_failed';
    fwrite(STDERR, 'ERROR: GCS upload failed (' . $blocked . ')' . PHP_EOL);
    exit(1);
}

print_step('GCS Upload', 'PASS');

$simulateReadbackFail = $isUploadMode && env_string('BDS_SYNC_SIMULATE_READBACK_FAIL') === '1';
if ($simulateReadbackFail) {
    $readback = [
        'ok' => false,
        'failures' => ['simulated_readback_fail'],
    ];
} else {
    $readback = verify_readback($uploader, $tenantSno, $isUploadMode ? $syncId : null);
}
if ($readback['ok'] !== true) {
    print_step('Read-back Verification', 'FAIL');
    foreach ($readback['failures'] as $failure) {
        fwrite(STDERR, 'READBACK_FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

print_step('Read-back Verification', 'PASS');

if ($isUploadMode && $syncId !== null && $publishedAt !== null) {
    $syncReportPath = $writer->writeFormalSyncReport(
        $tenantSno,
        $syncId,
        $publishedAt,
        $uploadStartedAt,
        gmdate('Y-m-d\TH:i:s\Z'),
        $uploadSource,
        $uploadResult
    );
    fwrite(STDOUT, 'Sync ID: ' . $syncId . PHP_EOL);
    fwrite(STDOUT, 'Sync Report: ' . $syncReportPath . PHP_EOL);
}

fwrite(STDOUT, 'Completed.' . PHP_EOL);
exit(0);
