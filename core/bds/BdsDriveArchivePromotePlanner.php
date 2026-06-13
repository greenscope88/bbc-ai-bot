<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveMetadataValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFileClassifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsGcsUploader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

/**
 * BDS Phase 6D-1a — Archive Promote Planner (dry-run only).
 *
 * Drive → Metadata → Promote Gate → Archive Path → Read-back Plan.
 * Writes archive_promote_plan.json locally; no GCS upload.
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §8.1、§17
 * @see docs/BATS_DATA_SYNC_POLICY.md §11.6～§11.7
 */
final class BdsDriveArchivePromotePlanner
{
    public const SCHEMA_VERSION = 'bds_archive_promote_plan.v1';

    public const PLAN_FILENAME = 'archive_promote_plan.json';

    public const STATUS_PROMOTE = 'PROMOTE';

    public const STATUS_SKIP = 'SKIP';

    public const STATUS_FAIL = 'FAIL';

    public const PILOT_TENANT_KEY = 'travel_b';

    public const PILOT_TENANT_SNO = '5f99b8d665e8444d';

    public const WARNING_GOOGLE_WORKSPACE = 'W_GOOGLE_WORKSPACE_EXPORT_REQUIRED';

    public const MIME_GOOGLE_SPREADSHEET = 'google-apps.spreadsheet';

    public const MIME_GOOGLE_DOCUMENT = 'google-apps.document';

    public const MIME_GOOGLE_PRESENTATION = 'google-apps.presentation';

    public const MIME_VND_GOOGLE_SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    public const MIME_VND_GOOGLE_DOCUMENT = 'application/vnd.google-apps.document';

    public const MIME_VND_GOOGLE_PRESENTATION = 'application/vnd.google-apps.presentation';

    /** @var list<string> */
    public const GOOGLE_WORKSPACE_NATIVE_MIMES = [
        self::MIME_GOOGLE_SPREADSHEET,
        self::MIME_GOOGLE_DOCUMENT,
        self::MIME_GOOGLE_PRESENTATION,
        self::MIME_VND_GOOGLE_SPREADSHEET,
        self::MIME_VND_GOOGLE_DOCUMENT,
        self::MIME_VND_GOOGLE_PRESENTATION,
    ];

    /** @var list<string> */
    private const ALLOWED_DATA_CATEGORIES_6D1 = [
        BdsDriveFileClassifier::CATEGORY_ITINERARY,
        BdsDriveFileClassifier::CATEGORY_ARCHIVE,
        'tenant_private_knowledge',
    ];

    /** @var BdsDriveFolderScanner */
    private $scanner;

    /** @var BdsDriveMetadataEnvelopeBuilder */
    private $envelopeBuilder;

    /** @var BdsDriveMetadataValidator */
    private $metadataValidator;

    /** @var string */
    private $planRoot;

    public function __construct(
        ?BdsDriveFolderScanner $scanner = null,
        ?BdsDriveMetadataEnvelopeBuilder $envelopeBuilder = null,
        ?BdsDriveMetadataValidator $metadataValidator = null,
        ?string $planRoot = null
    ) {
        $this->scanner = $scanner ?? new BdsDriveFolderScanner();
        $this->envelopeBuilder = $envelopeBuilder ?? new BdsDriveMetadataEnvelopeBuilder();
        $this->metadataValidator = $metadataValidator ?? new BdsDriveMetadataValidator();
        $this->planRoot = $planRoot !== null
            ? rtrim($planRoot, '/\\')
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds';
    }

    public function getPlanRoot(): string
    {
        return $this->planRoot;
    }

