<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSharedArchivePromoter.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSharedArchiveReadBackVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSharedArchivePolicyLoader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsDriveClient.php';

$failures = 0;

const TRAVEL_SHARED_FOLDER = '1i-yIs1H4pJsyOXyCO7eRLPh3eTbmSy7T';
const PILOT_INDUSTRY = 'travel';

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

function shared_archive_promote_env_ready(): bool
{
    $gate = BdsSharedArchivePromoter::evaluatePromoteEnvironmentGate();

    return ($gate['open'] ?? false) === true;
}

reset_shared_archive_policy_cache();

// --- Unit: environment gate shape ---
$gateShape = BdsSharedArchivePromoter::evaluatePromoteEnvironmentGate();
test_assert(isset($gateShape['open'], $gateShape['industry_shared_archive_enabled'], $gateShape['bucket']), 'environment gate fields');

// --- Unit: read-back verifier ---
$verifier = new BdsSharedArchiveReadBackVerifier();
$sampleBytes = '%PDF-1.4 sample';
$checksum = $verifier->computeSha256Content($sampleBytes);
test_assert(strpos($checksum, 'sha256:') === 0, 'sha256 prefix');
test_assert(strlen($checksum) === 7 + 64, 'sha256 length');

$rb = $verifier->verify(
    'shared/travel/archive/shared_knowledge/file001/brochure.pdf',
    $checksum,
    'file001',
    PILOT_INDUSTRY,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    $sampleBytes,
    [
        'content_checksum' => $checksum,
        'drive_file_id' => 'file001',
        'industry_code' => PILOT_INDUSTRY,
        'owner_scope' => BdsDriveFileClassifier::OWNER_INDUSTRY,
    ],
    200
);
test_assert($rb['ok'] === true, 'read-back verifier pass');
test_assert(count($rb['checks']) >= 9, 'read-back check count');

$rbFail = $verifier->verify(
    'tenants/other/archive/shared_knowledge/file001/brochure.pdf',
    $checksum,
    'file001',
    PILOT_INDUSTRY,
    BdsDriveFileClassifier::OWNER_INDUSTRY,
    $sampleBytes,
    [
        'content_checksum' => $checksum,
        'drive_file_id' => 'file001',
        'industry_code' => PILOT_INDUSTRY,
        'owner_scope' => BdsDriveFileClassifier::OWNER_INDUSTRY,
    ],
    200
);
test_assert($rbFail['ok'] === false, 'read-back tenant path fail');

// --- Unit: gate evaluation without GCS ---
$builder = new BdsDriveMetadataEnvelopeBuilder();
$validator = new BdsDriveMetadataValidator();
$promoter = new BdsSharedArchivePromoter();

$sheetEnvelope = $builder->buildFromDriveFile([
    'id' => 'sheet_promote_001',
    'name' => '基本資料',
    'mimeType' => 'application/vnd.google-apps.spreadsheet',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
], $builder->buildScanContextForIndustry(PILOT_INDUSTRY), 'bds-shared-archive-promote-unit-001');

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

with_temp_shared_archive_policy_config($policyOnConfig, static function () use ($promoter, $sheetEnvelope, $validator): void {
    global $failures;

    $sheetItem = $promoter->promoteFile(
        $sheetEnvelope,
        $validator->validate($sheetEnvelope),
        PILOT_INDUSTRY,
        TRAVEL_SHARED_FOLDER,
        'bds-shared-archive-promote-unit-001'
    );
    test_assert($sheetItem['status'] === BdsSharedArchivePromoter::STATUS_SKIP, 'google sheet SKIP');
    test_assert(($sheetItem['reason'] ?? '') === BdsSharedArchivePromoter::WARNING_GOOGLE_WORKSPACE, 'google sheet skip reason');
});

$pdfEnvelope = $builder->buildFromDriveFile([
    'id' => 'pdf_promote_unit',
    'name' => 'brochure.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'size' => 128,
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], $builder->buildScanContextForIndustry(PILOT_INDUSTRY), 'bds-shared-archive-promote-unit-001');

