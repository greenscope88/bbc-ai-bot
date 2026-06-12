<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFileClassifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveMetadataEnvelopeBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

/**
 * BDS Phase 6C-1 — Drive Metadata envelope validator.
 *
 * @see docs/BATS_DRIVE_METADATA_CONTRACT.md §8、§12、§14、§15
 */
final class BdsDriveMetadataValidator
{
    /** @var BdsDriveFileClassifier */
    private $classifier;

    /** @var BdsDriveMetadataEnvelopeBuilder */
    private $pathBuilder;

    public function __construct(
        ?BdsDriveFileClassifier $classifier = null,
        ?BdsDriveMetadataEnvelopeBuilder $pathBuilder = null
    ) {
        $this->classifier = $classifier ?? new BdsDriveFileClassifier();
        $this->pathBuilder = $pathBuilder ?? new BdsDriveMetadataEnvelopeBuilder($this->classifier);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{
     *   ok: bool,
     *   errors: list<array{code: string, message: string, field: ?string}>,
     *   warnings: list<string>,
     *   validated_at: string
     * }
     */
    public function validate(array $envelope): array
    {
        $errors = [];
        $warnings = [];

        $this->validateRequiredFields($envelope, $errors);
        $this->validateSchemaConstants($envelope, $errors);
        $this->validateOwnerScopeConsistency($envelope, $errors);
        $this->validateTenantBoundary($envelope, $errors);
        $this->validateCustomerRegistrationIsolation($envelope, $errors);
        $this->validateSourcePath($envelope, $errors);
        $this->validateChecksumRules($envelope, $errors);
        $this->validateClassificationConsistency($envelope, $errors);

        if (isset($envelope['warnings']) && is_array($envelope['warnings'])) {
            foreach ($envelope['warnings'] as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[] = $warning;
                }
            }
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'validated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateRequiredFields(array $envelope, array &$errors): void
    {
        $required = [
            'schema_version',
            'source_type',
            'file_id',
            'file_name',
            'mime_type',
            'file_size_bytes',
            'checksum',
            'checksum_method',
            'source_path',
            'source_path_kind',
            'owner_scope',
            'tenant_key',
            'tenant_sno',
            'industry_code',
            'data_category',
            'uploaded_at',
            'modified_at',
            'discovered_at',
            'sync_job_id',
            'promote_status',
            'gcs_object_path',
            'warnings',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $envelope)) {
                $this->addError($errors, 'BDM_FIELD_MISSING', 'Missing required field: ' . $field, $field);
            }
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateSchemaConstants(array $envelope, array &$errors): void
    {
        if (($envelope['schema_version'] ?? '') !== BdsDriveMetadataEnvelopeBuilder::SCHEMA_VERSION) {
            $this->addError($errors, 'BDM_SCHEMA_VERSION', 'schema_version must be bds_drive_metadata.v1', 'schema_version');
        }

        if (($envelope['source_type'] ?? '') !== BdsDriveMetadataEnvelopeBuilder::SOURCE_TYPE) {
            $this->addError($errors, 'BDM_SOURCE_TYPE', 'source_type must be google_drive', 'source_type');
        }

        if (($envelope['source_path_kind'] ?? '') !== BdsDriveMetadataEnvelopeBuilder::SOURCE_PATH_KIND) {
            $this->addError($errors, 'BDM_SOURCE_PATH_KIND', 'source_path_kind must be logical', 'source_path_kind');
        }

        $promoteStatus = (string) ($envelope['promote_status'] ?? '');
        if (!in_array($promoteStatus, ['pending', 'validated', 'promoted', 'skipped', 'failed'], true)) {
            $this->addError($errors, 'BDM_PROMOTE_STATUS', 'Invalid promote_status', 'promote_status');
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateOwnerScopeConsistency(array $envelope, array &$errors): void
    {
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');
        if (!in_array($ownerScope, BdsDriveFileClassifier::VALID_OWNER_SCOPES, true)) {
            $this->addError($errors, 'BDM_OWNER_SCOPE', 'Invalid owner_scope', 'owner_scope');
            return;
        }

        $tenantKey = $envelope['tenant_key'] ?? null;
        $tenantSno = $envelope['tenant_sno'] ?? null;

        if ($ownerScope === BdsDriveFileClassifier::OWNER_TENANT) {
            if (!is_string($tenantKey) || trim($tenantKey) === '') {
                $this->addError($errors, 'BDM_TENANT_KEY_REQUIRED', 'tenant_key is required for tenant scope', 'tenant_key');
            }
            if (!is_string($tenantSno) || trim($tenantSno) === '') {
                $this->addError($errors, 'BDM_TENANT_SNO_REQUIRED', 'tenant_sno is required for tenant scope', 'tenant_sno');
            }
            return;
        }

        if ($tenantKey !== null) {
            $this->addError($errors, 'BDM_TENANT_KEY_FORBIDDEN', 'tenant_key must be null for non-tenant scope', 'tenant_key');
        }
        if ($tenantSno !== null) {
            $this->addError($errors, 'BDM_TENANT_SNO_FORBIDDEN', 'tenant_sno must be null for non-tenant scope', 'tenant_sno');
        }

        if ($ownerScope === BdsDriveFileClassifier::OWNER_GLOBAL && ($envelope['industry_code'] ?? '') !== 'global') {
            $this->addError($errors, 'BDM_INDUSTRY_CODE', 'industry_code must be global for global scope', 'industry_code');
        }

        if ($ownerScope === BdsDriveFileClassifier::OWNER_PLATFORM && ($envelope['industry_code'] ?? '') !== 'platform') {
            $this->addError($errors, 'BDM_INDUSTRY_CODE', 'industry_code must be platform for platform scope', 'industry_code');
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateTenantBoundary(array $envelope, array &$errors): void
    {
        if (($envelope['owner_scope'] ?? '') !== BdsDriveFileClassifier::OWNER_TENANT) {
            return;
        }

        $tenantKey = trim((string) ($envelope['tenant_key'] ?? ''));
        $tenantSno = trim((string) ($envelope['tenant_sno'] ?? ''));
        if ($tenantKey === '' || $tenantSno === '') {
            return;
        }

        $registryEntry = BdsSourceRegistryLoader::loadByTenantKey($tenantKey);
        if ($registryEntry === null) {
            $this->addError($errors, 'BDM_REGISTRY_NOT_FOUND', 'Tenant registry entry not found', 'tenant_key');
            return;
        }

        $registrySno = trim((string) ($registryEntry['sno'] ?? ''));
        if ($registrySno !== '' && $registrySno !== $tenantSno) {
            $this->addError(
                $errors,
                'BDM_TENANT_BOUNDARY',
                'tenant_sno does not match Tenant Registry',
                'tenant_sno'
            );
        }

        $registryIndustry = trim((string) ($registryEntry['industry_code'] ?? ''));
        $envelopeIndustry = trim((string) ($envelope['industry_code'] ?? ''));
        if ($registryIndustry !== '' && $envelopeIndustry !== '' && $registryIndustry !== $envelopeIndustry) {
            $this->addError(
                $errors,
                'BDM_TENANT_BOUNDARY',
                'industry_code does not match Tenant Registry',
                'industry_code'
            );
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateCustomerRegistrationIsolation(array $envelope, array &$errors): void
    {
        $dataCategory = (string) ($envelope['data_category'] ?? '');
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');

        if ($dataCategory === BdsDriveFileClassifier::CATEGORY_REGISTRATION && $ownerScope !== BdsDriveFileClassifier::OWNER_PLATFORM) {
            $this->addError(
                $errors,
                'BDM_CATEGORY_ISOLATION',
                'customer_registration requires platform owner_scope',
                'data_category'
            );
        }

        if ($ownerScope === BdsDriveFileClassifier::OWNER_PLATFORM
            && $dataCategory !== BdsDriveFileClassifier::CATEGORY_REGISTRATION) {
            $this->addError(
                $errors,
                'BDM_CATEGORY_ISOLATION',
                'platform owner_scope must use customer_registration',
                'data_category'
            );
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateSourcePath(array $envelope, array &$errors): void
    {
        $sourcePath = trim((string) ($envelope['source_path'] ?? ''));
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');
        $fileName = trim((string) ($envelope['file_name'] ?? ''));

        if ($sourcePath === '' || $fileName === '') {
            return;
        }

        if (strpos($sourcePath, $fileName) === false || substr($sourcePath, -strlen($fileName)) !== $fileName) {
            $this->addError($errors, 'BDM_SOURCE_PATH', 'source_path must end with file_name', 'source_path');
        }

        $expectedPrefix = $this->expectedSourcePathPrefix($envelope);
        if ($expectedPrefix !== null && strpos($sourcePath, $expectedPrefix) !== 0) {
            $this->addError(
                $errors,
                'BDM_SOURCE_PATH',
                'source_path does not match logical path template for owner_scope',
                'source_path'
            );
        }

        if ($this->containsLegacyDisplayFolderSegment($sourcePath, $ownerScope)) {
            $this->addError(
                $errors,
                'BDM_SOURCE_PATH',
                'source_path must not contain Drive display folder names',
                'source_path'
            );
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateChecksumRules(array $envelope, array &$errors): void
    {
        $method = (string) ($envelope['checksum_method'] ?? '');
        $checksum = $envelope['checksum'];

        if (!in_array($method, [
            BdsDriveMetadataEnvelopeBuilder::CHECKSUM_DRIVE_MD5,
            BdsDriveMetadataEnvelopeBuilder::CHECKSUM_UNAVAILABLE,
            'sha256_content',
        ], true)) {
            $this->addError($errors, 'BDM_CHECKSUM_METHOD', 'Invalid checksum_method', 'checksum_method');
            return;
        }

        if ($method === BdsDriveMetadataEnvelopeBuilder::CHECKSUM_DRIVE_MD5) {
            if (!is_string($checksum) || !preg_match('/^[a-f0-9]{32}$/', $checksum)) {
                $this->addError($errors, 'BDM_CHECKSUM', 'drive_md5 requires lowercase hex md5', 'checksum');
            }
            return;
        }

        if ($method === BdsDriveMetadataEnvelopeBuilder::CHECKSUM_UNAVAILABLE && $checksum !== null) {
            $this->addError($errors, 'BDM_CHECKSUM', 'checksum must be null when unavailable', 'checksum');
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function validateClassificationConsistency(array $envelope, array &$errors): void
    {
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');
        $mimeType = (string) ($envelope['mime_type'] ?? '');
        $fileName = (string) ($envelope['file_name'] ?? '');
        $dataCategory = (string) ($envelope['data_category'] ?? '');

        if ($ownerScope === '' || $mimeType === '') {
            return;
        }

        try {
            $expected = $this->classifier->classify($ownerScope, $mimeType, $fileName);
        } catch (\InvalidArgumentException $e) {
            return;
        }

        if ($expected['data_category'] !== $dataCategory) {
            $this->addError(
                $errors,
                'BDM_CATEGORY_MISMATCH',
                'data_category does not match classification matrix',
                'data_category'
            );
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function expectedSourcePathPrefix(array $envelope): ?string
    {
        $ownerScope = (string) ($envelope['owner_scope'] ?? '');
        $industryCode = trim((string) ($envelope['industry_code'] ?? ''));
        $tenantKey = trim((string) ($envelope['tenant_key'] ?? ''));
        $fileName = trim((string) ($envelope['file_name'] ?? ''));

        if ($fileName === '') {
            return null;
        }

        try {
            return $this->pathBuilder->buildLogicalSourcePath($ownerScope, $industryCode, $tenantKey, $fileName, '');
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    private function containsLegacyDisplayFolderSegment(string $sourcePath, string $ownerScope): bool
    {
        if ($ownerScope !== BdsDriveFileClassifier::OWNER_TENANT) {
            return false;
        }

        return (bool) preg_match('#/tenants/[^/]+/(旅行蜜優惠|Private|private)/#u', $sourcePath);
    }

    /**
     * @param list<array{code: string, message: string, field: ?string}> $errors
     */
    private function addError(array &$errors, string $code, string $message, ?string $field): void
    {
        $errors[] = [
            'code' => $code,
            'message' => $message,
            'field' => $field,
        ];
    }
}
