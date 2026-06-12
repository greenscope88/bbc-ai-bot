<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataReportWriter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveMetadataValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';

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

/**
 * @return array<string, mixed>|null
 */
function fetch_drive_file_metadata_for_test(BdsDriveClient $client, string $fileId): ?array
{
    try {
        $file = $client->getDriveService()->files->get($fileId, [
            'fields' => 'id,name,mimeType,createdTime,modifiedTime,size,md5Checksum',
            'supportsAllDrives' => true,
        ]);
    } catch (Throwable $e) {
        return null;
    }

    return [
        'id' => (string) $file->getId(),
        'name' => (string) $file->getName(),
        'mimeType' => (string) $file->getMimeType(),
        'createdTime' => (string) $file->getCreatedTime(),
        'modifiedTime' => (string) $file->getModifiedTime(),
        'size' => $file->getSize(),
        'md5Checksum' => $file->getMd5Checksum(),
    ];
}

function remove_directory_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
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

// --- Unit tests with isolated temp root ---
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bds_report_writer_' . uniqid('', true);
$writer = new BdsDriveMetadataReportWriter($tempRoot);
$builder = new BdsDriveMetadataEnvelopeBuilder();
$validator = new BdsDriveMetadataValidator();

$validEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'file_valid_001',
    'name' => 'brochure.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], 'bds-drive-report-unit-001');

$validValidation = $validator->validate($validEnvelope);

$invalidEnvelope = $validEnvelope;
$invalidEnvelope['tenant_sno'] = '0000000000000000';
$invalidValidation = $validator->validate($invalidEnvelope);

$warningEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'file_warn_001',
    'name' => 'misc.xlsx',
    'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'createdTime' => '2026-02-01T08:00:00.000Z',
    'modifiedTime' => '2026-02-02T08:00:00.000Z',
], 'bds-drive-report-unit-001');
$warningValidation = $validator->validate($warningEnvelope);

$report = $writer->buildReport([
    'sync_job_id' => 'bds-drive-report-unit-001',
    'tenant_key' => 'travel_b',
    'tenant_sno' => PILOT_SNO,
    'industry_code' => 'travel',
    'owner_scope' => 'tenant',
    'scan_scope' => 'tenant_private',
], [
    ['envelope' => $validEnvelope, 'validation' => $validValidation],
    ['envelope' => $invalidEnvelope, 'validation' => $invalidValidation],
    ['envelope' => $warningEnvelope, 'validation' => $warningValidation],
]);

test_assert($report['schema_version'] === 'bds_drive_sync_report.v1', 'report schema_version');
test_assert($report['total_files'] === 3, 'total_files count');
test_assert($report['valid_count'] === 2, 'valid_count');
test_assert($report['invalid_count'] === 1, 'invalid_count');
test_assert($report['warning_count'] >= 1, 'warning_count includes excel ambiguous item');

$metaDir = $writer->resolveTenantMetaDirectory(PILOT_SNO);
test_assert(!is_dir($metaDir), 'meta directory does not exist before write');

$reportPath = $writer->writeReport($report, PILOT_SNO);
test_assert(is_file($reportPath), 'drive_sync_report.json written');
test_assert(is_dir($metaDir), 'meta directory created automatically');
test_assert(
    $reportPath === $tempRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . PILOT_SNO . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . 'drive_sync_report.json',
    'report path under var/bds tenants meta'
);

$decoded = json_decode((string) file_get_contents($reportPath), true);
test_assert(is_array($decoded), 'report JSON parseable');
test_assert(is_array($decoded['items'] ?? null) && count($decoded['items']) === 3, 'items array length');

$firstItem = $decoded['items'][0];
test_assert(isset($firstItem['file_id'], $firstItem['source_path'], $firstItem['validation_status']), 'item contract fields');
test_assert(strpos((string) $firstItem['source_path'], '01_Private_Layer/') !== false, 'item uses logical source_path');

