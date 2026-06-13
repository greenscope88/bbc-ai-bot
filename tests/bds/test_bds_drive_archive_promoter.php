<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveArchivePromoter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveArchiveReadBackVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveClient.php';

$failures = 0;

const PILOT_SNO = '5f99b8d665e8444d';
const PILOT_FOLDER_ID = '17wrq-rrvc7ezclhWlbvdTKxHSf_Hw8pi';

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function resolve_credentials_path_for_test(): ?string
{
    $candidates = [
        getenv('BDS_GOOGLE_APPLICATION_CREDENTIALS'),
        getenv('GOOGLE_APPLICATION_CREDENTIALS'),
    ];

    foreach ($candidates as $candidate) {
        $path = is_string($candidate) ? trim($candidate) : '';
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    $secretsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secrets';
    if (!is_dir($secretsDir)) {
        return null;
    }

    $matches = glob($secretsDir . DIRECTORY_SEPARATOR . '*.json');
    if (!is_array($matches) || $matches === []) {
        return null;
    }

    sort($matches, SORT_STRING);

    return is_file($matches[0]) ? $matches[0] : null;
}

function archive_promote_env_ready(): bool
{
    $gate = BdsDriveArchivePromoter::evaluatePromoteEnvironmentGate();

    return ($gate['open'] ?? false) === true;
}

function remove_directory_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            remove_directory_recursive($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

// --- Unit: environment gate shape ---
$gateShape = BdsDriveArchivePromoter::evaluatePromoteEnvironmentGate();
test_assert(isset($gateShape['open'], $gateShape['archive_promote_enabled'], $gateShape['bucket']), 'environment gate fields');

// --- Unit: read-back verifier ---
$verifier = new BdsDriveArchiveReadBackVerifier();
$sampleBytes = '%PDF-1.4 sample';
$checksum = $verifier->computeSha256Content($sampleBytes);
test_assert(strpos($checksum, 'sha256:') === 0, 'sha256 prefix');
test_assert(strlen($checksum) === 7 + 64, 'sha256 length');

$rb = $verifier->verify(
    'tenants/' . PILOT_SNO . '/archive/itinerary_data/file001/brochure.pdf',
    $checksum,
    'file001',
    PILOT_SNO,
    $sampleBytes,
    [
        'content_checksum' => $checksum,
        'drive_file_id' => 'file001',
        'tenant_sno' => PILOT_SNO,
    ],
    200
);
test_assert($rb['ok'] === true, 'read-back verifier pass');
test_assert(count($rb['checks']) >= 7, 'read-back check count');

$rbFail = $verifier->verify(
    'tenants/other/archive/itinerary_data/file001/brochure.pdf',
    $checksum,
    'file001',
    PILOT_SNO,
    $sampleBytes,
    ['content_checksum' => $checksum, 'drive_file_id' => 'file001', 'tenant_sno' => PILOT_SNO],
    200
);
test_assert($rbFail['ok'] === false, 'read-back tenant boundary fail');

// --- Unit: gate evaluation without GCS ---
$builder = new BdsDriveMetadataEnvelopeBuilder();
$validator = new BdsDriveMetadataValidator();
$promoter = new BdsDriveArchivePromoter();

$sheetEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'sheet_promote_001',
    'name' => '基本資料',
    'mimeType' => 'application/vnd.google-apps.spreadsheet',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
], 'bds-archive-promote-unit-001');
$sheetItem = $promoter->promoteFile(
    $sheetEnvelope,
    $validator->validate($sheetEnvelope),
    'travel_b',
    PILOT_FOLDER_ID,
    'bds-archive-promote-unit-001'
);
test_assert($sheetItem['status'] === BdsDriveArchivePromoter::STATUS_SKIP, 'google sheet SKIP');
test_assert(($sheetItem['reason'] ?? '') === BdsDriveArchivePromoter::WARNING_GOOGLE_WORKSPACE, 'google sheet skip reason');

$pdfEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'pdf_promote_unit',
    'name' => 'brochure.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'size' => 128,
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], 'bds-archive-promote-unit-001');

// Without env gates open, PDF should SKIP at environment
$prevDryRun = getenv('BDS_DRY_RUN');
putenv('BDS_DRY_RUN=true');
$pdfItemBlocked = $promoter->promoteFile(
    $pdfEnvelope,
    $validator->validate($pdfEnvelope),
    'travel_b',
    PILOT_FOLDER_ID,
    'bds-archive-promote-unit-001'
);
if ($prevDryRun === false) {
    putenv('BDS_DRY_RUN');
} elseif (is_string($prevDryRun)) {
    putenv('BDS_DRY_RUN=' . $prevDryRun);
}
test_assert($pdfItemBlocked['status'] === BdsDriveArchivePromoter::STATUS_SKIP, 'pdf blocked when dry_run');
test_assert(($pdfItemBlocked['gate'] ?? '') === 'environment', 'pdf environment gate');