    public function resolveTenantMetaDirectory(string $tenantSno): string
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            throw new \InvalidArgumentException('tenant_sno is required.');
        }

        return $this->planRoot
            . DIRECTORY_SEPARATOR . 'tenants'
            . DIRECTORY_SEPARATOR . $tenantSno
            . DIRECTORY_SEPARATOR . 'meta';
    }

    public function resolvePlanPath(string $tenantSno): string
    {
        return $this->resolveTenantMetaDirectory($tenantSno) . DIRECTORY_SEPARATOR . self::PLAN_FILENAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function planForTenantFolder(string $tenantKey, string $folderId, string $syncJobId): array
    {
        $tenantKey = trim($tenantKey);
        $folderId = trim($folderId);
        $syncJobId = trim($syncJobId);

        if ($tenantKey === '') {
            throw new \InvalidArgumentException('tenant_key is required.');
        }
        if ($folderId === '') {
            throw new \InvalidArgumentException('folder_id is required.');
        }
        if ($syncJobId === '') {
            throw new \InvalidArgumentException('sync_job_id is required.');
        }

        $tenantEntry = BdsSourceRegistryLoader::loadByTenantKey($tenantKey);
        if ($tenantEntry === null) {
            throw new \InvalidArgumentException('Tenant registry entry not found: ' . $tenantKey);
        }

        $tenantSno = trim((string) ($tenantEntry['sno'] ?? ''));
        if ($tenantSno === '') {
            throw new \InvalidArgumentException('Tenant registry entry missing sno: ' . $tenantKey);
        }

        $registryFolderId = trim((string) ($tenantEntry['private_knowledge_folder_id'] ?? ''));
        if ($registryFolderId !== '' && $registryFolderId !== $folderId) {
            throw new \InvalidArgumentException(
                'folder_id does not match Tenant Registry private_knowledge_folder_id.'
            );
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
                $items[] = $this->buildFailedFetchItem($fileId, (string) ($child['name'] ?? ''));
                continue;
            }

            $envelope = $this->envelopeBuilder->buildFromTenantKey($tenantKey, $driveFile, $syncJobId);
            $validation = $this->metadataValidator->validate($envelope);
            $items[] = $this->evaluateFilePlan($envelope, $validation, $tenantKey, $folderId);
        }

        return $this->buildPlan([
            'sync_job_id' => $syncJobId,
            'tenant_key' => $tenantKey,
            'tenant_sno' => $tenantSno,
            'industry_code' => (string) ($tenantEntry['industry_code'] ?? ''),
            'folder_id' => $folderId,
            'mode' => 'dry_run_plan',
        ], $items);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function buildPlan(array $context, array $items): array
    {
        $promoteCount = 0;
        $skipCount = 0;
        $failCount = 0;

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === self::STATUS_PROMOTE) {
                ++$promoteCount;
            } elseif ($status === self::STATUS_SKIP) {
                ++$skipCount;
            } elseif ($status === self::STATUS_FAIL) {
                ++$failCount;
            }
        }

        $totalFiles = count($items);
        $status = 'success';
        if ($failCount > 0 && ($promoteCount > 0 || $skipCount > 0)) {
            $status = 'partial';
        } elseif ($failCount > 0) {
            $status = 'failed';
        } elseif ($promoteCount === 0 && $skipCount > 0) {
            $status = 'skipped';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sync_job_id' => (string) ($context['sync_job_id'] ?? ''),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => (string) ($context['mode'] ?? 'dry_run_plan'),
            'scan_scope' => 'tenant_private',
            'tenant_key' => (string) ($context['tenant_key'] ?? ''),
            'tenant_sno' => (string) ($context['tenant_sno'] ?? ''),
            'industry_code' => (string) ($context['industry_code'] ?? ''),
            'folder_id' => (string) ($context['folder_id'] ?? ''),
            'owner_scope' => BdsDriveFileClassifier::OWNER_TENANT,
            'status' => $status,
            'environment' => $this->buildEnvironmentSnapshot(),
            'summary' => [
                'total_files' => $totalFiles,
                'promote_count' => $promoteCount,
                'skip_count' => $skipCount,
                'fail_count' => $failCount,
            ],
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function writePlan(array $plan, string $tenantSno): string
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            throw new \InvalidArgumentException('tenant_sno is required for writePlan.');
        }

        $planPath = $this->resolvePlanPath($tenantSno);
        $metaDir = dirname($planPath);

        if (!is_dir($metaDir) && !mkdir($metaDir, 0775, true) && !is_dir($metaDir)) {
            throw new \RuntimeException('Failed to create meta directory: ' . $metaDir);
        }

        $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode archive_promote_plan.json.');
        }

        $json .= "\n";

        if (file_put_contents($planPath, $json) === false) {
            throw new \RuntimeException('Failed to write archive_promote_plan.json: ' . $planPath);
        }

        return $planPath;
    }

    public function buildPlannedObjectPath(
        string $tenantSno,
        string $dataCategory,
        string $driveFileId,
        string $fileName
    ): string {
        $tenantSno = trim($tenantSno);
        $dataCategory = trim($dataCategory);
        $driveFileId = trim($driveFileId);
        $fileName = trim($fileName);

        if ($tenantSno === '' || $dataCategory === '' || $driveFileId === '' || $fileName === '') {
            throw new \InvalidArgumentException('All path segments are required for planned_object_path.');
        }

        return 'tenants/' . $tenantSno
            . '/archive/' . $dataCategory
            . '/' . $driveFileId
            . '/' . $fileName;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    public function evaluateFilePlan(
        array $envelope,
        array $validation,
        string $expectedTenantKey,
        string $expectedFolderId
    ): array {
        $fileId = (string) ($envelope['file_id'] ?? '');
        $fileName = (string) ($envelope['file_name'] ?? '');
        $mimeType = (string) ($envelope['mime_type'] ?? '');
        $tenantSno = (string) ($envelope['tenant_sno'] ?? '');
        $dataCategory = (string) ($envelope['data_category'] ?? '');
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');

        $base = [
            'drive_file_id' => $fileId,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'source_path' => (string) ($envelope['source_path'] ?? ''),
            'owner_scope' => $ownerScope,
            'data_category' => $dataCategory,
            'checksum' => $envelope['checksum'] ?? null,
            'checksum_method' => (string) ($envelope['checksum_method'] ?? ''),
            'planned_object_path' => null,
            'read_back_plan' => null,
            'warnings' => [],
            'gate_results' => [],
        ];

        $gate0 = $this->evaluateGate0($tenantSno);
        $base['gate_results']['gate_0_environment'] = $gate0;
        if (!$gate0['passed']) {
            return $this->finalizeItem($base, self::STATUS_SKIP, 'environment', (string) $gate0['reason']);
        }

        $gate1 = $this->evaluateGate1($envelope, $expectedTenantKey, $expectedFolderId);
        $base['gate_results']['gate_1_scope'] = $gate1;
        if (!$gate1['passed']) {
            return $this->finalizeItem($base, self::STATUS_SKIP, 'scope', (string) $gate1['reason']);
        }

        $gate2 = $this->evaluateGate2($validation);
        $base['gate_results']['gate_2_metadata'] = $gate2;
        if (!$gate2['passed']) {
            return $this->finalizeItem(
                $base,
                self::STATUS_FAIL,
                'metadata',
                (string) $gate2['reason'],
                is_array($gate2['errors'] ?? null) ? $gate2['errors'] : []
            );
        }

        $gate3 = $this->evaluateGate3($envelope);
        $base['gate_results']['gate_3_category'] = $gate3;
        if (!$gate3['passed']) {
            return $this->finalizeItem($base, self::STATUS_SKIP, 'category', (string) $gate3['reason']);
        }

        $gate4 = $this->evaluateGate4($envelope);
        $base['gate_results']['gate_4_content_type'] = $gate4;
        if (!$gate4['passed']) {
            $warnings = [];
            if (($gate4['reason'] ?? '') === self::WARNING_GOOGLE_WORKSPACE) {
                $warnings[] = self::WARNING_GOOGLE_WORKSPACE;
            }
            $base['warnings'] = $warnings;

            return $this->finalizeItem($base, self::STATUS_SKIP, 'content_type', (string) $gate4['reason']);
        }

        $plannedPath = null;
        try {
            $plannedPath = $this->buildPlannedObjectPath($tenantSno, $dataCategory, $fileId, $fileName);
            $base['planned_object_path'] = $plannedPath;
        } catch (\InvalidArgumentException $e) {
            return $this->finalizeItem($base, self::STATUS_FAIL, 'path_safety', 'path_segment_invalid');
        }

        $gate5 = $this->evaluateGate5($envelope);
        $base['gate_results']['gate_5_checksum'] = $gate5;
        if (!$gate5['passed']) {
            return $this->finalizeItem($base, self::STATUS_FAIL, 'checksum', (string) $gate5['reason']);
        }

        $gate6 = $this->evaluateGate6($envelope, $plannedPath);
        $base['gate_results']['gate_6_path_safety'] = $gate6;
        if (!$gate6['passed']) {
            return $this->finalizeItem($base, self::STATUS_FAIL, 'path_safety', (string) $gate6['reason']);
        }

        $base['read_back_plan'] = $this->buildReadBackPlan($plannedPath, $envelope);
        $base['gate_results']['gate_7_failure_handling'] = [
            'passed' => true,
            'reason' => 'read_back_planned',
        ];

        return $this->finalizeItem($base, self::STATUS_PROMOTE, null, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEnvironmentSnapshot(): array
    {
        $writeGate = BdsGcsUploader::evaluateWriteGate();

        return [
            'bucket' => BdsGcsUploader::DEFAULT_BUCKET,
            'dry_run' => (bool) ($writeGate['dry_run'] ?? true),
            'gcs_write_enabled' => (bool) ($writeGate['gcs_write_enabled'] ?? false),
            'target_sno' => (string) ($writeGate['target_sno'] ?? ''),
            'write_gate_open' => (bool) ($writeGate['open'] ?? false),
            'write_gate_reason' => (string) ($writeGate['reason'] ?? ''),
            'planner_note' => '6D-1a dry-run plan only; no GCS write performed',
        ];
    }

    /**
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate0(string $tenantSno): array
    {
        $writeGate = BdsGcsUploader::evaluateWriteGate();
        $targetSno = trim((string) ($writeGate['target_sno'] ?? ''));
        if ($targetSno !== '' && $tenantSno !== '' && $targetSno !== $tenantSno) {
            return ['passed' => false, 'reason' => 'target_sno_mismatch'];
        }

        return ['passed' => true, 'reason' => 'plan_mode_ok'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate1(array $envelope, string $expectedTenantKey, string $expectedFolderId): array
    {
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');
        if ($ownerScope !== BdsDriveFileClassifier::OWNER_TENANT) {
            return ['passed' => false, 'reason' => 'owner_scope_not_tenant'];
        }

        $tenantKey = trim((string) ($envelope['tenant_key'] ?? ''));
        if ($tenantKey !== trim($expectedTenantKey)) {
            return ['passed' => false, 'reason' => 'tenant_key_mismatch'];
        }

        if ($tenantKey !== self::PILOT_TENANT_KEY) {
            return ['passed' => false, 'reason' => 'pilot_tenant_only_travel_b'];
        }

        $tenantSno = trim((string) ($envelope['tenant_sno'] ?? ''));
        if ($tenantSno !== self::PILOT_TENANT_SNO) {
            return ['passed' => false, 'reason' => 'pilot_sno_mismatch'];
        }

        $registryEntry = BdsSourceRegistryLoader::loadByTenantKey($tenantKey);
        if ($registryEntry === null) {
            return ['passed' => false, 'reason' => 'registry_not_found'];
        }

        $registryFolderId = trim((string) ($registryEntry['private_knowledge_folder_id'] ?? ''));
        if ($registryFolderId !== '' && $registryFolderId !== trim($expectedFolderId)) {
            return ['passed' => false, 'reason' => 'drive_root_mismatch'];
        }

        return ['passed' => true, 'reason' => 'tenant_private_scope_ok'];
    }

    /**
     * @param array<string, mixed> $validation
     * @return array{passed: bool, reason: string, errors?: list<array<string, mixed>>}
     */
    private function evaluateGate2(array $validation): array
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

        return [
            'passed' => false,
            'reason' => 'metadata_validation_failed',
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate3(array $envelope): array
    {
        $dataCategory = (string) ($envelope['data_category'] ?? '');

        if ($dataCategory === BdsDriveFileClassifier::CATEGORY_REGISTRATION) {
            return ['passed' => false, 'reason' => 'customer_registration_drive_only'];
        }

        if ($dataCategory === BdsDriveFileClassifier::CATEGORY_SHARED) {
            return ['passed' => false, 'reason' => 'shared_knowledge_deferred_6e'];
        }

        if (!in_array($dataCategory, self::ALLOWED_DATA_CATEGORIES_6D1, true)) {
            return ['passed' => false, 'reason' => 'category_not_in_6d1_allow_list'];
        }

        return ['passed' => true, 'reason' => 'category_allowed'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate4(array $envelope): array
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
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate5(array $envelope): array
    {
        $method = (string) ($envelope['checksum_method'] ?? '');
        $checksum = $envelope['checksum'] ?? null;

        if ($method === BdsDriveMetadataEnvelopeBuilder::CHECKSUM_DRIVE_MD5) {
            if (!is_string($checksum) || !preg_match('/^[a-f0-9]{32}$/', $checksum)) {
                return ['passed' => false, 'reason' => 'checksum_invalid_md5'];
            }

            return ['passed' => true, 'reason' => 'checksum_ok'];
        }

        if ($method === BdsDriveMetadataEnvelopeBuilder::CHECKSUM_UNAVAILABLE) {
            return ['passed' => false, 'reason' => 'checksum_unavailable'];
        }

        return ['passed' => false, 'reason' => 'checksum_method_unsupported'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGate6(array $envelope, string $plannedPath): array
    {
        $tenantSno = trim((string) ($envelope['tenant_sno'] ?? ''));
        $fileName = (string) ($envelope['file_name'] ?? '');

        if (!$this->isSafeFileName($fileName)) {
            return ['passed' => false, 'reason' => 'unsafe_file_name'];
        }

        $expectedPrefix = 'tenants/' . $tenantSno . '/archive/';
        if (strpos($plannedPath, $expectedPrefix) !== 0) {
            return ['passed' => false, 'reason' => 'archive_prefix_required'];
        }

        if (preg_match('#(^|/)uploads(/|$)#i', $plannedPath) === 1) {
            return ['passed' => false, 'reason' => 'forbidden_uploads_alias'];
        }

        if (strpos($plannedPath, '/knowledge/') !== false) {
            return ['passed' => false, 'reason' => 'knowledge_path_forbidden'];
        }

        return ['passed' => true, 'reason' => 'path_safe'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function buildReadBackPlan(string $plannedObjectPath, array $envelope): array
    {
        return [
            'action' => 'verify_current_object',
            'object_path' => $plannedObjectPath,
            'compare_field' => 'checksum',
            'expected_checksum' => $envelope['checksum'] ?? null,
            'expected_checksum_method' => (string) ($envelope['checksum_method'] ?? ''),
            'versioning' => 'current_only',
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param list<array<string, mixed>> $errors
     * @return array<string, mixed>
     */
    private function finalizeItem(
        array $base,
        string $status,
        ?string $gate,
        ?string $reason,
        array $errors = []
    ): array {
        $base['status'] = $status;
        $base['gate'] = $gate;
        $base['reason'] = $reason;
        if ($errors !== []) {
            $base['errors'] = $errors;
        }

        return $base;
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

    /**
     * @return array<string, mixed>
     */
    private function buildFailedFetchItem(string $fileId, string $fileName): array
    {
        return [
            'drive_file_id' => $fileId,
            'file_name' => $fileName,
            'mime_type' => '',
            'source_path' => '',
            'owner_scope' => '',
            'data_category' => '',
            'checksum' => null,
            'checksum_method' => '',
            'planned_object_path' => null,
            'read_back_plan' => null,
            'warnings' => [],
            'gate_results' => [],
            'status' => self::STATUS_FAIL,
            'gate' => 'metadata',
            'reason' => 'drive_metadata_fetch_failed',
        ];
    }

    private function isGoogleWorkspaceNativeMime(string $mimeType): bool
    {
        $mimeType = strtolower(trim($mimeType));

        if (in_array($mimeType, self::GOOGLE_WORKSPACE_NATIVE_MIMES, true)) {
            return true;
        }

        return strpos($mimeType, 'application/vnd.google-apps.') === 0
            || strpos($mimeType, 'google-apps.') === 0;
    }

    private function isAllowedBinaryMime(string $mimeType, string $fileName): bool
    {
        if ($this->isGoogleWorkspaceNativeMime($mimeType)) {
            return false;
        }

        if ($mimeType === 'application/pdf') {
            return true;
        }

        if (strpos($mimeType, 'image/') === 0) {
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

        $classifier = new BdsDriveFileClassifier();
        try {
            $result = $classifier->classify(BdsDriveFileClassifier::OWNER_TENANT, $mimeType, $fileName);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        return ($result['data_category'] ?? '') !== BdsDriveFileClassifier::CATEGORY_SHARED;
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

        if (preg_match('/[\x00-\x1f\x7f]/', $fileName) === 1) {
            return false;
        }

        return true;
    }
}