$prevDryRun = getenv('BDS_DRY_RUN');
putenv('BDS_DRY_RUN=true');
$pdfItemBlocked = $promoter->promoteFile(
    $pdfEnvelope,
    $validator->validate($pdfEnvelope),
    PILOT_INDUSTRY,
    TRAVEL_SHARED_FOLDER,
    'bds-shared-archive-promote-unit-001'
);
if ($prevDryRun === false) {
    putenv('BDS_DRY_RUN');
} elseif (is_string($prevDryRun)) {
    putenv('BDS_DRY_RUN=' . $prevDryRun);
}
test_assert($pdfItemBlocked['status'] === BdsSharedArchivePromoter::STATUS_SKIP, 'pdf blocked when dry_run');
test_assert(($pdfItemBlocked['gate'] ?? '') === 'environment', 'pdf environment gate');

$tenantEnvelope = $builder->buildFromTenantKey('travel_b', [
    'id' => 'tenant_scope_001',
    'name' => 'private.pdf',
    'mimeType' => 'application/pdf',
    'createdTime' => '2026-01-10T08:00:00.000Z',
    'modifiedTime' => '2026-06-01T12:30:00.000Z',
    'md5Checksum' => 'abc123def456abc123def456abc12345',
], 'bds-shared-archive-promote-unit-001');

with_temp_shared_archive_policy_config($policyOnConfig, static function () use ($promoter, $tenantEnvelope, $validator): void {
    global $failures;

    $scopeItem = $promoter->promoteFile(
        $tenantEnvelope,
        $validator->validate($tenantEnvelope),
        PILOT_INDUSTRY,
        TRAVEL_SHARED_FOLDER,
        'bds-shared-archive-promote-unit-001'
    );
    test_assert($scopeItem['status'] === BdsSharedArchivePromoter::STATUS_SKIP, 'tenant scope SKIP');
    test_assert(($scopeItem['gate'] ?? '') === 'scope', 'tenant scope gate');
});

$scopeItemPlaceholder = [
    'status' => BdsSharedArchivePromoter::STATUS_SKIP,
    'gate' => 'scope',
];

// --- Unit: report writer ---
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bds_shared_archive_promote_' . uniqid('', true);
$tempPromoter = new BdsSharedArchivePromoter(null, null, null, null, $tempRoot);
$report = $tempPromoter->buildReport([
    'sync_job_id' => 'unit-report',
    'industry_code' => PILOT_INDUSTRY,
    'folder_id' => TRAVEL_SHARED_FOLDER,
    'bucket' => BdsGcsUploader::DEFAULT_BUCKET,
    'policy_enabled' => true,
], [$scopeItemPlaceholder, $pdfItemBlocked]);
test_assert($report['schema_version'] === 'bds_shared_archive_promote_report.v1', 'report schema');
test_assert(($report['owner_scope'] ?? '') === BdsDriveFileClassifier::OWNER_INDUSTRY, 'report owner_scope');
$reportPath = $tempPromoter->writeReport($report, PILOT_INDUSTRY);
test_assert(is_file($reportPath), 'report written');
test_assert(strpos($reportPath, 'archive_promote_report.json') !== false, 'report filename');
test_assert(
    $reportPath === $tempRoot . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . PILOT_INDUSTRY
        . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . 'archive_promote_report.json',
    'report path SSOT'
);
remove_directory_recursive($tempRoot);

