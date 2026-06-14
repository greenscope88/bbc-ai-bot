<?php
declare(strict_types=1);

/**
 * BDS Phase 7-1c-2a — Upload Portal staging resolver.
 *
 * Resolves tenant upload staging directories and validates upload_session.json
 * against Registry tenant_key / sno before BDS upload mode ingestion.
 *
 * @see docs/BATS_DATA_CONTRACT.md §1.6.7
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md §8.8.6
 */
final class BdsUploadStagingResolver
{
    public const SESSION_ID_PATTERN = '/^UPLOAD-\d{8}-\d{6}-[0-9a-f]{6}$/';

    /** @var string */
    private $uploadsRoot;

    public function __construct(string $uploadsRoot)
    {
        $this->uploadsRoot = rtrim($uploadsRoot, "/\\");
    }

    /**
     * @return array{
     *   ok: bool,
     *   reason?: string,
     *   staging_dir?: string,
     *   session_json_path?: string,
     *   xlsx_path?: string,
     *   session_data?: array<string, mixed>,
     *   stored_filename?: string
     * }
     */
    public function resolve(string $tenantKey, string $tenantSno, string $uploadSessionId): array
    {
        $tenantKey = trim($tenantKey);
        $tenantSno = trim($tenantSno);
        $uploadSessionId = trim($uploadSessionId);

        if ($tenantKey === '' || $tenantSno === '') {
            return ['ok' => false, 'reason' => 'tenant_key and tenant_sno are required'];
        }

        if ($uploadSessionId === '' || preg_match(self::SESSION_ID_PATTERN, $uploadSessionId) !== 1) {
            return ['ok' => false, 'reason' => 'upload_session_id format is invalid'];
        }

        $stagingDir = $this->uploadsRoot
            . DIRECTORY_SEPARATOR . 'tenants'
            . DIRECTORY_SEPARATOR . $tenantSno
            . DIRECTORY_SEPARATOR . 'staging'
            . DIRECTORY_SEPARATOR . $uploadSessionId;

        $sessionJsonPath = $stagingDir . DIRECTORY_SEPARATOR . 'upload_session.json';
        if (!is_dir($stagingDir)) {
            return ['ok' => false, 'reason' => 'staging directory does not exist'];
        }
        if (!is_file($sessionJsonPath)) {
            return ['ok' => false, 'reason' => 'upload_session.json does not exist'];
        }

        $raw = file_get_contents($sessionJsonPath);
        $sessionData = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($sessionData)) {
            return ['ok' => false, 'reason' => 'upload_session.json is invalid JSON'];
        }

        $sessionIdInFile = isset($sessionData['upload_session_id']) ? trim((string) $sessionData['upload_session_id']) : '';
        if ($sessionIdInFile !== $uploadSessionId) {
            return ['ok' => false, 'reason' => 'upload_session_id mismatch in upload_session.json'];
        }

        $sessionTenantKey = isset($sessionData['tenant_key']) ? trim((string) $sessionData['tenant_key']) : '';
        if ($sessionTenantKey === '' || $sessionTenantKey !== $tenantKey) {
            return ['ok' => false, 'reason' => 'tenant_key mismatch in upload_session.json'];
        }

        $sessionTenantSno = isset($sessionData['tenant_sno']) ? trim((string) $sessionData['tenant_sno']) : '';
        if ($sessionTenantSno === '' || $sessionTenantSno !== $tenantSno) {
            return ['ok' => false, 'reason' => 'tenant_sno mismatch in upload_session.json'];
        }

        $storedFilename = isset($sessionData['stored_filename']) ? trim((string) $sessionData['stored_filename']) : '';
        if ($storedFilename === '' || strpos($storedFilename, '/') !== false || strpos($storedFilename, '\\') !== false) {
            $storedFilename = 'knowledge_' . $uploadSessionId . '.xlsx';
        }

        $xlsxPath = $stagingDir . DIRECTORY_SEPARATOR . $storedFilename;
        if (!is_file($xlsxPath)) {
            return ['ok' => false, 'reason' => 'stored_filename does not exist in staging directory'];
        }

        return [
            'ok' => true,
            'staging_dir' => $stagingDir,
            'session_json_path' => $sessionJsonPath,
            'xlsx_path' => $xlsxPath,
            'session_data' => $sessionData,
            'stored_filename' => $storedFilename,
        ];
    }
}
