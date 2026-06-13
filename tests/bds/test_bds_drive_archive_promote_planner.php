<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveArchivePromotePlanner.php';

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

// --- Unit: path builder ---
$planner = new BdsDriveArchivePromotePlanner();

$plannedPath = $planner->buildPlannedObjectPath(
    PILOT_SNO,
    'itinerary_data',
    'abc123file',
    'brochure.pdf'
);
test_assert(
    $plannedPath === 'tenants/5f99b8d665e8444d/archive/itinerary_data/abc123file/brochure.pdf',
    'planned_object_path template'
);
test_assert(strpos($plannedPath, '/uploads/') === false, 'planned path excludes uploads alias');

// --- Unit: gate evaluation with synthetic envelopes ---
$builder = new BdsDriveMetadataEnvelopeBuilder();
$validator = new BdsDriveMetadataValidator();

$pdfEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'pdf_unit_001',
    'name' => 'brochure.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'size' => 1024,
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], 'bds-archive-plan-unit-001');
$pdfValidation = $validator->validate($pdfEnvelope);
$pdfItem = $planner->evaluateFilePlan($pdfEnvelope, $pdfValidation, 'travel_b', PILOT_FOLDER_ID);

test_assert($pdfItem['status'] === BdsDriveArchivePromotePlanner::STATUS_PROMOTE, 'pdf status PROMOTE');
test_assert(
    $pdfItem['planned_object_path'] === 'tenants/5f99b8d665e8444d/archive/itinerary_data/pdf_unit_001/brochure.pdf',
    'pdf planned_object_path'
);
test_assert(is_array($pdfItem['read_back_plan'] ?? null), 'pdf read_back_plan present');
test_assert(
    ($pdfItem['read_back_plan']['action'] ?? '') === 'verify_current_object',
    'pdf read_back action'
);
test_assert(
    ($pdfItem['read_back_plan']['versioning'] ?? '') === 'current_only',
    'pdf read_back current_only'
);

$sheetEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'sheet_unit_001',
    'name' => '基本資料',
    'mimeType' => 'google-apps.spreadsheet',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
], 'bds-archive-plan-unit-001');
$sheetValidation = $validator->validate($sheetEnvelope);
$sheetItem = $planner->evaluateFilePlan($sheetEnvelope, $sheetValidation, 'travel_b', PILOT_FOLDER_ID);

test_assert($sheetItem['status'] === BdsDriveArchivePromotePlanner::STATUS_SKIP, 'google sheet status SKIP');
test_assert(($sheetItem['gate'] ?? '') === 'content_type', 'google sheet gate content_type');
test_assert(
    ($sheetItem['reason'] ?? '') === BdsDriveArchivePromotePlanner::WARNING_GOOGLE_WORKSPACE,
    'google sheet reason W_GOOGLE_WORKSPACE_EXPORT_REQUIRED'
);
test_assert(
    in_array(BdsDriveArchivePromotePlanner::WARNING_GOOGLE_WORKSPACE, $sheetItem['warnings'] ?? [], true),
    'google sheet warning code present'
);
test_assert($sheetItem['planned_object_path'] === null, 'google sheet no planned path');

$industryEnvelope = $builder->buildFromDriveFile([
    'id' => 'industry_file_001',
    'name' => 'shared.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], $builder->buildScanContextForIndustry('travel'), 'bds-archive-plan-unit-001');
$industryValidation = $validator->validate($industryEnvelope);
$industryItem = $planner->evaluateFilePlan($industryEnvelope, $industryValidation, 'travel_b', PILOT_FOLDER_ID);

test_assert($industryItem['status'] === BdsDriveArchivePromotePlanner::STATUS_SKIP, 'industry scope SKIP');
test_assert(($industryItem['gate'] ?? '') === 'scope', 'industry gate scope');

$unsafeEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'unsafe_001',
    'name' => '../evil.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'size' => 512,
    'md5Checksum' => 'fedcba9876543210fedcba9876543210',
], 'bds-archive-plan-unit-001');
$unsafeValidation = $validator->validate($unsafeEnvelope);
$unsafeItem = $planner->evaluateFilePlan($unsafeEnvelope, $unsafeValidation, 'travel_b', PILOT_FOLDER_ID);

test_assert($unsafeItem['status'] === BdsDriveArchivePromotePlanner::STATUS_FAIL, 'unsafe filename FAIL');
test_assert(($unsafeItem['gate'] ?? '') === 'path_safety', 'unsafe filename gate path_safety');

// --- Unit: buildPlan + writePlan ---
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bds_archive_plan_' . uniqid('', true);
$tempPlanner = new BdsDriveArchivePromotePlanner(null, null, null, $tempRoot);

$plan = $tempPlanner->buildPlan([
    'sync_job_id' => 'bds-archive-plan-unit-001',
    'tenant_key' => 'travel_b',
    'tenant_sno' => PILOT_SNO,
    'industry_code' => 'travel',
    'folder_id' => PILOT_FOLDER_ID,
    'mode' => 'dry_run_plan',
], [$pdfItem, $sheetItem, $industryItem]);

