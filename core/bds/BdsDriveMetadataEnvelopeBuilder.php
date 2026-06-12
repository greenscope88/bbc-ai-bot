<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsDriveFileClassifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';

/**
 * BDS Phase 6C-1 — Drive Metadata envelope builder.
 *
 * @see docs/BATS_DRIVE_METADATA_CONTRACT.md §12、§14、§15
 */
final class BdsDriveMetadataEnvelopeBuilder
{
    public const SCHEMA_VERSION = 'bds_drive_metadata.v1';

    public const SOURCE_TYPE = 'google_drive';

    public const SOURCE_PATH_KIND = 'logical';

    public const CHECKSUM_DRIVE_MD5 = 'drive_md5';

    public const CHECKSUM_UNAVAILABLE = 'unavailable';

    public const WARNING_CHECKSUM_UNAVAILABLE = 'W_CHK_UNAVAILABLE';

    public const WARNING_LEGACY_FOLDER = 'W_PATH_LEGACY_FOLDER';

    /** @var BdsDriveFileClassifier */
    private $classifier;

    public function __construct(?BdsDriveFileClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new BdsDriveFileClassifier();
    }

    /**
     * @param array<string, mixed> $tenantEntry Normalized Tenant Registry entry.
     * @return array<string, mixed>
     */
    public function buildScanContextFromTenantEntry(array $tenantEntry): array
    {
        return [
            'owner_scope' => BdsDriveFileClassifier::OWNER_TENANT,
            'industry_code' => isset($tenantEntry['industry_code']) ? (string) $tenantEntry['industry_code'] : '',
            'tenant_key' => isset($tenantEntry['tenant_key']) ? (string) $tenantEntry['tenant_key'] : '',
            'tenant_sno' => isset($tenantEntry['sno']) ? (string) $tenantEntry['sno'] : '',
            'relative_path' => '',
            'legacy_folder' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildScanContextForIndustry(string $industryCode): array
    {
        return [
            'owner_scope' => BdsDriveFileClassifier::OWNER_INDUSTRY,
            'industry_code' => trim($industryCode),
            'tenant_key' => null,
            'tenant_sno' => null,
            'relative_path' => '',
            'legacy_folder' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildScanContextForGlobal(): array
    {
        return [
            'owner_scope' => BdsDriveFileClassifier::OWNER_GLOBAL,
            'industry_code' => 'global',
            'tenant_key' => null,
            'tenant_sno' => null,
            'relative_path' => '',
            'legacy_folder' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildScanContextForPlatform(): array
    {
        return [
            'owner_scope' => BdsDriveFileClassifier::OWNER_PLATFORM,
            'industry_code' => 'platform',
            'tenant_key' => null,
            'tenant_sno' => null,
            'relative_path' => '',
            'legacy_folder' => false,
        ];
    }

    /**
     * @param array<string, mixed> $driveFile Drive API fields: id, name, mimeType, createdTime, modifiedTime, size?, md5Checksum?
     * @param array<string, mixed> $scanContext From buildScanContext* methods.
     * @return array<string, mixed>
     */
    public function buildFromDriveFile(
        array $driveFile,
        array $scanContext,
        string $syncJobId,
        ?string $discoveredAt = null
    ): array {
        $fileId = trim((string) ($driveFile['id'] ?? ''));
        $fileName = trim((string) ($driveFile['name'] ?? ''));
        $mimeType = trim((string) ($driveFile['mimeType'] ?? ''));

        if ($fileId === '' || $fileName === '' || $mimeType === '') {
            throw new \InvalidArgumentException('driveFile requires id, name, and mimeType.');
        }

        $ownerScope = trim((string) ($scanContext['owner_scope'] ?? ''));
        $industryCode = trim((string) ($scanContext['industry_code'] ?? ''));
        $tenantKey = array_key_exists('tenant_key', $scanContext) ? $scanContext['tenant_key'] : null;
        $tenantSno = array_key_exists('tenant_sno', $scanContext) ? $scanContext['tenant_sno'] : null;
        $relativePath = isset($scanContext['relative_path']) ? trim((string) $scanContext['relative_path']) : '';
        $legacyFolder = !empty($scanContext['legacy_folder']);

        $classification = $this->classifier->classify($ownerScope, $mimeType, $fileName);
        $warnings = $classification['warnings'];

        if ($legacyFolder) {
            $warnings[] = self::WARNING_LEGACY_FOLDER;
        }

        $checksumData = $this->resolveChecksum($driveFile);
        if ($checksumData['warning'] !== null) {
            $warnings[] = $checksumData['warning'];
        }

        $sourcePath = $this->buildLogicalSourcePath(
            $ownerScope,
            $industryCode,
            is_string($tenantKey) ? $tenantKey : '',
            $fileName,
            $relativePath
        );

        $discoveredAtIso = $this->normalizeTimestamp($discoveredAt ?? gmdate('c'));
        $uploadedAt = $this->normalizeTimestamp((string) ($driveFile['createdTime'] ?? $discoveredAtIso));
        $modifiedAt = $this->normalizeTimestamp((string) ($driveFile['modifiedTime'] ?? $uploadedAt));

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'source_type' => self::SOURCE_TYPE,
            'file_id' => $fileId,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'file_size_bytes' => $this->normalizeFileSize($driveFile['size'] ?? null),
            'checksum' => $checksumData['checksum'],
            'checksum_method' => $checksumData['checksum_method'],
            'source_path' => $sourcePath,
            'source_path_kind' => self::SOURCE_PATH_KIND,
            'owner_scope' => $classification['owner_scope'],
            'tenant_key' => $ownerScope === BdsDriveFileClassifier::OWNER_TENANT ? (string) $tenantKey : null,
            'tenant_sno' => $ownerScope === BdsDriveFileClassifier::OWNER_TENANT ? (string) $tenantSno : null,
            'industry_code' => $this->resolveIndustryCodeForEnvelope($ownerScope, $industryCode),
            'data_category' => $classification['data_category'],
            'uploaded_at' => $uploadedAt,
            'modified_at' => $modifiedAt,
            'discovered_at' => $discoveredAtIso,
            'sync_job_id' => trim($syncJobId),
            'promote_status' => 'pending',
            'gcs_object_path' => null,
            'warnings' => array_values(array_unique($warnings)),
        ];

        return $envelope;
    }

    /**
     * @param array<string, mixed> $driveFile
     * @return array<string, mixed>
     */
    public function buildFromTenantKey(
        string $tenantKey,
        array $driveFile,
        string $syncJobId,
        ?string $discoveredAt = null,
        string $relativePath = ''
    ): array {
        $entry = BdsSourceRegistryLoader::loadByTenantKey($tenantKey);
        if ($entry === null) {
            throw new \InvalidArgumentException('Tenant registry entry not found: ' . $tenantKey);
        }

        $context = $this->buildScanContextFromTenantEntry($entry);
        $context['relative_path'] = trim($relativePath);

        return $this->buildFromDriveFile($driveFile, $context, $syncJobId, $discoveredAt);
    }

    public function buildLogicalSourcePath(
        string $ownerScope,
        string $industryCode,
        string $tenantKey,
        string $fileName,
        string $relativePath = ''
    ): string {
        $fileName = trim($fileName);
        if ($fileName === '') {
            throw new \InvalidArgumentException('file_name is required for source_path.');
        }

        $relativePath = $this->normalizeRelativePath($relativePath);

        switch ($ownerScope) {
            case BdsDriveFileClassifier::OWNER_TENANT:
                if ($industryCode === '' || $tenantKey === '') {
                    throw new \InvalidArgumentException('industry_code and tenant_key are required for tenant source_path.');
                }
                $prefix = 'industries/' . $industryCode . '/tenants/' . $tenantKey . '/01_Private_Layer/';
                break;
            case BdsDriveFileClassifier::OWNER_INDUSTRY:
                if ($industryCode === '') {
                    throw new \InvalidArgumentException('industry_code is required for industry source_path.');
                }
                $prefix = 'industries/' . $industryCode . '/shared/02_Shared_Layer/';
                break;
            case BdsDriveFileClassifier::OWNER_GLOBAL:
                $prefix = 'global/02_Global_Shared_Layer/';
                break;
            case BdsDriveFileClassifier::OWNER_PLATFORM:
                $prefix = 'registrations/';
                break;
            default:
                throw new \InvalidArgumentException('Invalid owner_scope for source_path: ' . $ownerScope);
        }

        return $prefix . $relativePath . $fileName;
    }

    private function resolveIndustryCodeForEnvelope(string $ownerScope, string $industryCode): string
    {
        if ($ownerScope === BdsDriveFileClassifier::OWNER_GLOBAL) {
            return 'global';
        }

        if ($ownerScope === BdsDriveFileClassifier::OWNER_PLATFORM) {
            return 'platform';
        }

        return $industryCode;
    }

    /**
     * @param array<string, mixed> $driveFile
     * @return array{checksum: ?string, checksum_method: string, warning: ?string}
     */
    private function resolveChecksum(array $driveFile): array
    {
        $md5 = isset($driveFile['md5Checksum']) ? trim((string) $driveFile['md5Checksum']) : '';
        if ($md5 !== '') {
            return [
                'checksum' => strtolower($md5),
                'checksum_method' => self::CHECKSUM_DRIVE_MD5,
                'warning' => null,
            ];
        }

        return [
            'checksum' => null,
            'checksum_method' => self::CHECKSUM_UNAVAILABLE,
            'warning' => self::WARNING_CHECKSUM_UNAVAILABLE,
        ];
    }

    /**
     * @param mixed $size
     */
    private function normalizeFileSize($size): ?int
    {
        if ($size === null || $size === '') {
            return null;
        }

        if (!is_numeric($size)) {
            return null;
        }

        $intSize = (int) $size;

        return $intSize >= 0 ? $intSize : null;
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return '';
        }

        return $relativePath . '/';
    }

    private function normalizeTimestamp(string $timestamp): string
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        try {
            $date = new \DateTimeImmutable($timestamp);

            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
    }
}
