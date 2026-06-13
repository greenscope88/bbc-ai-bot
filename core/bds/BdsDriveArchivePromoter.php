<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveMetadataValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFileClassifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveArchivePromotePlanner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveArchiveReadBackVerifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

/**
 * BDS Phase 6D-1b — Drive Archive Promoter (tenant pilot).
 *
 * Drive binary → download → sha256_content → GCS archive upload → read-back.
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §8.1、§17
 */
final class BdsDriveArchivePromoter
{
    public const SCHEMA_VERSION = 'bds_archive_promote_report.v1';

    public const REPORT_FILENAME = 'archive_promote_report.json';

    public const STATUS_PROMOTED = 'PROMOTED';

    public const STATUS_SKIP = 'SKIP';

    public const STATUS_FAIL = 'FAIL';

    public const PILOT_TENANT_KEY = 'travel_b';

    public const PILOT_TENANT_SNO = '5f99b8d665e8444d';

    public const WARNING_GOOGLE_WORKSPACE = 'W_GOOGLE_WORKSPACE_EXPORT_REQUIRED';

    public const CHECKSUM_METHOD = 'sha256_content';

    public const STORAGE_SCOPE = 'https://www.googleapis.com/auth/devstorage.read_write';

    /** @var list<string> */
    private const ALLOWED_DATA_CATEGORIES = [
        BdsDriveFileClassifier::CATEGORY_ITINERARY,
        BdsDriveFileClassifier::CATEGORY_ARCHIVE,
    ];

    /** @var BdsDriveFolderScanner */
    private $scanner;

    /** @var BdsDriveMetadataEnvelopeBuilder */
    private $envelopeBuilder;

    /** @var BdsDriveMetadataValidator */
    private $metadataValidator;

    /** @var BdsDriveArchiveReadBackVerifier */
    private $readBackVerifier;

    /** @var BdsDriveArchivePromotePlanner */
    private $pathPlanner;

    /** @var string */
    private $reportRoot;

    /** @var string */
    private $bucket;

    /** @var string */
    private $credentialsPath;

    public function __construct(
        ?BdsDriveFolderScanner $scanner = null,
        ?BdsDriveMetadataEnvelopeBuilder $envelopeBuilder = null,
        ?BdsDriveMetadataValidator $metadataValidator = null,
        ?BdsDriveArchiveReadBackVerifier $readBackVerifier = null,
        ?string $reportRoot = null,
        ?string $bucket = null,
        ?string $credentialsPath = null
    ) {
        $this->scanner = $scanner ?? new BdsDriveFolderScanner();
        $this->envelopeBuilder = $envelopeBuilder ?? new BdsDriveMetadataEnvelopeBuilder();
        $this->metadataValidator = $metadataValidator ?? new BdsDriveMetadataValidator();
        $this->readBackVerifier = $readBackVerifier ?? new BdsDriveArchiveReadBackVerifier();
        $this->pathPlanner = new BdsDriveArchivePromotePlanner();
        $this->reportRoot = $reportRoot !== null
            ? rtrim($reportRoot, '/\\')
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds';
        $this->bucket = $this->resolveBucket($bucket);
        $this->credentialsPath = $this->resolveCredentialsPath($credentialsPath);
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function resolveReportPath(string $tenantSno): string
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            throw new \InvalidArgumentException('tenant_sno is required.');
        }