test_assert($plan['schema_version'] === 'bds_archive_promote_plan.v1', 'plan schema_version');
test_assert(($plan['summary']['promote_count'] ?? -1) === 1, 'plan promote_count');
test_assert(($plan['summary']['skip_count'] ?? -1) === 2, 'plan skip_count');
test_assert(isset($plan['environment']['planner_note']), 'plan environment snapshot');
test_assert(($plan['mode'] ?? '') === 'dry_run_plan', 'plan mode dry_run_plan');

$metaDir = $tempPlanner->resolveTenantMetaDirectory(PILOT_SNO);
test_assert(!is_dir($metaDir), 'meta directory absent before writePlan');

$planPath = $tempPlanner->writePlan($plan, PILOT_SNO);
test_assert(is_file($planPath), 'archive_promote_plan.json written');
test_assert(
    $planPath === $tempRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . PILOT_SNO
        . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . 'archive_promote_plan.json',
    'plan path under var/bds tenants meta'
);

$decoded = json_decode((string) file_get_contents($planPath), true);
test_assert(is_array($decoded), 'plan JSON parseable');
test_assert(is_array($decoded['items'] ?? null) && count($decoded['items']) === 3, 'plan items count');

$archiveDir = $tempRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . PILOT_SNO . DIRECTORY_SEPARATOR . 'archive';
test_assert(!is_dir($archiveDir), 'no archive directory created');

remove_directory_recursive($tempRoot);

// --- Host A live scan ---
$credentialsPath = resolve_credentials_path_for_test();
if ($credentialsPath === null) {
    fwrite(STDERR, "SKIP: Host A archive promote planner live test (no credentials)\n");
} else {
    $projectRoot = dirname(__DIR__, 2);
    $hostPlanner = new BdsDriveArchivePromotePlanner(
        new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath)),
        null,
        null,
        $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds'
    );

    $syncJobId = 'bds-archive-plan-hosta-' . gmdate('Ymd-His');
    $hostPlan = $hostPlanner->planForTenantFolder('travel_b', PILOT_FOLDER_ID, $syncJobId);
    $hostPlanPath = $hostPlanner->writePlan($hostPlan, PILOT_SNO);

    $expectedPath = $projectRoot
        . DIRECTORY_SEPARATOR . 'var'
        . DIRECTORY_SEPARATOR . 'bds'
        . DIRECTORY_SEPARATOR . 'tenants'
        . DIRECTORY_SEPARATOR . PILOT_SNO
        . DIRECTORY_SEPARATOR . 'meta'
        . DIRECTORY_SEPARATOR . 'archive_promote_plan.json';

    test_assert($hostPlanPath === $expectedPath, 'Host A plan path matches SSOT');
    test_assert(is_file($hostPlanPath), 'Host A archive_promote_plan.json exists');
    test_assert(($hostPlan['tenant_key'] ?? '') === 'travel_b', 'Host A tenant_key');
    test_assert(($hostPlan['folder_id'] ?? '') === PILOT_FOLDER_ID, 'Host A folder_id');

    $hostDecoded = json_decode((string) file_get_contents($hostPlanPath), true);
    test_assert(is_array($hostDecoded), 'Host A plan JSON parseable');
    test_assert(
        ($hostDecoded['summary']['total_files'] ?? -1) === count($hostDecoded['items'] ?? []),
        'Host A summary total_files matches items'
    );

    $hasGoogleSkip = false;
    foreach ($hostDecoded['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        test_assert(
            strpos((string) ($item['source_path'] ?? ''), '01_Private_Layer/') !== false,
            'Host A item logical source_path'
        );
        if (strpos((string) ($item['mime_type'] ?? ''), 'google-apps.spreadsheet') !== false) {
            test_assert(
                ($item['status'] ?? '') === BdsDriveArchivePromotePlanner::STATUS_SKIP,
                'Host A google-apps.spreadsheet SKIP'
            );
            test_assert(
                ($item['reason'] ?? '') === BdsDriveArchivePromotePlanner::WARNING_GOOGLE_WORKSPACE,
                'Host A google sheet skip reason'
            );
            $hasGoogleSkip = true;
        }
        if (($item['status'] ?? '') === BdsDriveArchivePromotePlanner::STATUS_PROMOTE) {
            test_assert(
                strpos((string) ($item['planned_object_path'] ?? ''), 'tenants/' . PILOT_SNO . '/archive/') === 0,
                'Host A PROMOTE path prefix'
            );
            test_assert(is_array($item['read_back_plan'] ?? null), 'Host A PROMOTE read_back_plan');
        }
    }

    test_assert($hasGoogleSkip, 'Host A scanned at least one google-apps.spreadsheet');

    $hostArchiveDir = dirname(dirname(dirname($hostPlanPath))) . DIRECTORY_SEPARATOR . 'archive';
    test_assert(!is_dir($hostArchiveDir), 'Host A no GCS archive directory');

    fwrite(STDOUT, "HOST_A plan: {$hostPlanPath}\n");
    fwrite(STDOUT, sprintf(
        "HOST_A promote=%d skip=%d fail=%d\n",
        (int) ($hostDecoded['summary']['promote_count'] ?? 0),
        (int) ($hostDecoded['summary']['skip_count'] ?? 0),
        (int) ($hostDecoded['summary']['fail_count'] ?? 0)
    ));
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_drive_archive_promote_planner (all passed)\n");
exit(0);