// --- Host A live promote ---
$credentialsPath = resolve_credentials_path_for_test();
if ($credentialsPath === null) {
    fwrite(STDERR, "SKIP: Host A shared archive promoter live test (no credentials)\n");
} elseif (!shared_archive_promote_env_ready()) {
    fwrite(STDERR, "SKIP: Host A shared archive promoter live test (environment gate not open; set BDS_DRY_RUN=false BDS_GCS_WRITE_ENABLED=true BDS_ARCHIVE_PROMOTE_ENABLED=true BDS_INDUSTRY_SHARED_ARCHIVE_ENABLED=true BDS_GCS_BUCKET=bbc-ai-saas-data)\n");
} else {
    $projectRoot = dirname(__DIR__, 2);
    $policyOnConfigHost = <<<'PHP'
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

    with_temp_shared_archive_policy_config($policyOnConfigHost, static function () use ($credentialsPath, $projectRoot): void {
        global $failures;

        test_assert(BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled(PILOT_INDUSTRY) === true, 'Host A temp policy ON');

        $hostPromoter = new BdsSharedArchivePromoter(
            new BdsDriveFolderScanner(new BdsDriveClient($credentialsPath)),
            null,
            null,
            null,
            $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds',
            BdsGcsUploader::DEFAULT_BUCKET,
            $credentialsPath
        );

        $syncJobId = 'bds-shared-archive-promote-hosta-' . gmdate('Ymd-His');
        $hostReport = $hostPromoter->promoteForIndustrySharedFolder(PILOT_INDUSTRY, $syncJobId);

        test_assert(($hostReport['industry_code'] ?? '') === PILOT_INDUSTRY, 'Host A industry_code');
        test_assert(($hostReport['folder_id'] ?? '') === TRAVEL_SHARED_FOLDER, 'Host A folder_id');
        test_assert(($hostReport['policy_enabled'] ?? false) === true, 'Host A policy_enabled');
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

            if (strpos($mime, 'application/vnd.google-apps.') === 0 || strpos($mime, 'google-apps') !== false) {
                test_assert($status === BdsSharedArchivePromoter::STATUS_SKIP, 'Host A google-apps SKIP');
                test_assert(
                    ($item['reason'] ?? '') === BdsSharedArchivePromoter::WARNING_GOOGLE_WORKSPACE,
                    'Host A google-apps skip reason'
                );
                $hasGoogleSkip = true;
            }

            $isBinaryCandidate = $mime === 'application/pdf'
                || strpos($mime, 'image/') === 0
                || strpos($mime, 'application/vnd.openxmlformats-officedocument') === 0
                || $mime === 'application/zip';

            if ($isBinaryCandidate) {
                if ($status === BdsSharedArchivePromoter::STATUS_PROMOTED) {
                    $hasBinaryPromoted = true;
                    $path = (string) ($item['gcs_object_path'] ?? '');
                    test_assert(strpos($path, 'shared/travel/archive/shared_knowledge/') === 0, 'Host A promoted path prefix');
                    test_assert(strpos($path, 'tenants/') !== 0, 'Host A not tenant path');
                    test_assert(strpos($path, '/knowledge/') === false, 'Host A not knowledge path');
                    test_assert(
                        ($item['checksum_method'] ?? '') === BdsSharedArchiveReadBackVerifier::CHECKSUM_METHOD,
                        'Host A sha256_content method'
                    );
                    test_assert(is_array($item['read_back'] ?? null) && ($item['read_back']['ok'] ?? false) === true, 'Host A read-back PASS');
                    $promotedPaths[] = $path;
                } elseif ($status === BdsSharedArchivePromoter::STATUS_SKIP && ($item['reason'] ?? '') === 'idempotent_already_promoted') {
                    $hasBinaryPromoted = true;
                    $path = (string) ($item['gcs_object_path'] ?? '');
                    test_assert(strpos($path, 'shared/travel/archive/shared_knowledge/') === 0, 'Host A idempotent path prefix');
                    test_assert(is_array($item['read_back'] ?? null) && ($item['read_back']['ok'] ?? false) === true, 'Host A idempotent read-back PASS');
                    $promotedPaths[] = $path;
                }
            }
        }

        $binaryCandidates = 0;
        foreach ($hostReport['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mime = (string) ($item['mime_type'] ?? '');
            if ($mime === 'application/pdf' || strpos($mime, 'image/') === 0) {
                ++$binaryCandidates;
            }
        }

        if ($binaryCandidates === 0) {
            fwrite(STDERR, "NOTICE: Host A travel shared folder has no PDF/image binary files; promote not executed\n");
        } else {
            test_assert($hasBinaryPromoted, 'Host A has binary PDF/image PROMOTED or idempotent');
        }

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
    });
}

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bds_shared_archive_promoter (all passed)\n");
exit(0);