        return $this->reportRoot
            . DIRECTORY_SEPARATOR . 'tenants'
            . DIRECTORY_SEPARATOR . $tenantSno
            . DIRECTORY_SEPARATOR . 'meta'
            . DIRECTORY_SEPARATOR . self::REPORT_FILENAME;
    }

    /**
     * @return array<string, mixed>
     */
    public static function evaluatePromoteEnvironmentGate(): array
    {
        $dryRunRaw = self::envString('BDS_DRY_RUN');
        $gcsWriteRaw = self::envString('BDS_GCS_WRITE_ENABLED');
        $archivePromoteRaw = self::envString('BDS_ARCHIVE_PROMOTE_ENABLED');
        $targetSno = self::envString('BDS_TARGET_SNO');
        $bucket = self::envString('BDS_GCS_BUCKET');

        $dryRun = !self::isEnvFalse($dryRunRaw);
        $gcsWriteEnabled = self::isEnvTrue($gcsWriteRaw);
        $archivePromoteEnabled = self::isEnvTrue($archivePromoteRaw);

        if ($dryRun) {
            return self::environmentGateResult(false, 'dry_run_not_false', $dryRun, $gcsWriteEnabled, $archivePromoteEnabled, $targetSno, $bucket);
        }

        if (!$gcsWriteEnabled) {
            return self::environmentGateResult(false, 'gcs_write_not_enabled', $dryRun, $gcsWriteEnabled, $archivePromoteEnabled, $targetSno, $bucket);
        }

        if (!$archivePromoteEnabled) {
            return self::environmentGateResult(false, 'archive_promote_not_enabled', $dryRun, $gcsWriteEnabled, $archivePromoteEnabled, $targetSno, $bucket);
        }

        if ($targetSno === '') {
            return self::environmentGateResult(false, 'target_sno_missing', $dryRun, $gcsWriteEnabled, $archivePromoteEnabled, $targetSno, $bucket);
        }

        if ($targetSno !== self::PILOT_TENANT_SNO) {
            return self::environmentGateResult(false, 'target_sno_not_pilot', $dryRun, $gcsWriteEnabled, $archivePromoteEnabled, $targetSno, $bucket);
        }

        if ($bucket === '') {
            $bucket = BdsGcsUploader::DEFAULT_BUCKET;
        }

        return self::environmentGateResult(true, 'gate_open', $dryRun, $gcsWriteEnabled, $archivePromoteEnabled, $targetSno, $bucket);
    }

    /**
     * @return array<string, mixed>
     */
    public function promoteForTenantFolder(string $tenantKey, string $folderId, string $syncJobId): array
    {
        $tenantKey = trim($tenantKey);
        $folderId = trim($folderId);
        $syncJobId = trim($syncJobId);

        if ($tenantKey === '' || $folderId === '' || $syncJobId === '') {
            throw new \InvalidArgumentException('tenant_key, folder_id, and sync_job_id are required.');
        }

        $tenantEntry = BdsSourceRegistryLoader::loadByTenantKey($tenantKey);
        if ($tenantEntry === null) {
            throw new \InvalidArgumentException('Tenant registry entry not found: ' . $tenantKey);
        }

        $tenantSno = trim((string) ($tenantEntry['sno'] ?? ''));
        $registryFolderId = trim((string) ($tenantEntry['private_knowledge_folder_id'] ?? ''));
        if ($registryFolderId !== '' && $registryFolderId !== $folderId) {
            throw new \InvalidArgumentException('folder_id does not match Tenant Registry private_knowledge_folder_id.');
        }

        $children = $this->scanner->scanChildren($folderId);
        $items = [];

        foreach ($children['files'] as $child) {
            if (!is_array($child)) {
                continue;
            }

            $fileId = trim((string) ($child['id'] ?? ''));
            if ($fileId === '') {
                continue;
            }

            $driveFile = $this->fetchDriveFileMetadata($fileId);
            if ($driveFile === null) {
                $items[] = $this->buildFailedItem($fileId, (string) ($child['name'] ?? ''), 'metadata', 'drive_metadata_fetch_failed');
                continue;
            }

            $envelope = $this->envelopeBuilder->buildFromTenantKey($tenantKey, $driveFile, $syncJobId);
            $validation = $this->metadataValidator->validate($envelope);
            $items[] = $this->promoteFile($envelope, $validation, $tenantKey, $folderId, $syncJobId);
        }

        $report = $this->buildReport([
            'sync_job_id' => $syncJobId,
            'tenant_key' => $tenantKey,
            'tenant_sno' => $tenantSno,
            'industry_code' => (string) ($tenantEntry['industry_code'] ?? ''),
            'folder_id' => $folderId,
            'bucket' => $this->bucket,
        ], $items);

        $report['report_path'] = $this->writeReport($report, $tenantSno);

        return $report;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    public function promoteFile(
        array $envelope,
        array $validation,
        string $expectedTenantKey,
        string $expectedFolderId,
        string $syncJobId
    ): array {
        $fileId = (string) ($envelope['file_id'] ?? '');
        $fileName = (string) ($envelope['file_name'] ?? '');
        $mimeType = (string) ($envelope['mime_type'] ?? '');
        $tenantSno = (string) ($envelope['tenant_sno'] ?? '');
        $dataCategory = (string) ($envelope['data_category'] ?? '');

        $item = $this->buildItemSkeleton($envelope);

        $gate0 = self::evaluatePromoteEnvironmentGate();
        $item['gate_results']['gate_0_environment'] = [
            'passed' => $gate0['open'] === true,
            'reason' => (string) ($gate0['reason'] ?? ''),
        ];
        if ($gate0['open'] !== true) {
            return $this->finalizeItem($item, self::STATUS_SKIP, 'environment', (string) $gate0['reason']);
        }

        $gate1 = $this->evaluateGate1Scope($envelope, $expectedTenantKey, $expectedFolderId);
        $item['gate_results']['gate_1_scope'] = $gate1;
        if (!$gate1['passed']) {
            return $this->finalizeItem($item, self::STATUS_SKIP, 'scope', (string) $gate1['reason']);
        }

        $gate2 = $this->evaluateGate2Metadata($validation);
        $item['gate_results']['gate_2_metadata'] = $gate2;
        if (!$gate2['passed']) {
            return $this->finalizeItem(
                $item,
                self::STATUS_FAIL,
                'metadata',
                (string) $gate2['reason'],
                is_array($gate2['errors'] ?? null) ? $gate2['errors'] : []
            );
        }

        $gate3 = $this->evaluateGate3Category($envelope);
        $item['gate_results']['gate_3_category'] = $gate3;
        if (!$gate3['passed']) {
            return $this->finalizeItem($item, self::STATUS_SKIP, 'category', (string) $gate3['reason']);
        }

        $gate4 = $this->evaluateGate4ContentType($envelope);
        $item['gate_results']['gate_4_content_type'] = $gate4;
        if (!$gate4['passed']) {
            if (($gate4['reason'] ?? '') === self::WARNING_GOOGLE_WORKSPACE) {
                $item['warnings'][] = self::WARNING_GOOGLE_WORKSPACE;
            }

            return $this->finalizeItem($item, self::STATUS_SKIP, 'content_type', (string) $gate4['reason']);
        }

        $objectPath = $this->pathPlanner->buildPlannedObjectPath($tenantSno, $dataCategory, $fileId, $fileName);
        $item['planned_object_path'] = $objectPath;
        $item['gcs_object_path'] = $objectPath;

        $gate6 = $this->evaluateGate6PathSafety($envelope, $objectPath);
        $item['gate_results']['gate_6_path_safety'] = $gate6;
        if (!$gate6['passed']) {
            return $this->finalizeItem($item, self::STATUS_FAIL, 'path_safety', (string) $gate6['reason']);
        }

        try {
            $bytes = $this->scanner->getDriveClient()->downloadFileBytes($fileId);
        } catch (\Throwable $e) {
            return $this->finalizeItem($item, self::STATUS_FAIL, 'checksum', 'drive_download_failed');
        }

        if ($bytes === '') {
            return $this->finalizeItem($item, self::STATUS_FAIL, 'checksum', 'drive_download_empty');
        }

        $contentChecksum = $this->readBackVerifier->computeSha256Content($bytes);
        $item['content_checksum'] = $contentChecksum;
        $item['checksum_method'] = self::CHECKSUM_METHOD;

        $gate5 = $this->evaluateGate5Checksum($contentChecksum);
        $item['gate_results']['gate_5_checksum'] = $gate5;
        if (!$gate5['passed']) {
            return $this->finalizeItem($item, self::STATUS_FAIL, 'checksum', (string) $gate5['reason']);
        }

        $accessToken = $this->fetchAccessToken();
        $existingMeta = $this->getObjectMetadata($accessToken, $objectPath);
        if ($existingMeta['status'] === 200 && is_array($existingMeta['metadata'])) {
            $existingChecksum = (string) ($existingMeta['metadata']['content_checksum'] ?? '');
            if ($existingChecksum === $contentChecksum) {
                $item['gate_results']['gate_5_checksum']['idempotent'] = true;
                $readBack = $this->performReadBack(
                    $accessToken,
                    $objectPath,
                    $contentChecksum,
                    $fileId,
                    $tenantSno,
                    $bytes
                );
                $item['read_back'] = $readBack;

                return $this->finalizeItem($item, self::STATUS_SKIP, 'checksum', 'idempotent_already_promoted');
            }

            if ($existingChecksum !== '') {
                return $this->finalizeItem($item, self::STATUS_FAIL, 'checksum', 'checksum_conflict');
            }
        }

        $uploadMeta = $this->buildGcsObjectMetadata($envelope, $syncJobId, $contentChecksum);
        $uploadResp = $this->uploadArchiveObject(
            $accessToken,
            $objectPath,
            $bytes,
            $mimeType !== '' ? $mimeType : 'application/octet-stream',
            $uploadMeta
        );

        if ($uploadResp['status'] < 200 || $uploadResp['status'] >= 300) {
            $item['gate_results']['gate_7_failure_handling'] = [
                'passed' => false,
                'reason' => 'gcs_upload_failed',
                'http_status' => $uploadResp['status'],
            ];

            return $this->finalizeItem($item, self::STATUS_FAIL, 'read_back', 'gcs_upload_failed');
        }

        $readBack = $this->performReadBack($accessToken, $objectPath, $contentChecksum, $fileId, $tenantSno, $bytes);
        $item['read_back'] = $readBack;
        $item['gs_uri'] = 'gs://' . $this->bucket . '/' . $objectPath;
        $item['gate_results']['gate_7_failure_handling'] = [
            'passed' => $readBack['ok'] === true,
            'reason' => $readBack['ok'] === true ? 'read_back_pass' : 'read_back_failed',
        ];

        if ($readBack['ok'] !== true) {
            return $this->finalizeItem($item, self::STATUS_FAIL, 'read_back', 'read_back_verification_failed');
        }

        $item['promoted_at'] = gmdate('Y-m-d\TH:i:s\Z');

        return $this->finalizeItem($item, self::STATUS_PROMOTED, null, null);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function buildReport(array $context, array $items): array
    {
        $promoted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === self::STATUS_PROMOTED) {
                ++$promoted;
            } elseif ($status === self::STATUS_SKIP) {
                ++$skipped;
            } elseif ($status === self::STATUS_FAIL) {
                ++$failed;
            }
        }

        $total = count($items);
        $status = 'success';
        if ($failed > 0 && ($promoted > 0 || $skipped > 0)) {
            $status = 'partial';
        } elseif ($failed > 0) {
            $status = 'failed';
        } elseif ($promoted === 0 && $skipped > 0) {
            $status = 'skipped';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sync_job_id' => (string) ($context['sync_job_id'] ?? ''),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => 'archive_promote',
            'scan_scope' => 'tenant_private',
            'tenant_key' => (string) ($context['tenant_key'] ?? ''),
            'tenant_sno' => (string) ($context['tenant_sno'] ?? ''),
            'industry_code' => (string) ($context['industry_code'] ?? ''),
            'folder_id' => (string) ($context['folder_id'] ?? ''),
            'owner_scope' => BdsDriveFileClassifier::OWNER_TENANT,
            'bucket' => (string) ($context['bucket'] ?? $this->bucket),
            'status' => $status,
            'environment' => self::evaluatePromoteEnvironmentGate(),
            'summary' => [
                'total_files' => $total,
                'promoted_count' => $promoted,
                'skip_count' => $skipped,
                'fail_count' => $failed,
            ],
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public function writeReport(array $report, string $tenantSno): string
    {
        $reportPath = $this->resolveReportPath($tenantSno);
        $metaDir = dirname($reportPath);

        if (!is_dir($metaDir) && !mkdir($metaDir, 0775, true) && !is_dir($metaDir)) {
            throw new \RuntimeException('Failed to create meta directory: ' . $metaDir);
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode archive_promote_report.json.');
        }

        $json .= "\n";

        if (file_put_contents($reportPath, $json) === false) {
            throw new \RuntimeException('Failed to write archive_promote_report.json: ' . $reportPath);
        }

        return $reportPath;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function buildItemSkeleton(array $envelope): array
    {
        return [
            'drive_file_id' => (string) ($envelope['file_id'] ?? ''),
            'file_name' => (string) ($envelope['file_name'] ?? ''),
            'mime_type' => (string) ($envelope['mime_type'] ?? ''),
            'source_path' => (string) ($envelope['source_path'] ?? ''),
            'owner_scope' => (string) ($envelope['owner_scope'] ?? ''),
            'data_category' => (string) ($envelope['data_category'] ?? ''),
            'checksum_method' => null,
            'content_checksum' => null,
            'planned_object_path' => null,
            'gcs_object_path' => null,
            'gs_uri' => null,
            'read_back' => null,
            'warnings' => [],
            'gate_results' => [],
            'promoted_at' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @return array<string, mixed>
     */
    private function finalizeItem(
        array $item,
        string $status,
        ?string $gate,
        ?string $reason,
        array $errors = []
    ): array {
        $item['status'] = $status;
        $item['gate'] = $gate;
        $item['reason'] = $reason;
        if ($errors !== []) {
            $item['errors'] = $errors;
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFailedItem(string $fileId, string $fileName, string $gate, string $reason): array
    {
        return [
            'drive_file_id' => $fileId,
            'file_name' => $fileName,
            'mime_type' => '',
            'source_path' => '',
            'owner_scope' => '',
            'data_category' => '',
            'status' => self::STATUS_FAIL,
            'gate' => $gate,
            'reason' => $reason,
            'gate_results' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate1Scope(array $envelope, string $expectedTenantKey, string $expectedFolderId): array
    {
        if (($envelope['owner_scope'] ?? '') !== BdsDriveFileClassifier::OWNER_TENANT) {
            return ['passed' => false, 'reason' => 'owner_scope_not_tenant'];
        }

        if (trim((string) ($envelope['tenant_key'] ?? '')) !== trim($expectedTenantKey)) {
            return ['passed' => false, 'reason' => 'tenant_key_mismatch'];
        }

        if ((string) ($envelope['tenant_key'] ?? '') !== self::PILOT_TENANT_KEY) {
            return ['passed' => false, 'reason' => 'pilot_tenant_only_travel_b'];
        }

        if ((string) ($envelope['tenant_sno'] ?? '') !== self::PILOT_TENANT_SNO) {
            return ['passed' => false, 'reason' => 'pilot_sno_mismatch'];
        }

        require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';
        $entry = BdsSourceRegistryLoader::loadByTenantKey($expectedTenantKey);
        if ($entry === null) {
            return ['passed' => false, 'reason' => 'registry_not_found'];
        }

        $registryFolderId = trim((string) ($entry['private_knowledge_folder_id'] ?? ''));
        if ($registryFolderId !== '' && $registryFolderId !== trim($expectedFolderId)) {
            return ['passed' => false, 'reason' => 'drive_root_mismatch'];
        }

        return ['passed' => true, 'reason' => 'tenant_private_scope_ok'];
    }

    /**
     * @param array<string, mixed> $validation
     * @return array{passed: bool, reason: string, errors?: list<array<string, mixed>>}
     */
    private function evaluateGate2Metadata(array $validation): array
    {
        if (!empty($validation['ok'])) {
            return ['passed' => true, 'reason' => 'metadata_valid'];
        }

        $errors = [];
        if (isset($validation['errors']) && is_array($validation['errors'])) {
            foreach ($validation['errors'] as $error) {
                if (is_array($error)) {
                    $errors[] = $error;
                }
            }
        }

        return ['passed' => false, 'reason' => 'metadata_validation_failed', 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate3Category(array $envelope): array
    {
        $dataCategory = (string) ($envelope['data_category'] ?? '');

        if ($dataCategory === BdsDriveFileClassifier::CATEGORY_REGISTRATION) {
            return ['passed' => false, 'reason' => 'customer_registration_drive_only'];
        }

        if ($dataCategory === BdsDriveFileClassifier::CATEGORY_SHARED) {
            return ['passed' => false, 'reason' => 'shared_knowledge_deferred_6e'];
        }

        if ($dataCategory === 'tenant_private_knowledge') {
            return ['passed' => false, 'reason' => 'tenant_private_knowledge_not_in_6d1b_allow_list'];
        }

        if (!in_array($dataCategory, self::ALLOWED_DATA_CATEGORIES, true)) {
            return ['passed' => false, 'reason' => 'category_not_in_6d1b_allow_list'];
        }

        return ['passed' => true, 'reason' => 'category_allowed'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate4ContentType(array $envelope): array
    {
        $mimeType = strtolower(trim((string) ($envelope['mime_type'] ?? '')));

        if ($this->isGoogleWorkspaceNativeMime($mimeType)) {
            return ['passed' => false, 'reason' => self::WARNING_GOOGLE_WORKSPACE];
        }

        if ($this->isAllowedBinaryMime($mimeType, (string) ($envelope['file_name'] ?? ''))) {
            return ['passed' => true, 'reason' => 'binary_mime_allowed'];
        }

        return ['passed' => false, 'reason' => 'unknown_or_unsupported_mime'];
    }

    /**
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate5Checksum(string $contentChecksum): array
    {
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $contentChecksum)) {
            return ['passed' => false, 'reason' => 'sha256_content_invalid'];
        }

        return ['passed' => true, 'reason' => 'sha256_content_ok'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate6PathSafety(array $envelope, string $objectPath): array
    {
        $tenantSno = trim((string) ($envelope['tenant_sno'] ?? ''));
        $fileName = (string) ($envelope['file_name'] ?? '');

        if (!$this->isSafeFileName($fileName)) {
            return ['passed' => false, 'reason' => 'unsafe_file_name'];
        }

        $expectedPrefix = 'tenants/' . $tenantSno . '/archive/';
        if (strpos($objectPath, $expectedPrefix) !== 0) {
            return ['passed' => false, 'reason' => 'archive_prefix_required'];
        }

        if (preg_match('#(^|/)uploads(/|$)#i', $objectPath) === 1 || preg_match('#(^|/)raw_uploads(/|$)#i', $objectPath) === 1) {
            return ['passed' => false, 'reason' => 'forbidden_uploads_alias'];
        }

        if (strpos($objectPath, '/knowledge/') !== false) {
            return ['passed' => false, 'reason' => 'knowledge_path_forbidden'];
        }

        return ['passed' => true, 'reason' => 'path_safe'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, string>
     */
    private function buildGcsObjectMetadata(array $envelope, string $syncJobId, string $contentChecksum): array
    {
        return [
            'tenant_sno' => (string) ($envelope['tenant_sno'] ?? ''),
            'industry_code' => (string) ($envelope['industry_code'] ?? ''),
            'data_category' => (string) ($envelope['data_category'] ?? ''),
            'schema_version' => BdsDriveMetadataEnvelopeBuilder::SCHEMA_VERSION,
            'source_revision' => (string) ($envelope['modified_at'] ?? ''),
            'sync_id' => $syncJobId,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'bds_mode' => 'B',
            'drive_file_id' => (string) ($envelope['file_id'] ?? ''),
            'content_checksum' => $contentChecksum,
            'checksum_method' => self::CHECKSUM_METHOD,
            'drive_logical_path' => (string) ($envelope['source_path'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function performReadBack(
        string $accessToken,
        string $objectPath,
        string $expectedChecksum,
        string $driveFileId,
        string $tenantSno,
        string $sourceBytes
    ): array {
        $getResp = $this->getObjectBytes($accessToken, $objectPath);
        $metaResp = $this->getObjectMetadata($accessToken, $objectPath);
        $metadata = is_array($metaResp['metadata']) ? $metaResp['metadata'] : [];

        return $this->readBackVerifier->verify(
            $objectPath,
            $expectedChecksum,
            $driveFileId,
            $tenantSno,
            $getResp['status'] === 200 ? $getResp['body'] : $sourceBytes,
            $metadata,
            $getResp['status']
        );
    }

    /**
     * @return array{status: int, body: string}
     */
    private function uploadArchiveObject(
        string $accessToken,
        string $objectPath,
        string $bytes,
        string $contentType,
        array $customMetadata
    ): array {
        $boundary = 'bds_archive_' . bin2hex(random_bytes(8));
        $resource = [
            'name' => $objectPath,
            'contentType' => $contentType,
            'metadata' => $customMetadata,
        ];
        $resourceJson = json_encode($resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($resourceJson === false) {
            return ['status' => 0, 'body' => 'metadata_json_encode_failed'];
        }

        $multipartBody = '--' . $boundary . "\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $resourceJson . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $contentType . "\r\n\r\n"
            . $bytes . "\r\n"
            . '--' . $boundary . "--\r\n";

        $url = 'https://storage.googleapis.com/upload/storage/v1/b/' . rawurlencode($this->bucket)
            . '/o?uploadType=multipart';

        return $this->httpRequest('POST', $url, $accessToken, $multipartBody, [
            'Content-Type: multipart/related; boundary=' . $boundary,
        ]);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function getObjectBytes(string $accessToken, string $objectPath): array
    {
        $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($this->bucket)
            . '/o/' . $this->encodeObjectPath($objectPath) . '?alt=media';

        return $this->httpRequest('GET', $url, $accessToken);
    }

    /**
     * @return array{status: int, body: string, metadata: array<string, string>|null}
     */
    private function getObjectMetadata(string $accessToken, string $objectPath): array
    {
        $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($this->bucket)
            . '/o/' . $this->encodeObjectPath($objectPath) . '?fields=name,metadata';

        $resp = $this->httpRequest('GET', $url, $accessToken);
        $metadata = null;

        if ($resp['status'] === 200) {
            $json = json_decode($resp['body'], true);
            if (is_array($json) && isset($json['metadata']) && is_array($json['metadata'])) {
                $metadata = [];
                foreach ($json['metadata'] as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_numeric($value))) {
                        $metadata[$key] = (string) $value;
                    }
                }
            }
        }

        return [
            'status' => $resp['status'],
            'body' => $resp['body'],
            'metadata' => $metadata,
        ];
    }

    private function encodeObjectPath(string $objectPath): string
    {
        return rawurlencode($objectPath);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDriveFileMetadata(string $fileId): ?array
    {
        try {
            $file = $this->scanner->getDriveClient()->getDriveService()->files->get($fileId, [
                'fields' => 'id,name,mimeType,createdTime,modifiedTime,size,md5Checksum',
                'supportsAllDrives' => true,
            ]);
        } catch (\Throwable $e) {
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

    private function isGoogleWorkspaceNativeMime(string $mimeType): bool
    {
        $mimeType = strtolower(trim($mimeType));

        return strpos($mimeType, 'application/vnd.google-apps.') === 0
            || strpos($mimeType, 'google-apps.') === 0;
    }

    private function isAllowedBinaryMime(string $mimeType, string $fileName): bool
    {
        if ($this->isGoogleWorkspaceNativeMime($mimeType)) {
            return false;
        }

        if ($mimeType === 'application/pdf' || strpos($mimeType, 'image/') === 0) {
            return true;
        }

        $binaryMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
            'application/zip',
        ];

        if (in_array($mimeType, $binaryMimes, true)) {
            return true;
        }

        return false;
    }

    private function isSafeFileName(string $fileName): bool
    {
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return false;
        }

        if (strpos($fileName, '..') !== false) {
            return false;
        }

        if (preg_match('#[/\\\\]#', $fileName) === 1) {
            return false;
        }

        return preg_match('/[\x00-\x1f\x7f]/', $fileName) !== 1;
    }

    private function fetchAccessToken(): string
    {
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException('Composer autoload not found.');
        }
        require_once $autoload;

        if (!is_file($this->credentialsPath)) {
            throw new \RuntimeException('Google service account credentials file not found.');
        }

        $client = new \Google\Client();
        $client->setAuthConfig($this->credentialsPath);
        $client->setScopes([self::STORAGE_SCOPE]);
        $token = $client->fetchAccessTokenWithAssertion();

        if (isset($token['error']) || !isset($token['access_token'])) {
            $error = isset($token['error']) ? (string) $token['error'] : 'missing_access_token';
            throw new \RuntimeException('Failed to fetch GCS access token: ' . $error);
        }

        return (string) $token['access_token'];
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{status: int, body: string}
     */
    private function httpRequest(string $method, string $url, string $accessToken, ?string $body = null, array $extraHeaders = []): array
    {
        $headers = array_merge(['Authorization: Bearer ' . $accessToken], $extraHeaders);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function environmentGateResult(
        bool $open,
        string $reason,
        bool $dryRun,
        bool $gcsWriteEnabled,
        bool $archivePromoteEnabled,
        string $targetSno,
        string $bucket
    ): array {
        return [
            'open' => $open,
            'reason' => $reason,
            'dry_run' => $dryRun,
            'gcs_write_enabled' => $gcsWriteEnabled,
            'archive_promote_enabled' => $archivePromoteEnabled,
            'target_sno' => $targetSno,
            'bucket' => $bucket !== '' ? $bucket : BdsGcsUploader::DEFAULT_BUCKET,
        ];
    }

    private function resolveBucket(?string $bucket): string
    {
        $candidate = $bucket !== null ? trim($bucket) : self::envString('BDS_GCS_BUCKET');
        if ($candidate === '') {
            $candidate = BdsGcsUploader::DEFAULT_BUCKET;
        }

        return $candidate;
    }

    private function resolveCredentialsPath(?string $credentialsPath): string
    {
        if ($credentialsPath !== null && trim($credentialsPath) !== '') {
            return trim($credentialsPath);
        }

        foreach ([self::envString('BDS_GOOGLE_APPLICATION_CREDENTIALS'), self::envString('GOOGLE_APPLICATION_CREDENTIALS')] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        $secretsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secrets';
        if (is_dir($secretsDir)) {
            $matches = glob($secretsDir . DIRECTORY_SEPARATOR . '*.json');
            if (is_array($matches)) {
                sort($matches, SORT_STRING);
                foreach ($matches as $match) {
                    if (is_file($match)) {
                        return $match;
                    }
                }
            }
        }

        throw new \RuntimeException('Google service account credentials path is not configured.');
    }

    private static function envString(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private static function isEnvTrue(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function isEnvFalse(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
    }
}