$knowledgePath = $tempRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . PILOT_SNO . DIRECTORY_SEPARATOR . 'knowledge';
test_assert(!is_dir($knowledgePath), 'no knowledge directory created');

$gcsPath = $tempRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . PILOT_SNO . DIRECTORY_SEPARATOR . 'gcs';
test_assert(!is_dir($gcsPath), 'no gcs directory created');

remove_directory_recursive($tempRoot);

// --- Host A pipeline ---
$credentialsPath = resolve_credentials_path_for_test();
if ($credentialsPath === null) {
    fwrite(STDERR, "SKIP: Host A report writer live test (no credentials)\n");
} else {
    $projectRoot = dirname(__DIR__, 2);
    $hostWriter = new BdsDriveMetadataReportWriter($projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds');
    $hostBuilder = new BdsDriveMetadataEnvelopeBuilder();
    $hostValidator = new BdsDriveMetadataValidator();
    $scanner = new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath));

    $syncJobId = 'bds-drive-hosta-report-' . gmdate('Ymd-His');
    $records = [];

    $children = $scanner->scanChildren(PILOT_FOLDER_ID);
    foreach ($children['files'] as $child) {
        $meta = fetch_drive_file_metadata_for_test($scanner->getDriveClient(), $child['id']);
        if ($meta === null) {
            continue;
        }

        $envelope = $hostBuilder->buildFromTenantKey('travel_b', $meta, $syncJobId);
        $validation = $hostValidator->validate($envelope);
        $records[] = ['envelope' => $envelope, 'validation' => $validation];
    }

    $hostReport = $hostWriter->buildReport([
        'sync_job_id' => $syncJobId,
        'tenant_key' => 'travel_b',
        'tenant_sno' => PILOT_SNO,
        'industry_code' => 'travel',
        'owner_scope' => 'tenant',
        'scan_scope' => 'tenant_private',
    ], $records);

    $hostReportPath = $hostWriter->writeReport($hostReport, PILOT_SNO);
    $expectedPath = $projectRoot
        . DIRECTORY_SEPARATOR . 'var'
        . DIRECTORY_SEPARATOR . 'bds'
        . DIRECTORY_SEPARATOR . 'tenants'
        . DIRECTORY_SEPARATOR . PILOT_SNO
        . DIRECTORY_SEPARATOR . 'meta'
        . DIRECTORY_SEPARATOR . 'drive_sync_report.json';

    test_assert($hostReportPath === $expectedPath, 'Host A report path matches SSOT local root');
    test_assert(is_file($hostReportPath), 'Host A drive_sync_report.json exists');

    $hostDecoded = json_decode((string) file_get_contents($hostReportPath), true);
    test_assert(is_array($hostDecoded), 'Host A report JSON parseable');
    test_assert(($hostDecoded['total_files'] ?? -1) === count($records), 'Host A total_files matches scanned files');
    test_assert(
        ($hostDecoded['valid_count'] ?? -1) + ($hostDecoded['invalid_count'] ?? -1) === count($records),
        'Host A valid + invalid equals total'
    );

    foreach ($hostDecoded['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        test_assert(strpos((string) ($item['source_path'] ?? ''), '01_Private_Layer/') !== false, 'Host A logical path');
        test_assert(strpos((string) ($item['source_path'] ?? ''), '旅行蜜優惠') === false, 'Host A excludes display folder name');
    }

    $hostKnowledgeDir = dirname(dirname(dirname($hostReportPath))) . DIRECTORY_SEPARATOR . 'knowledge';
    test_assert(!is_dir($hostKnowledgeDir), 'Host A no knowledge json directory');

    fwrite(STDOUT, "HOST_A report: {$hostReportPath}\n");
    fwrite(STDOUT, "HOST_A total_files={$hostDecoded['total_files']} valid={$hostDecoded['valid_count']} invalid={$hostDecoded['invalid_count']}\n");
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_metadata_report_writer (all passed)\n");
exit(0);
