<?php
declare(strict_types=1);

/**
 * BDS Phase 6E-4a — BdsSharedArchivePromotePlanner tests (dry-run only).
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §6.1、§8.1、§18
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSharedArchivePromotePlanner.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSharedArchivePolicyLoader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanException.php';

$failures = 0;

const TRAVEL_SHARED_FOLDER = '1i-yIs1H4pJsyOXyCO7eRLPh3eTbmSy7T';

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

/**
 * @param mixed $value
 */
function reset_shared_archive_policy_cache($value = null): void
{
    $ref = new ReflectionClass(BdsSharedArchivePolicyLoader::class);
    $prop = $ref->getProperty('policy');
    $prop->setAccessible(true);
    $prop->setValue($value);
}

function with_temp_shared_archive_policy_config(string $phpBody, callable $callback): void
{
    $previousPath = getenv('BDS_SHARED_ARCHIVE_POLICY_PATH');
    $tmp = tempnam(sys_get_temp_dir(), 'bds_shared_archive_policy_');
    if ($tmp === false) {
        throw new RuntimeException('Failed to create temp file for shared archive policy test.');
    }

    $configPath = $tmp . '.php';
    rename($tmp, $configPath);
    file_put_contents($configPath, $phpBody);

    putenv('BDS_SHARED_ARCHIVE_POLICY_PATH=' . $configPath);
    reset_shared_archive_policy_cache();

    try {
        $callback($configPath);
    } finally {
        if ($previousPath === false) {
            putenv('BDS_SHARED_ARCHIVE_POLICY_PATH');
        } else {
            putenv('BDS_SHARED_ARCHIVE_POLICY_PATH=' . $previousPath);
        }
        reset_shared_archive_policy_cache();
        if (is_file($configPath)) {
            unlink($configPath);
        }
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

reset_shared_archive_policy_cache();

// --- Unit: path builder ---
$planner = new BdsSharedArchivePromotePlanner();

$industryPath = $planner->buildPlannedObjectPath(
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    'travel',
    'abc123file',
    'brochure.pdf'
);
test_assert(
    $industryPath === 'shared/travel/archive/shared_knowledge/abc123file/brochure.pdf',
    'industry planned_object_path template'
);

$globalPath = $planner->buildPlannedObjectPath(
    BdsDriveFileClassifier::OWNER_GLOBAL,
    'global',
    'xyz789file',
    'guide.pdf'
);
test_assert(
    $globalPath === 'shared/global/archive/shared_knowledge/xyz789file/guide.pdf',
    'global planned_object_path template'
);
test_assert(strpos($industryPath, '/uploads/') === false, 'planned path excludes uploads alias');
test_assert(strpos($industryPath, '/knowledge/') === false, 'planned path excludes knowledge alias');

// --- Unit: gate evaluation with synthetic envelopes ---
$builder = new BdsDriveMetadataEnvelopeBuilder();
$validator = new BdsDriveMetadataValidator();

$pdfEnvelope = $builder->buildFromDriveFile([
    'id' => 'pdf_unit_001',
    'name' => 'brochure.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'size' => 1024,
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], $builder->buildScanContextForIndustry('travel'), 'bds-shared-archive-plan-unit-001');
$pdfValidation = $validator->validate($pdfEnvelope);

$policyOffItem = $planner->evaluateFilePlan(
    $pdfEnvelope,
    $pdfValidation,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    'travel',
    false,
    TRAVEL_SHARED_FOLDER
);
test_assert($policyOffItem['status'] === BdsSharedArchivePromotePlanner::STATUS_SKIP, 'policy OFF status SKIP');
test_assert(($policyOffItem['gate'] ?? '') === 'policy', 'policy OFF gate policy');
test_assert(
    ($policyOffItem['reason'] ?? '') === BdsSharedArchivePromotePlanner::REASON_POLICY_DISABLED,
    'policy OFF reason POLICY_DISABLED'
);
test_assert($policyOffItem['planned_object_path'] === null, 'policy OFF no planned path');

$policyOnItem = $planner->evaluateFilePlan(
    $pdfEnvelope,
    $pdfValidation,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    'travel',
    true,
    TRAVEL_SHARED_FOLDER
);
test_assert($policyOnItem['status'] === BdsSharedArchivePromotePlanner::STATUS_PROMOTE, 'policy ON pdf status PROMOTE');
test_assert(
    $policyOnItem['planned_object_path'] === 'shared/travel/archive/shared_knowledge/pdf_unit_001/brochure.pdf',
    'policy ON pdf planned_object_path'
);
test_assert(is_array($policyOnItem['read_back_plan'] ?? null), 'policy ON pdf read_back_plan present');
test_assert(
    ($policyOnItem['read_back_plan']['deferred_phase'] ?? '') === '6E-4b',
    'policy ON pdf read_back deferred_phase'
);

$registryMissingItem = $planner->evaluateFilePlan(
    $pdfEnvelope,
    $pdfValidation,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    'travel',
    true,
    null
);
test_assert($registryMissingItem['status'] === BdsSharedArchivePromotePlanner::STATUS_SKIP, 'registry missing status SKIP');
test_assert(
    ($registryMissingItem['reason'] ?? '') === BdsSharedArchivePromotePlanner::REASON_REGISTRY_FOLDER_MISSING,
    'registry missing reason REGISTRY_FOLDER_MISSING'
);

$tenantEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'tenant_file_001',
    'name' => 'private.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], 'bds-shared-archive-plan-unit-001');
$tenantValidation = $validator->validate($tenantEnvelope);
$scopeMismatchItem = $planner->evaluateFilePlan(
    $tenantEnvelope,
    $tenantValidation,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    'travel',
    true,
    TRAVEL_SHARED_FOLDER
);
test_assert($scopeMismatchItem['status'] === BdsSharedArchivePromotePlanner::STATUS_FAIL, 'owner_scope mismatch FAIL');
test_assert(($scopeMismatchItem['gate'] ?? '') === 'owner_scope', 'owner_scope mismatch gate');

$sheetEnvelope = $builder->buildFromDriveFile([
    'id' => 'sheet_unit_001',
    'name' => '基本資料',
    'mimeType' => 'application/vnd.google-apps.spreadsheet',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
], $builder->buildScanContextForIndustry('travel'), 'bds-shared-archive-plan-unit-001');
$sheetValidation = $validator->validate($sheetEnvelope);
$sheetItem = $planner->evaluateFilePlan(
    $sheetEnvelope,
    $sheetValidation,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    'travel',
    true,
    TRAVEL_SHARED_FOLDER
);
test_assert($sheetItem['status'] === BdsSharedArchivePromotePlanner::STATUS_SKIP, 'google sheet status SKIP');
test_assert(($sheetItem['gate'] ?? '') === 'content_type', 'google sheet gate content_type');
test_assert(
    ($sheetItem['reason'] ?? '') === BdsSharedArchivePromotePlanner::WARNING_GOOGLE_WORKSPACE,
    'google sheet reason W_GOOGLE_WORKSPACE_EXPORT_REQUIRED'
);

$invalidEnvelope = $pdfEnvelope;
unset($invalidEnvelope['file_id']);
$invalidValidation = $validator->validate($invalidEnvelope);
$metadataFailItem = $planner->evaluateFilePlan(
    $invalidEnvelope,
    $invalidValidation,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    'travel',
    true,
    TRAVEL_SHARED_FOLDER
);
test_assert($metadataFailItem['status'] === BdsSharedArchivePromotePlanner::STATUS_FAIL, 'metadata validation FAIL');
test_assert(($metadataFailItem['gate'] ?? '') === 'metadata', 'metadata validation gate');

// --- Unit: buildPlan + writePlan ---
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bds_shared_archive_plan_' . uniqid('', true);
$tempPlanner = new BdsSharedArchivePromotePlanner(null, null, null, $tempRoot);

$plan = $tempPlanner->buildPlan([
    'sync_job_id' => 'bds-shared-archive-plan-unit-001',
    'owner_scope' => BdsDriveFileClassifier::OWNER_INDUSTRY,
    'industry_code' => 'travel',
    'folder_id' => TRAVEL_SHARED_FOLDER,
    'policy_enabled' => false,
    'folder_status' => BdsDriveFolderScanner::STATUS_OK,
    'mode' => 'dry_run_plan',
], [$policyOffItem, $sheetItem, $scopeMismatchItem]);

test_assert($plan['schema_version'] === BdsSharedArchivePromotePlanner::SCHEMA_VERSION, 'plan schema_version');
test_assert(($plan['owner_scope'] ?? '') === BdsDriveFileClassifier::OWNER_INDUSTRY, 'plan owner_scope industry');
test_assert(($plan['policy_enabled'] ?? true) === false, 'plan policy_enabled false');
test_assert(($plan['summary']['skip_count'] ?? -1) === 2, 'plan skip_count');
test_assert(($plan['summary']['fail_count'] ?? -1) === 1, 'plan fail_count');
test_assert(isset($plan['environment']['gcs_write_enabled']) && $plan['environment']['gcs_write_enabled'] === false, 'plan gcs_write_enabled false');
test_assert(($plan['mode'] ?? '') === 'dry_run_plan', 'plan mode dry_run_plan');
test_assert(($plan['scan_scope'] ?? '') === BdsDriveFolderScanner::SCAN_SCOPE_INDUSTRY_SHARED, 'plan scan_scope industry_shared');

$metaDir = $tempPlanner->resolveSharedMetaDirectory('travel');
test_assert(!is_dir($metaDir), 'meta directory absent before writePlan');

$planPath = $tempPlanner->writePlan($plan, 'travel');
test_assert(is_file($planPath), 'archive_promote_plan.json written');
test_assert(
    $planPath === $tempRoot . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'travel'
        . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . 'archive_promote_plan.json',
    'plan path under var/bds shared travel meta'
);

$decoded = json_decode((string) file_get_contents($planPath), true);
test_assert(is_array($decoded), 'plan JSON parseable');
test_assert(is_array($decoded['items'] ?? null) && count($decoded['items']) === 3, 'plan items count');

$archiveDir = $tempRoot . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'travel' . DIRECTORY_SEPARATOR . 'archive';
test_assert(!is_dir($archiveDir), 'no shared archive directory created');

remove_directory_recursive($tempRoot);

// --- Unit: registry folder missing at plan level ---
$missingPlanner = new BdsSharedArchivePromotePlanner();
$hotelPlan = $missingPlanner->planForIndustrySharedFolder('hotel', 'bds-shared-archive-plan-missing-001');
test_assert(($hotelPlan['folder_status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'hotel folder_status missing');
test_assert(($hotelPlan['summary']['total_files'] ?? -1) === 0, 'hotel missing folder zero items');
test_assert(
    ($hotelPlan['folder_error_code'] ?? '') === BdsDriveFolderScanException::REGISTRY_MISSING,
    'hotel folder_error_code REGISTRY_FOLDER_MISSING'
);

$globalMissingPlan = $missingPlanner->planForGlobalSharedFolder('bds-shared-archive-plan-missing-002');
test_assert(($globalMissingPlan['folder_status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING, 'global folder_status missing');
test_assert(($globalMissingPlan['owner_scope'] ?? '') === BdsDriveFileClassifier::OWNER_GLOBAL, 'global missing owner_scope');

// --- Host A live scan (policy OFF) ---
$credentialsPath = resolve_credentials_path_for_test();
if ($credentialsPath === null) {
    fwrite(STDERR, "SKIP: Host A shared archive promote planner live test (no credentials)\n");
} else {
    $projectRoot = dirname(__DIR__, 2);
    $hostPlanner = new BdsSharedArchivePromotePlanner(
        new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath)),
        null,
        null,
        $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds'
    );

    $syncJobId = 'bds-shared-archive-plan-hosta-' . gmdate('Ymd-His');
    $hostPlan = $hostPlanner->planForIndustrySharedFolder('travel', $syncJobId);
    $hostPlanPath = $hostPlanner->writePlan($hostPlan, 'travel');

    $expectedPath = $projectRoot
        . DIRECTORY_SEPARATOR . 'var'
        . DIRECTORY_SEPARATOR . 'bds'
        . DIRECTORY_SEPARATOR . 'shared'
        . DIRECTORY_SEPARATOR . 'travel'
        . DIRECTORY_SEPARATOR . 'meta'
        . DIRECTORY_SEPARATOR . 'archive_promote_plan.json';

    test_assert($hostPlanPath === $expectedPath, 'Host A plan path matches SSOT');
    test_assert(is_file($hostPlanPath), 'Host A archive_promote_plan.json exists');
    test_assert(($hostPlan['industry_code'] ?? '') === 'travel', 'Host A industry_code travel');
    test_assert(($hostPlan['folder_id'] ?? '') === TRAVEL_SHARED_FOLDER, 'Host A folder_id');
    test_assert(($hostPlan['policy_enabled'] ?? true) === false, 'Host A policy_enabled false');
    test_assert(($hostPlan['folder_status'] ?? '') === BdsDriveFolderScanner::STATUS_OK, 'Host A folder_status ok');

    $hostDecoded = json_decode((string) file_get_contents($hostPlanPath), true);
    test_assert(is_array($hostDecoded), 'Host A plan JSON parseable');
    test_assert(
        ($hostDecoded['summary']['total_files'] ?? -1) === count($hostDecoded['items'] ?? []),
        'Host A summary total_files matches items'
    );
    test_assert(($hostDecoded['summary']['total_files'] ?? 0) >= 1, 'Host A has scanned children');

    foreach ($hostDecoded['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        test_assert(
            strpos((string) ($item['source_path'] ?? ''), 'industries/travel/shared/02_Shared_Layer/') !== false,
            'Host A item logical source_path prefix'
        );
        test_assert(
            ($item['status'] ?? '') === BdsSharedArchivePromotePlanner::STATUS_SKIP,
            'Host A policy OFF all items SKIP'
        );
        test_assert(
            ($item['reason'] ?? '') === BdsSharedArchivePromotePlanner::REASON_POLICY_DISABLED,
            'Host A policy OFF reason POLICY_DISABLED'
        );
        test_assert(
            ($item['owner_scope'] ?? '') === BdsDriveFileClassifier::OWNER_INDUSTRY,
            'Host A item owner_scope industry'
        );
        test_assert($item['planned_object_path'] === null, 'Host A policy OFF no planned_object_path');
    }

    test_assert(
        ($hostDecoded['summary']['skip_count'] ?? -1) === ($hostDecoded['summary']['total_files'] ?? 0),
        'Host A all files skipped'
    );
    test_assert(($hostDecoded['summary']['promote_count'] ?? -1) === 0, 'Host A promote_count zero');

    $hostArchiveDir = dirname(dirname(dirname($hostPlanPath))) . DIRECTORY_SEPARATOR . 'archive';
    test_assert(!is_dir($hostArchiveDir), 'Host A no GCS archive directory');

    fwrite(STDOUT, "HOST_A plan: {$hostPlanPath}\n");
    fwrite(STDOUT, sprintf(
        "HOST_A promote=%d skip=%d fail=%d total=%d\n",
        (int) ($hostDecoded['summary']['promote_count'] ?? 0),
        (int) ($hostDecoded['summary']['skip_count'] ?? 0),
        (int) ($hostDecoded['summary']['fail_count'] ?? 0),
        (int) ($hostDecoded['summary']['total_files'] ?? 0)
    ));

    // --- Host A policy ON temp config (dry-run only) ---
    $policyOnConfig = <<<'PHP'
<?php
declare(strict_types=1);
return [
    'schema_version' => 'bds_shared_archive_policy.v1',
    'defaults' => ['promote_requires_explicit_enable' => true],
    'industries' => [
        'travel' => ['archive_promote_enabled' => true, 'maintainer_role' => 'industry_maintainer'],
    ],
    'global' => ['archive_promote_enabled' => false, 'maintainer_role' => 'platform_admin'],
];
PHP;

    with_temp_shared_archive_policy_config($policyOnConfig, static function () use ($credentialsPath, $projectRoot, $hostPlanner): void {
        global $failures;

        test_assert(BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled('travel') === true, 'temp policy travel ON');

        $syncJobIdOn = 'bds-shared-archive-plan-hosta-on-' . gmdate('Ymd-His');
        $onPlanner = new BdsSharedArchivePromotePlanner(
            new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath)),
            null,
            null,
            $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds'
        );
        $onPlan = $onPlanner->planForIndustrySharedFolder('travel', $syncJobIdOn);

        test_assert(($onPlan['policy_enabled'] ?? false) === true, 'Host A policy ON plan policy_enabled true');

        $binaryPromoteCount = 0;
        foreach ($onPlan['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mime = (string) ($item['mime_type'] ?? '');
            if (strpos($mime, 'application/vnd.google-apps.') === 0) {
                test_assert(
                    ($item['status'] ?? '') === BdsSharedArchivePromotePlanner::STATUS_SKIP,
                    'Host A policy ON google workspace SKIP'
                );
                continue;
            }
            if (($item['status'] ?? '') === BdsSharedArchivePromotePlanner::STATUS_PROMOTE) {
                ++$binaryPromoteCount;
                test_assert(
                    strpos((string) ($item['planned_object_path'] ?? ''), 'shared/travel/archive/shared_knowledge/') === 0,
                    'Host A policy ON binary planned_object_path prefix'
                );
                test_assert(is_array($item['read_back_plan'] ?? null), 'Host A policy ON read_back_plan present');
            }
        }

        test_assert($binaryPromoteCount >= 1, 'Host A policy ON at least one binary PROMOTE');

        $onArchiveDir = $projectRoot
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'bds'
            . DIRECTORY_SEPARATOR . 'shared'
            . DIRECTORY_SEPARATOR . 'travel'
            . DIRECTORY_SEPARATOR . 'archive';
        test_assert(!is_dir($onArchiveDir), 'Host A policy ON no archive directory created');

        fwrite(STDOUT, sprintf(
            "HOST_A_POLICY_ON promote=%d skip=%d fail=%d\n",
            (int) ($onPlan['summary']['promote_count'] ?? 0),
            (int) ($onPlan['summary']['skip_count'] ?? 0),
            (int) ($onPlan['summary']['fail_count'] ?? 0)
        ));
    });
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_shared_archive_promote_planner (all passed)\n");
exit(0);
