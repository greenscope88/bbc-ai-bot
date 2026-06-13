<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFolderScanner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveMetadataValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFileClassifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsPlatformDriveRegistryLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsSharedArchivePolicyLoader.php';

/**
 * BDS Phase 6E-4a — Shared Archive Promote Planner (dry-run only).
 *
 * Industry / Global Shared Drive → Metadata → Policy Gate → Archive Path Plan.
 * Writes archive_promote_plan.json locally; no GCS upload.
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §6.1、§8.1、§18
 */
final class BdsSharedArchivePromotePlanner
{
    public const SCHEMA_VERSION = 'bds_shared_archive_promote_plan.v1';

    public const PLAN_FILENAME = 'archive_promote_plan.json';

    public const STATUS_PROMOTE = 'PROMOTE';

    public const STATUS_SKIP = 'SKIP';

    public const STATUS_FAIL = 'FAIL';

    public const REASON_POLICY_DISABLED = 'POLICY_DISABLED';

    public const REASON_REGISTRY_FOLDER_MISSING = 'REGISTRY_FOLDER_MISSING';

    public const WARNING_GOOGLE_WORKSPACE = 'W_GOOGLE_WORKSPACE_EXPORT_REQUIRED';

    public const MIME_VND_GOOGLE_SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    public const MIME_VND_GOOGLE_DOCUMENT = 'application/vnd.google-apps.document';

    public const MIME_VND_GOOGLE_PRESENTATION = 'application/vnd.google-apps.presentation';