// --- Unit: report writer ---
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bds_archive_promote_' . uniqid('', true);
$tempPromoter = new BdsDriveArchivePromoter(null, null, null, null, $tempRoot);
$report = $tempPromoter->buildReport([
    'sync_job_id' => 'unit-report',
    'tenant_key' => 'travel_b',
    'tenant_sno' => PILOT_SNO,
    'industry_code' => 'travel',
    'folder_id' => PILOT_FOLDER_ID,
    'bucket' => BdsGcsUploader::DEFAULT_BUCKET,
], [$sheetItem, $pdfItemBlocked]);
test_assert($report['schema_version'] === 'bds_archive_promote_report.v1', 'report schema');
$reportPath = $tempPromoter->writeReport($report, PILOT_SNO);
test_assert(is_file($reportPath), 'report written');
test_assert(strpos($reportPath, 'archive_promote_report.json') !== false, 'report filename');
remove_directory_recursive($tempRoot);

// --- Host A live promote ---
$credentialsPath = resolve_credentials_path_for_test();
if ($credentialsPath === null) {
    fwrite(STDERR, "SKIP: Host A archive promoter live test (no credentials)\n");
} elseif (!archive_promote_env_ready()) {
    fwrite(STDERR, "SKIP: Host A archive promoter live test (environment gate not open; set BDS_DRY_RUN=false BDS_GCS_WRITE_ENABLED=true BDS_ARCHIVE_PROMOTE_ENABLED=true BDS_TARGET_SNO=" . PILOT_SNO . ")\n");
} else {
    $projectRoot = dirname(__DIR__, 2);
    $hostPromoter = new BdsDriveArchivePromoter(
        new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath)),
        null,
        null,
        null,
        $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds',
        BdsGcsUploader::DEFAULT_BUCKET,
        $credentialsPath
    );

    $syncJobId = 'bds-archive-promote-hosta-' . gmdate('Ymd-His');
    $hostReport = $hostPromoter->promoteForTenantFolder('travel_b', PILOT_FOLDER_ID, $syncJobId);

    test_assert(($hostReport['tenant_key'] ?? '') === 'travel_b', 'Host A tenant_key');
    test_assert(($hostReport['folder_id'] ?? '') === PILOT_FOLDER_ID, 'Host A folder_id');
    test_assert(is_file((string) ($hostReport['report_path'] ?? '')), 'Host A report path exists');

    $hasGoogleSkip = false;
    $hasBinaryPromoted = false;
    $promotedPaths = [];

    foreach ($hostReport['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $mime = (string) ($item['mime_type'] ?? '');
        $status = (string) ($item['status'] ?? '');

        if (strpos($mime, 'google-apps') !== false) {
            test_assert($status === BdsDriveArchivePromoter::STATUS_SKIP, 'Host A google-apps SKIP');
            test_assert(
                ($item['reason'] ?? '') === BdsDriveArchivePromoter::WARNING_GOOGLE_WORKSPACE,
                'Host A google-apps skip reason'
            );
            $hasGoogleSkip = true;
        }

        if ($mime === 'application/pdf' || strpos($mime, 'image/') === 0) {
            if ($status === BdsDriveArchivePromoter::STATUS_PROMOTED) {
                $hasBinaryPromoted = true;
                $path = (string) ($item['gcs_object_path'] ?? '');
                test_assert(strpos($path, 'tenants/' . PILOT_SNO . '/archive/') === 0, 'Host A promoted path prefix');
                test_assert(strpos($path, '/knowledge/') === false, 'Host A not knowledge path');
                test_assert(
                    ($item['checksum_method'] ?? '') === BdsDriveArchiveReadBackVerifier::CHECKSUM_METHOD,
                    'Host A sha256_content method'
                );
                test_assert(is_array($item['read_back'] ?? null) && ($item['read_back']['ok'] ?? false) === true, 'Host A read-back PASS');
                $promotedPaths[] = $path;
            }
        }
    }

    test_assert($hasGoogleSkip, 'Host A has google-apps.spreadsheet SKIP');
    test_assert($hasBinaryPromoted, 'Host A has binary PDF/image PROMOTED');

    fwrite(STDOUT, 'HOST_A report: ' . ($hostReport['report_path'] ?? '') . PHP_EOL);
    fwrite(STDOUT, sprintf(
        "HOST_A promoted=%d skip=%d fail=%d\n",
        (int) ($hostReport['summary']['promoted_count'] ?? 0),
        (int) ($hostReport['summary']['skip_count'] ?? 0),
        (int) ($hostReport['summary']['fail_count'] ?? 0)
    ));
    foreach ($promotedPaths as $path) {
        fwrite(STDOUT, 'GCS_OBJECT|' . $path . PHP_EOL);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_archive_promoter (all passed)\n");
exit(0);
