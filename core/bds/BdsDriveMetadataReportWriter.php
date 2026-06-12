<?php
declare(strict_types=1);

/**
 * BDS Phase 6C-2 — Drive Metadata sync report writer (local dry-run only).
 *
 * Writes drive_sync_report.json under var/bds/tenants/{sno}/meta/.
 * No GCS upload, no Archive promote, no Knowledge JSON.
 *
 * @see docs/BATS_DRIVE_METADATA_CONTRACT.md §17
 * @see docs/BDS_RUNTIME_STORAGE_POLICY.md §4
 */
final class BdsDriveMetadataReportWriter
{
    public const SCHEMA_VERSION = 'bds_drive_sync_report.v1';

    public const REPORT_FILENAME = 'drive_sync_report.json';

    public const VALIDATION_STATUS_VALID = 'valid';

    public const VALIDATION_STATUS_INVALID = 'invalid';

    /** @var string */
    private $reportRoot;

    public function __construct(?string $reportRoot = null)
    {
        $this->reportRoot = $reportRoot !== null
            ? rtrim($reportRoot, '/\\')
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds';
    }

    public function getReportRoot(): string
    {
        return $this->reportRoot;
    }

    public function resolveTenantMetaDirectory(string $tenantSno): string
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            throw new \InvalidArgumentException('tenant_sno is required.');
        }

        return $this->reportRoot
            . DIRECTORY_SEPARATOR . 'tenants'
            . DIRECTORY_SEPARATOR . $tenantSno
            . DIRECTORY_SEPARATOR . 'meta';
    }

    public function resolveReportPath(string $tenantSno): string
    {
        return $this->resolveTenantMetaDirectory($tenantSno) . DIRECTORY_SEPARATOR . self::REPORT_FILENAME;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{envelope: array<string, mixed>, validation: array<string, mixed>}> $records
     * @return array<string, mixed>
     */
    public function buildReport(array $context, array $records): array
    {
        $syncJobId = trim((string) ($context['sync_job_id'] ?? ''));
        if ($syncJobId === '') {
            throw new \InvalidArgumentException('sync_job_id is required.');
        }

        $items = [];
        $validCount = 0;
        $invalidCount = 0;
        $warningCount = 0;

        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['envelope'], $record['validation'])) {
                continue;
            }

            $envelope = is_array($record['envelope']) ? $record['envelope'] : [];
            $validation = is_array($record['validation']) ? $record['validation'] : [];
            $item = $this->buildItem($envelope, $validation);
            $items[] = $item;

            if (($item['validation_status'] ?? '') === self::VALIDATION_STATUS_VALID) {
                ++$validCount;
            } else {
                ++$invalidCount;
            }

            if (count($item['warnings'] ?? []) > 0) {
                ++$warningCount;
            }
        }

        $totalFiles = count($items);
        $ownerScope = trim((string) ($context['owner_scope'] ?? ''));
        $scanScope = trim((string) ($context['scan_scope'] ?? ''));
        if ($scanScope === '') {
            $scanScope = $this->defaultScanScopeForOwner($ownerScope);
        }

        $status = 'success';
        if ($invalidCount > 0 && $validCount > 0) {
            $status = 'partial';
        } elseif ($invalidCount > 0) {
            $status = 'failed';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sync_job_id' => $syncJobId,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'scan_scope' => $scanScope,
            'tenant_key' => isset($context['tenant_key']) ? (string) $context['tenant_key'] : null,
            'tenant_sno' => isset($context['tenant_sno']) ? (string) $context['tenant_sno'] : null,
            'industry_code' => isset($context['industry_code']) ? (string) $context['industry_code'] : '',
            'owner_scope' => $ownerScope,
            'status' => $status,
            'total_files' => $totalFiles,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'warning_count' => $warningCount,
            'summary' => [
                'files_discovered' => $totalFiles,
                'files_validated' => $validCount,
                'files_failed' => $invalidCount,
                'warnings_count' => $warningCount,
            ],
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public function writeReport(array $report, string $tenantSno): string
    {
        $tenantSno = trim($tenantSno);
        if ($tenantSno === '') {
            throw new \InvalidArgumentException('tenant_sno is required for writeReport.');
        }

        $reportPath = $this->resolveReportPath($tenantSno);
        $metaDir = dirname($reportPath);

        if (!is_dir($metaDir) && !mkdir($metaDir, 0775, true) && !is_dir($metaDir)) {
            throw new \RuntimeException('Failed to create meta directory: ' . $metaDir);
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode drive_sync_report.json.');
        }

        $json .= "\n";

        if (file_put_contents($reportPath, $json) === false) {
            throw new \RuntimeException('Failed to write drive_sync_report.json: ' . $reportPath);
        }

        return $reportPath;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function buildItem(array $envelope, array $validation): array
    {
        $isValid = !empty($validation['ok']);
        $errors = [];
        if (isset($validation['errors']) && is_array($validation['errors'])) {
            foreach ($validation['errors'] as $error) {
                if (is_array($error)) {
                    $errors[] = $error;
                }
            }
        }

        $warnings = [];
        if (isset($envelope['warnings']) && is_array($envelope['warnings'])) {
            foreach ($envelope['warnings'] as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[] = $warning;
                }
            }
        }
        if (isset($validation['warnings']) && is_array($validation['warnings'])) {
            foreach ($validation['warnings'] as $warning) {
                if (is_string($warning) && $warning !== '' && !in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
            }
        }

        return [
            'file_id' => (string) ($envelope['file_id'] ?? ''),
            'file_name' => (string) ($envelope['file_name'] ?? ''),
            'mime_type' => (string) ($envelope['mime_type'] ?? ''),
            'source_path' => (string) ($envelope['source_path'] ?? ''),
            'owner_scope' => (string) ($envelope['owner_scope'] ?? ''),
            'data_category' => (string) ($envelope['data_category'] ?? ''),
            'checksum_method' => (string) ($envelope['checksum_method'] ?? ''),
            'promote_status' => (string) ($envelope['promote_status'] ?? 'pending'),
            'validation_status' => $isValid ? self::VALIDATION_STATUS_VALID : self::VALIDATION_STATUS_INVALID,
            'errors' => $errors,
            'warnings' => array_values($warnings),
        ];
    }

    private function defaultScanScopeForOwner(string $ownerScope): string
    {
        switch ($ownerScope) {
            case 'industry':
                return 'industry_shared';
            case 'global':
                return 'global_shared';
            case 'platform':
                return 'platform_roots';
            case 'tenant':
            default:
                return 'tenant_private';
        }
    }
}
