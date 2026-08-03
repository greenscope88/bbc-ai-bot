<?php
declare(strict_types=1);

/**
 * BDS Raw Excel Host A Retention — sole cleanup owner (Phase V24B).
 *
 * Host A must never retain raw tenant Excel permanently. This class is the
 * ONLY component allowed to delete a raw uploaded Excel file. It never
 * deletes the Upload Session directory, upload_session.json, validation
 * results, or sync reports.
 *
 * Deletion is scoped strictly to:
 *   var/bds/uploads/tenants/{resolved sno}/staging/{validated upload-session-id}/{server-generated stored filename}
 *
 * Identity is proven only from:
 *   - a resolved tenant sno supplied by the caller (already resolved via the
 *     V21 BDS Authority — this class does not resolve tenant identity itself)
 *   - a validated Upload Session ID matching the canonical session pattern
 *   - the session's own upload_session.json (tenant_sno / upload_session_id /
 *     stored_filename), which must agree with the caller-supplied identity
 *
 * Any missing, mismatched, or unprovable identity/path condition fails
 * closed (no deletion). Deleting an already-absent file is treated as an
 * idempotent success.
 */
final class BdsUploadStagingRetentionCleaner
{
    private const SESSION_ID_PATTERN = '/^UPLOAD-\d{8}-\d{6}-[0-9a-f]{6}$/';
    private const SAFE_SEGMENT_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    /** @var string */
    private $uploadsRoot;

    public function __construct(?string $uploadsRoot = null)
    {
        $default = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'uploads';
        $this->uploadsRoot = rtrim($uploadsRoot ?? $default, "/\\");
    }

    /**
     * Remove only the raw Excel for one resolved tenant sno + validated Upload Session.
     *
     * @return array{ok: bool, removed: bool, reason: string, session_id: string}
     */
    public function removeRawExcel(string $tenantSno, string $uploadSessionId): array
    {
        $tenantSno = trim($tenantSno);
        $uploadSessionId = trim($uploadSessionId);

        if (!$this->isSafeSegment($tenantSno)) {
            return $this->result(false, false, 'invalid_tenant_sno', $uploadSessionId);
        }
        if ($uploadSessionId === '' || preg_match(self::SESSION_ID_PATTERN, $uploadSessionId) !== 1) {
            return $this->result(false, false, 'invalid_session_id', $uploadSessionId);
        }

        $stagingRootReal = realpath($this->uploadsRoot);
        if ($stagingRootReal === false) {
            return $this->result(false, false, 'staging_root_missing', $uploadSessionId);
        }

        $sessionDir = $this->uploadsRoot . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
            . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $uploadSessionId;

        $sessionDirReal = realpath($sessionDir);
        if ($sessionDirReal === false) {
            return $this->result(false, false, 'session_dir_missing', $uploadSessionId);
        }

        $expectedSessionDir = $stagingRootReal . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSno
            . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $uploadSessionId;
        if (rtrim($sessionDirReal, "/\\") !== rtrim($expectedSessionDir, "/\\")) {
            return $this->result(false, false, 'path_boundary_violation', $uploadSessionId);
        }
        if (is_link($sessionDir)) {
            return $this->result(false, false, 'path_boundary_violation', $uploadSessionId);
        }

        $sessionJsonPath = $sessionDir . DIRECTORY_SEPARATOR . 'upload_session.json';
        if (!is_file($sessionJsonPath)) {
            return $this->result(false, false, 'session_metadata_missing', $uploadSessionId);
        }

        $raw = file_get_contents($sessionJsonPath);
        $sessionData = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($sessionData)) {
            return $this->result(false, false, 'session_metadata_invalid', $uploadSessionId);
        }

        $metaSessionId = isset($sessionData['upload_session_id']) ? trim((string) $sessionData['upload_session_id']) : '';
        if ($metaSessionId === '' || $metaSessionId !== $uploadSessionId) {
            return $this->result(false, false, 'session_id_mismatch', $uploadSessionId);
        }

        $metaTenantSno = isset($sessionData['tenant_sno']) ? trim((string) $sessionData['tenant_sno']) : '';
        if ($metaTenantSno === '' || $metaTenantSno !== $tenantSno) {
            return $this->result(false, false, 'tenant_sno_mismatch', $uploadSessionId);
        }

        $storedFilename = isset($sessionData['stored_filename']) ? trim((string) $sessionData['stored_filename']) : '';
        if (!$this->isSafeSegment($storedFilename)) {
            return $this->result(false, false, 'stored_filename_invalid', $uploadSessionId);
        }

        $extension = strtolower((string) pathinfo($storedFilename, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx' && $extension !== 'xls') {
            return $this->result(false, false, 'stored_filename_not_excel', $uploadSessionId);
        }

        $rawExcelPath = $sessionDir . DIRECTORY_SEPARATOR . $storedFilename;

        if (!is_file($rawExcelPath) && !is_link($rawExcelPath)) {
            // Nothing to remove: idempotent success (end state already satisfied).
            return $this->result(true, false, 'already_removed', $uploadSessionId);
        }

        if (is_link($rawExcelPath)) {
            return $this->result(false, false, 'symlink_not_allowed', $uploadSessionId);
        }

        $rawExcelReal = realpath($rawExcelPath);
        if ($rawExcelReal === false) {
            return $this->result(false, false, 'raw_excel_path_unresolvable', $uploadSessionId);
        }
        if (strpos($rawExcelReal, $sessionDirReal . DIRECTORY_SEPARATOR) !== 0) {
            return $this->result(false, false, 'path_boundary_violation', $uploadSessionId);
        }

        if (!@unlink($rawExcelPath)) {
            return $this->result(false, false, 'delete_failed', $uploadSessionId);
        }

        return $this->result(true, true, 'removed', $uploadSessionId);
    }

    private function isSafeSegment(string $value): bool
    {
        if ($value === '' || $value === '.' || $value === '..') {
            return false;
        }
        if (strpos($value, '..') !== false) {
            return false;
        }
        if (strpos($value, '/') !== false || strpos($value, '\\') !== false || strpos($value, "\0") !== false) {
            return false;
        }

        return preg_match(self::SAFE_SEGMENT_PATTERN, $value) === 1;
    }

    /**
     * @return array{ok: bool, removed: bool, reason: string, session_id: string}
     */
    private function result(bool $ok, bool $removed, string $reason, string $sessionId): array
    {
        return [
            'ok' => $ok,
            'removed' => $removed,
            'reason' => $reason,
            'session_id' => $sessionId,
        ];
    }
}