    /** @var list<string> */
    public const GOOGLE_WORKSPACE_NATIVE_MIMES = [
        self::MIME_VND_GOOGLE_SPREADSHEET,
        self::MIME_VND_GOOGLE_DOCUMENT,
        self::MIME_VND_GOOGLE_PRESENTATION,
        'application/vnd.google-apps.',
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

    public function resolveSharedMetaDirectory(string $scopeCode): string
    {
        $scopeCode = trim($scopeCode);
        if ($scopeCode === '') {
            throw new \InvalidArgumentException('scope_code is required.');
        }

        return $this->planRoot
            . DIRECTORY_SEPARATOR . 'shared'
            . DIRECTORY_SEPARATOR . $scopeCode
            . DIRECTORY_SEPARATOR . 'meta';
    }

    public function resolvePlanPath(string $scopeCode): string
    {
        return $this->resolveSharedMetaDirectory($scopeCode) . DIRECTORY_SEPARATOR . self::PLAN_FILENAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function planForIndustrySharedFolder(string $industryCode, string $syncJobId): array
    {
        $industryCode = trim($industryCode);
        $syncJobId = trim($syncJobId);

        if ($industryCode === '') {
            throw new \InvalidArgumentException('industry_code is required.');
        }
        if ($syncJobId === '') {
            throw new \InvalidArgumentException('sync_job_id is required.');
        }

        $scan = $this->scanner->scanIndustrySharedFolder($industryCode);
        $policyEnabled = BdsSharedArchivePolicyLoader::isIndustryArchiveEnabled($industryCode);

        if (($scan['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING) {
            return $this->buildPlan([
                'sync_job_id' => $syncJobId,
                'owner_scope' => BdsDriveFileClassifier::OWNER_INDUSTRY,
                'industry_code' => $industryCode,
                'folder_id' => null,
                'policy_enabled' => $policyEnabled,
                'folder_status' => BdsDriveFolderScanner::STATUS_MISSING,
                'folder_error_code' => (string) ($scan['error_code'] ?? ''),
                'folder_error' => (string) ($scan['error'] ?? ''),
                'mode' => 'dry_run_plan',
            ], []);
        }

        if (($scan['status'] ?? '') !== BdsDriveFolderScanner::STATUS_OK) {
            return $this->buildPlan([
                'sync_job_id' => $syncJobId,
                'owner_scope' => BdsDriveFileClassifier::OWNER_INDUSTRY,
                'industry_code' => $industryCode,
                'folder_id' => $scan['folder_id'] ?? null,
                'policy_enabled' => $policyEnabled,
                'folder_status' => (string) ($scan['status'] ?? 'error'),
                'folder_error_code' => (string) ($scan['error_code'] ?? ''),
                'folder_error' => (string) ($scan['error'] ?? ''),
                'mode' => 'dry_run_plan',
            ], []);
        }

        $folderId = trim((string) ($scan['folder_id'] ?? ''));
        $items = $this->buildItemsFromScan(
            $scan,
            BdsDriveFileClassifier::OWNER_INDUSTRY,
            $industryCode,
            $syncJobId,
            $policyEnabled,
            $folderId
        );

        return $this->buildPlan([
            'sync_job_id' => $syncJobId,
            'owner_scope' => BdsDriveFileClassifier::OWNER_INDUSTRY,
            'industry_code' => $industryCode,
            'folder_id' => $folderId,
            'policy_enabled' => $policyEnabled,
            'folder_status' => BdsDriveFolderScanner::STATUS_OK,
            'mode' => 'dry_run_plan',
        ], $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function planForGlobalSharedFolder(string $syncJobId): array
    {
        $syncJobId = trim($syncJobId);
        if ($syncJobId === '') {
            throw new \InvalidArgumentException('sync_job_id is required.');
        }

        $scan = $this->scanner->scanGlobalSharedFolder();
        $policyEnabled = BdsSharedArchivePolicyLoader::isGlobalArchiveEnabled();

        if (($scan['status'] ?? '') === BdsDriveFolderScanner::STATUS_MISSING) {
            return $this->buildPlan([
                'sync_job_id' => $syncJobId,
                'owner_scope' => BdsDriveFileClassifier::OWNER_GLOBAL,
                'industry_code' => 'global',
                'folder_id' => null,
                'policy_enabled' => $policyEnabled,
                'folder_status' => BdsDriveFolderScanner::STATUS_MISSING,
                'folder_error_code' => (string) ($scan['error_code'] ?? ''),
                'folder_error' => (string) ($scan['error'] ?? ''),
                'mode' => 'dry_run_plan',
            ], []);
        }

        if (($scan['status'] ?? '') !== BdsDriveFolderScanner::STATUS_OK) {
            return $this->buildPlan([
                'sync_job_id' => $syncJobId,
                'owner_scope' => BdsDriveFileClassifier::OWNER_GLOBAL,
                'industry_code' => 'global',
                'folder_id' => $scan['folder_id'] ?? null,
                'policy_enabled' => $policyEnabled,
                'folder_status' => (string) ($scan['status'] ?? 'error'),
                'folder_error_code' => (string) ($scan['error_code'] ?? ''),
                'folder_error' => (string) ($scan['error'] ?? ''),
                'mode' => 'dry_run_plan',
            ], []);
        }

        $folderId = trim((string) ($scan['folder_id'] ?? ''));
        $items = $this->buildItemsFromScan(
            $scan,
            BdsDriveFileClassifier::OWNER_GLOBAL,
            'global',
            $syncJobId,
            $policyEnabled,
            $folderId
        );

        return $this->buildPlan([
            'sync_job_id' => $syncJobId,
            'owner_scope' => BdsDriveFileClassifier::OWNER_GLOBAL,
            'industry_code' => 'global',
            'folder_id' => $folderId,
            'policy_enabled' => $policyEnabled,
            'folder_status' => BdsDriveFolderScanner::STATUS_OK,
            'mode' => 'dry_run_plan',
        ], $items);
    }

    public function buildPlannedObjectPath(
        string $ownerScope,
        string $industryCode,
        string $driveFileId,
        string $fileName,
        string $dataCategory = BdsDriveFileClassifier::CATEGORY_SHARED
    ): string {
        $ownerScope = trim($ownerScope);
        $industryCode = trim($industryCode);
        $driveFileId = trim($driveFileId);
        $fileName = trim($fileName);
        $dataCategory = trim($dataCategory);

        if ($ownerScope === '' || $industryCode === '' || $driveFileId === '' || $fileName === '' || $dataCategory === '') {
            throw new \InvalidArgumentException('All path segments are required for planned_object_path.');
        }

        if ($ownerScope === BdsDriveFileClassifier::OWNER_GLOBAL) {
            return 'shared/global/archive/' . $dataCategory . '/' . $driveFileId . '/' . $fileName;
        }

        if ($ownerScope === BdsDriveFileClassifier::OWNER_INDUSTRY) {
            return 'shared/' . $industryCode . '/archive/' . $dataCategory . '/' . $driveFileId . '/' . $fileName;
        }

        throw new \InvalidArgumentException('owner_scope must be industry or global for shared planned_object_path.');
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    public function evaluateFilePlan(
        array $envelope,
        array $validation,
        string $expectedOwnerScope,
        string $expectedIndustryCode,
        bool $policyEnabled,
        ?string $expectedFolderId = null
    ): array {
        $fileId = (string) ($envelope['file_id'] ?? '');
        $fileName = (string) ($envelope['file_name'] ?? '');
        $mimeType = (string) ($envelope['mime_type'] ?? '');
        $dataCategory = (string) ($envelope['data_category'] ?? '');
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');
        $industryCode = (string) ($envelope['industry_code'] ?? '');

        $base = [
            'drive_file_id' => $fileId,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'source_path' => (string) ($envelope['source_path'] ?? ''),
            'owner_scope' => $ownerScope,
            'industry_code' => $industryCode,
            'data_category' => $dataCategory,
            'checksum' => $envelope['checksum'] ?? null,
            'checksum_method' => (string) ($envelope['checksum_method'] ?? ''),
            'planned_object_path' => null,
            'read_back_plan' => null,
            'warnings' => [],
            'gate_results' => [],
        ];

        if ($expectedFolderId === null || trim($expectedFolderId) === '') {
            $gateB = ['passed' => false, 'reason' => self::REASON_REGISTRY_FOLDER_MISSING];
            $base['gate_results']['gate_b_registry_folder'] = $gateB;

            return $this->finalizeItem($base, self::STATUS_SKIP, 'registry_folder', self::REASON_REGISTRY_FOLDER_MISSING);
        }

        $gateC = $this->evaluateGateOwnerScope($envelope, $expectedOwnerScope, $expectedIndustryCode);
        $base['gate_results']['gate_c_owner_scope'] = $gateC;
        if (!$gateC['passed']) {
            return $this->finalizeItem($base, self::STATUS_FAIL, 'owner_scope', (string) $gateC['reason']);
        }

        $gateA = $this->evaluateGatePolicy($policyEnabled);
        $base['gate_results']['gate_a_policy'] = $gateA;
        if (!$gateA['passed']) {
            return $this->finalizeItem($base, self::STATUS_SKIP, 'policy', self::REASON_POLICY_DISABLED);
        }

        $gateD = $this->evaluateGateMetadata($validation);
        $base['gate_results']['gate_d_metadata'] = $gateD;
        if (!$gateD['passed']) {
            return $this->finalizeItem(
                $base,
                self::STATUS_FAIL,
                'metadata',
                (string) $gateD['reason'],
                is_array($gateD['errors'] ?? null) ? $gateD['errors'] : []
            );
        }

        if ($dataCategory !== BdsDriveFileClassifier::CATEGORY_SHARED) {
            $base['gate_results']['gate_category'] = [
                'passed' => false,
                'reason' => 'data_category_not_shared_knowledge',
            ];

            return $this->finalizeItem($base, self::STATUS_FAIL, 'category', 'data_category_not_shared_knowledge');
        }

        $gateE = $this->evaluateGateGoogleWorkspace($envelope);
        $base['gate_results']['gate_e_content_type'] = $gateE;
        if (!$gateE['passed']) {
            if (($gateE['reason'] ?? '') === self::WARNING_GOOGLE_WORKSPACE) {
                $base['warnings'][] = self::WARNING_GOOGLE_WORKSPACE;
            }

            return $this->finalizeItem($base, self::STATUS_SKIP, 'content_type', (string) $gateE['reason']);
        }

        $plannedPath = null;
        try {
            $plannedPath = $this->buildPlannedObjectPath($ownerScope, $industryCode, $fileId, $fileName, $dataCategory);
            $base['planned_object_path'] = $plannedPath;
        } catch (\InvalidArgumentException $e) {
            return $this->finalizeItem($base, self::STATUS_FAIL, 'path_safety', 'path_segment_invalid');
        }

        $pathGate = $this->evaluateGatePathSafety($ownerScope, $industryCode, $plannedPath, $fileName);
        $base['gate_results']['gate_f_path_safety'] = $pathGate;
        if (!$pathGate['passed']) {
            return $this->finalizeItem($base, self::STATUS_FAIL, 'path_safety', (string) $pathGate['reason']);
        }

        $checksumGate = $this->evaluateGateChecksum($envelope);
        $base['gate_results']['gate_f_checksum'] = $checksumGate;
        if (!$checksumGate['passed']) {
            return $this->finalizeItem($base, self::STATUS_FAIL, 'checksum', (string) $checksumGate['reason']);
        }

        $base['read_back_plan'] = $this->buildReadBackPlan($plannedPath, $envelope);
        $base['gate_results']['gate_f_binary_promote'] = [
            'passed' => true,
            'reason' => 'binary_plan_only',
        ];

        return $this->finalizeItem($base, self::STATUS_PROMOTE, null, null);
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

        $ownerScope = (string) ($context['owner_scope'] ?? '');
        $scanScope = $ownerScope === BdsDriveFileClassifier::OWNER_GLOBAL
            ? BdsDriveFolderScanner::SCAN_SCOPE_GLOBAL_SHARED
            : BdsDriveFolderScanner::SCAN_SCOPE_INDUSTRY_SHARED;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sync_job_id' => (string) ($context['sync_job_id'] ?? ''),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => (string) ($context['mode'] ?? 'dry_run_plan'),
            'scan_scope' => $scanScope,
            'owner_scope' => $ownerScope,
            'industry_code' => (string) ($context['industry_code'] ?? ''),
            'folder_id' => $context['folder_id'] ?? null,
            'policy_enabled' => (bool) ($context['policy_enabled'] ?? false),
            'folder_status' => (string) ($context['folder_status'] ?? ''),
            'folder_error_code' => (string) ($context['folder_error_code'] ?? ''),
            'folder_error' => (string) ($context['folder_error'] ?? ''),
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
    public function writePlan(array $plan, string $scopeCode): string
    {
        $scopeCode = trim($scopeCode);
        if ($scopeCode === '') {
            throw new \InvalidArgumentException('scope_code is required for writePlan.');
        }

        $planPath = $this->resolvePlanPath($scopeCode);
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

    /**
     * @param array<string, mixed> $scan
     * @return list<array<string, mixed>>
     */
    private function buildItemsFromScan(
        array $scan,
        string $expectedOwnerScope,
        string $expectedIndustryCode,
        string $syncJobId,
        bool $policyEnabled,
        string $folderId
    ): array {
        $children = is_array($scan['children']['files'] ?? null) ? $scan['children']['files'] : [];
        $items = [];
        $scanContext = $expectedOwnerScope === BdsDriveFileClassifier::OWNER_GLOBAL
            ? $this->envelopeBuilder->buildScanContextForGlobal()
            : $this->envelopeBuilder->buildScanContextForIndustry($expectedIndustryCode);

        foreach ($children as $child) {
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

            $envelope = $this->envelopeBuilder->buildFromDriveFile($driveFile, $scanContext, $syncJobId);
            $validation = $this->metadataValidator->validate($envelope);
            $items[] = $this->evaluateFilePlan(
                $envelope,
                $validation,
                $expectedOwnerScope,
                $expectedIndustryCode,
                $policyEnabled,
                $folderId
            );
        }

        return $items;
    }

    /**
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGatePolicy(bool $policyEnabled): array
    {
        if (!$policyEnabled) {
            return ['passed' => false, 'reason' => self::REASON_POLICY_DISABLED];
        }

        return ['passed' => true, 'reason' => 'policy_enabled'];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGateOwnerScope(
        array $envelope,
        string $expectedOwnerScope,
        string $expectedIndustryCode
    ): array {
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');
        if ($ownerScope !== $expectedOwnerScope) {
            return ['passed' => false, 'reason' => 'owner_scope_mismatch'];
        }

        $industryCode = trim((string) ($envelope['industry_code'] ?? ''));
        if ($industryCode !== trim($expectedIndustryCode)) {
            return ['passed' => false, 'reason' => 'industry_code_mismatch'];
        }

        return ['passed' => true, 'reason' => 'owner_scope_ok'];
    }

    /**
     * @param array<string, mixed> $validation
     * @return array{passed: bool, reason: string, errors?: list<array<string, mixed>>}
     */
    private function evaluateGateMetadata(array $validation): array
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
    private function evaluateGateGoogleWorkspace(array $envelope): array
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
    private function evaluateGatePathSafety(
        string $ownerScope,
        string $industryCode,
        string $plannedPath,
        string $fileName
    ): array {
        if (!$this->isSafeFileName($fileName)) {
            return ['passed' => false, 'reason' => 'unsafe_file_name'];
        }

        if ($ownerScope === BdsDriveFileClassifier::OWNER_GLOBAL) {
            $expectedPrefix = 'shared/global/archive/';
        } else {
            $expectedPrefix = 'shared/' . trim($industryCode) . '/archive/';
        }

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
     * @return array{passed: bool, reason: string}
     */
    private function evaluateGateChecksum(array $envelope): array
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
     * @return array<string, mixed>
     */
    private function buildEnvironmentSnapshot(): array
    {
        return [
            'planner_note' => '6E-4a shared dry-run plan only; no GCS write performed',
            'gcs_write_enabled' => false,
            'promote_executed' => false,
        ];
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
            'deferred_phase' => '6E-4b',
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
            'industry_code' => '',
            'data_category' => '',
            'checksum' => null,
            'checksum_method' => '',
            'planned_object_path' => null,
            'read_back_plan' => null,
            'warnings' => [],
            'gate_results' => [],
            'status' => self::STATUS_FAIL,
            'gate' => 'drive_fetch',
            'reason' => 'drive_metadata_fetch_failed',
        ];
    }

    private function isGoogleWorkspaceNativeMime(string $mimeType): bool
    {
        if ($mimeType === '') {
            return false;
        }

        if (strpos($mimeType, 'application/vnd.google-apps.') === 0) {
            return true;
        }

        return in_array($mimeType, self::GOOGLE_WORKSPACE_NATIVE_MIMES, true);
    }

    private function isAllowedBinaryMime(string $mimeType, string $fileName): bool
    {
        $lowerName = strtolower($fileName);

        if ($mimeType === 'application/pdf' || substr($lowerName, -4) === '.pdf') {
            return true;
        }

        if (strpos($mimeType, 'image/') === 0) {
            return true;
        }

        if (
            $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            || $mimeType === 'application/msword'
            || substr($lowerName, -5) === '.docx'
            || substr($lowerName, -4) === '.doc'
        ) {
            return true;
        }

        if (
            $mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            || $mimeType === 'application/vnd.ms-powerpoint'
            || substr($lowerName, -5) === '.pptx'
            || substr($lowerName, -4) === '.ppt'
        ) {
            return true;
        }

        if ($mimeType === 'application/zip' || substr($lowerName, -4) === '.zip') {
            return true;
        }

        return false;
    }

    private function isSafeFileName(string $fileName): bool
    {
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return false;
        }

        if (strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false) {
            return false;
        }

        if (preg_match('/[\x00-\x1f]/', $fileName) === 1) {
            return false;
        }

        return true;
    }
}
