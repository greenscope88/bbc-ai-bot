<?php
declare(strict_types=1);

/**
 * BDS Raw Excel Host A Retention — TTL Maintenance CLI (Phase V24B).
 *
 * Scans the canonical BDS staging root and, for sessions whose metadata
 * proves a failed / abandoned / cleanup-failed outcome that is at least
 * 24 hours old, delegates raw Excel removal to the single Cleanup Owner
 * (BdsUploadStagingRetentionCleaner). Never deletes directly.
 *
 * Modes:
 *   php bds-upload-staging-retention.php            -> dry-run (default, no deletion)
 *   php bds-upload-staging-retention.php --execute   -> performs eligible deletions
 *
 * This script must NOT be scheduled or executed as part of this phase; it is
 * coding-only and is validated via php -l plus source contract review.
 */

require_once __DIR__ . '/../core/bds/BdsUploadStagingRetentionCleaner.php';

const BDS_RETENTION_TTL_SECONDS = 24 * 60 * 60;
const BDS_RETENTION_SESSION_ID_PATTERN = '/^UPLOAD-\d{8}-\d{6}-[0-9a-f]{6}$/';
const BDS_RETENTION_SAFE_SEGMENT_PATTERN = '/^[A-Za-z0-9_.-]+$/';

/**
 * @return array{execute: bool}
 */
function bdsRetentionParseArgv(array $argv): array
{
    $execute = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $execute = true;
        }
    }

    return array('execute' => $execute);
}

function bdsRetentionIsSafeSegment(string $value): bool
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

    return preg_match(BDS_RETENTION_SAFE_SEGMENT_PATTERN, $value) === 1;
}

/**
 * Prove (without any filesystem access) whether a session's own metadata
 * declares a safe, server-generated .xlsx/.xls stored_filename.
 */
function bdsRetentionHasProvenExcelFilename(array $sessionData): bool
{
    $storedFilename = isset($sessionData['stored_filename']) ? trim((string) $sessionData['stored_filename']) : '';
    if (!bdsRetentionIsSafeSegment($storedFilename)) {
        return false;
    }

    $extension = strtolower((string) pathinfo($storedFilename, PATHINFO_EXTENSION));

    return $extension === 'xlsx' || $extension === 'xls';
}

/**
 * Resolve the raw Excel path implied by a session's own metadata, without
 * touching the filesystem. Returns null when the metadata does not prove a
 * safe, server-generated .xlsx/.xls filename.
 */
function bdsRetentionRawExcelPath(string $sessionDir, array $sessionData): ?string
{
    if (!bdsRetentionHasProvenExcelFilename($sessionData)) {
        return null;
    }

    return $sessionDir . DIRECTORY_SEPARATOR . trim((string) $sessionData['stored_filename']);
}

/**
 * Read-only existence probe used only to feed classification input. This
 * never deletes anything; actual deletion remains solely the responsibility
 * of BdsUploadStagingRetentionCleaner::removeRawExcel().
 */
function bdsRetentionRawExcelExists(string $sessionDir, array $sessionData): bool
{
    $path = bdsRetentionRawExcelPath($sessionDir, $sessionData);

    return $path !== null && is_file($path);
}

/**
 * Determine whether a session's own metadata proves a failed / abandoned /
 * success-raw (historical-or-retry) outcome eligible for TTL-based cleanup,
 * and the reference timestamp to measure age from. Fails closed (not
 * eligible) on any unproven or ambiguous metadata.
 *
 * Success-category eligibility (Phase V24E) covers a sync_success session
 * whose raw Excel cleanup was either never attempted (historical, no
 * raw_excel_cleanup record) or previously failed (raw_excel_cleanup.status
 * === 'failed'). It requires success to be corroborated by multiple
 * non-contradictory fields, a server-generated .xlsx/.xls stored_filename,
 * AND the raw Excel proven to still exist under this canonical session
 * directory. A cleanup already recorded as 'removed', or any other/unknown
 * cleanup status, is never eligible here regardless of raw existence.
 *
 * @return array{eligible: bool, reference_at: ?int, skip_reason: string}
 */
function bdsRetentionClassifySession(array $sessionData, bool $rawExcelExists): array
{
    $status = isset($sessionData['status']) ? (string) $sessionData['status'] : '';
    $syncTriggered = isset($sessionData['sync_triggered']) ? (bool) $sessionData['sync_triggered'] : null;
    $cleanupStatus = null;
    if (isset($sessionData['raw_excel_cleanup']) && is_array($sessionData['raw_excel_cleanup'])) {
        $cleanupStatus = isset($sessionData['raw_excel_cleanup']['status'])
            ? (string) $sessionData['raw_excel_cleanup']['status']
            : null;
    }

    $isFailedSync = $status === 'sync_failed';
    $isAbandoned = $status === 'received_only' && $syncTriggered === false;

    $isSuccessCategoryCandidate = $status === 'sync_success' && ($cleanupStatus === null || $cleanupStatus === 'failed');
    $isEligibleSuccessCategory = false;
    $successFailClosedReason = 'status_not_eligible';

    if ($isSuccessCategoryCandidate) {
        $syncId = isset($sessionData['sync_id']) ? trim((string) $sessionData['sync_id']) : '';
        $syncCompletedAt = isset($sessionData['sync_completed_at']) ? trim((string) $sessionData['sync_completed_at']) : '';
        $hasCorroboratedSuccessEvidence = $syncTriggered === true
            && $syncId !== ''
            && $syncCompletedAt !== ''
            && strtotime($syncCompletedAt) !== false;

        $hasProvenExcelFilename = bdsRetentionHasProvenExcelFilename($sessionData);

        if (!$hasCorroboratedSuccessEvidence) {
            $successFailClosedReason = 'success_evidence_unproven';
        } elseif (!$hasProvenExcelFilename) {
            $successFailClosedReason = 'success_stored_filename_unproven';
        } elseif ($rawExcelExists !== true) {
            $successFailClosedReason = 'success_raw_excel_absent';
        } else {
            $isEligibleSuccessCategory = true;
        }
    }

    if (!$isFailedSync && !$isAbandoned && !$isEligibleSuccessCategory) {
        $reason = $isSuccessCategoryCandidate ? $successFailClosedReason : 'status_not_eligible';

        return array('eligible' => false, 'reference_at' => null, 'skip_reason' => $reason);
    }

    $referenceAt = null;
    $candidates = array();
    if (isset($sessionData['raw_excel_cleanup']) && is_array($sessionData['raw_excel_cleanup'])
        && isset($sessionData['raw_excel_cleanup']['attempted_at'])) {
        $candidates[] = (string) $sessionData['raw_excel_cleanup']['attempted_at'];
    }
    if (isset($sessionData['sync_completed_at'])) {
        $candidates[] = (string) $sessionData['sync_completed_at'];
    }
    if (isset($sessionData['cli_sync_at'])) {
        $candidates[] = (string) $sessionData['cli_sync_at'];
    }
    if (isset($sessionData['uploaded_at'])) {
        $candidates[] = (string) $sessionData['uploaded_at'];
    }

    foreach ($candidates as $candidate) {
        $ts = strtotime($candidate);
        if ($ts !== false) {
            $referenceAt = $ts;
            break;
        }
    }

    if ($referenceAt === null) {
        return array('eligible' => false, 'reference_at' => null, 'skip_reason' => 'reference_timestamp_missing');
    }

    return array('eligible' => true, 'reference_at' => $referenceAt, 'skip_reason' => '');
}

/**
 * Persist the CLI-driven cleanup outcome into upload_session.json without
 * touching sync status, session identity, or any other field.
 */
function bdsRetentionRecordCleanupResult(string $sessionJsonPath, array $sessionData, array $cleanupResult): bool
{
    $ok = isset($cleanupResult['ok']) && $cleanupResult['ok'] === true;
    $reason = isset($cleanupResult['reason']) ? (string) $cleanupResult['reason'] : 'unknown';

    $sessionData['raw_excel_cleanup'] = array(
        'status' => $ok ? 'removed' : 'failed',
        'reason' => $reason,
        'attempted_at' => gmdate('c'),
    );
    if ($ok) {
        $sessionData['raw_excel_cleanup']['removed_at'] = gmdate('c');
    }

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $encoded = json_encode($sessionData, $jsonFlags);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($sessionJsonPath, $encoded) !== false;
}

/**
 * @return array{summary: array<string, int>, reasons: array<string, int>}
 */
function bdsRetentionRun(string $uploadsRoot, bool $execute, ?BdsUploadStagingRetentionCleaner $cleaner = null): array
{
    $cleaner = $cleaner ?? new BdsUploadStagingRetentionCleaner($uploadsRoot);

    $summary = array(
        'scanned' => 0,
        'eligible' => 0,
        'removed' => 0,
        'skipped' => 0,
        'failed' => 0,
    );
    $reasons = array();

    $tenantsRoot = rtrim($uploadsRoot, "/\\") . DIRECTORY_SEPARATOR . 'tenants';
    if (!is_dir($tenantsRoot)) {
        return array('summary' => $summary, 'reasons' => $reasons);
    }

    $tenantDirs = glob($tenantsRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: array();
    foreach ($tenantDirs as $tenantDir) {
        $tenantSno = basename($tenantDir);
        if (!bdsRetentionIsSafeSegment($tenantSno)) {
            continue;
        }

        $stagingRoot = $tenantDir . DIRECTORY_SEPARATOR . 'staging';
        if (!is_dir($stagingRoot)) {
            continue;
        }

        $sessionDirs = glob($stagingRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: array();
        foreach ($sessionDirs as $sessionDir) {
            $sessionId = basename($sessionDir);
            $summary['scanned']++;

            $bump = function (string $reasonKey) use (&$reasons) {
                $reasons[$reasonKey] = ($reasons[$reasonKey] ?? 0) + 1;
            };

            if (preg_match(BDS_RETENTION_SESSION_ID_PATTERN, $sessionId) !== 1) {
                $summary['skipped']++;
                $bump('invalid_session_id_format');
                continue;
            }

            $sessionJsonPath = $sessionDir . DIRECTORY_SEPARATOR . 'upload_session.json';
            if (!is_file($sessionJsonPath)) {
                $summary['skipped']++;
                $bump('session_metadata_missing');
                continue;
            }

            $raw = file_get_contents($sessionJsonPath);
            $sessionData = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($sessionData)) {
                $summary['skipped']++;
                $bump('session_metadata_invalid');
                continue;
            }

            $metaSessionId = isset($sessionData['upload_session_id']) ? trim((string) $sessionData['upload_session_id']) : '';
            $metaTenantSno = isset($sessionData['tenant_sno']) ? trim((string) $sessionData['tenant_sno']) : '';
            if ($metaSessionId === '' || $metaSessionId !== $sessionId || $metaTenantSno === '' || $metaTenantSno !== $tenantSno) {
                $summary['skipped']++;
                $bump('identity_mismatch');
                continue;
            }

            $rawExcelExists = bdsRetentionRawExcelExists($sessionDir, $sessionData);
            $classification = bdsRetentionClassifySession($sessionData, $rawExcelExists);
            if (!$classification['eligible']) {
                $summary['skipped']++;
                $bump($classification['skip_reason']);
                continue;
            }

            $ageSeconds = time() - $classification['reference_at'];
            if ($ageSeconds < BDS_RETENTION_TTL_SECONDS) {
                $summary['skipped']++;
                $bump('ttl_not_elapsed');
                continue;
            }

            $summary['eligible']++;

            if (!$execute) {
                $summary['skipped']++;
                $bump('dry_run_not_executed');
                continue;
            }

            $cleanupResult = $cleaner->removeRawExcel($tenantSno, $sessionId);
            bdsRetentionRecordCleanupResult($sessionJsonPath, $sessionData, $cleanupResult);

            if (!empty($cleanupResult['ok'])) {
                $summary['removed']++;
                $bump('removed_' . (string) $cleanupResult['reason']);
            } else {
                $summary['failed']++;
                $bump('delete_failed_' . (string) $cleanupResult['reason']);
            }
        }
    }

    return array('summary' => $summary, 'reasons' => $reasons);
}

/**
 * Determine the process exit code purely from the summary's own 'failed'
 * count, so Windows Task Scheduler's LastTaskResult can distinguish a clean
 * run from one that left cleanup failures behind. Any missing, non-integer,
 * or negative shape fails closed to the failure exit code rather than
 * silently reporting success.
 */
function bdsRetentionExitCode(array $result): int
{
    if (!isset($result['summary']) || !is_array($result['summary'])) {
        return 2;
    }

    if (!array_key_exists('failed', $result['summary'])) {
        return 2;
    }

    $failed = $result['summary']['failed'];
    if (!is_int($failed) || $failed < 0) {
        return 2;
    }

    return $failed === 0 ? 0 : 2;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $options = bdsRetentionParseArgv($argv);
    $uploadsRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'uploads';

    $result = bdsRetentionRun($uploadsRoot, $options['execute']);

    fwrite(STDOUT, 'BDS Raw Excel Staging Retention — mode=' . ($options['execute'] ? 'EXECUTE' : 'DRY-RUN') . "\n");
    fwrite(STDOUT, 'TTL: ' . BDS_RETENTION_TTL_SECONDS . " seconds (24h)\n");
    foreach ($result['summary'] as $key => $value) {
        fwrite(STDOUT, str_pad($key, 12) . ': ' . $value . "\n");
    }
    if (!empty($result['reasons'])) {
        fwrite(STDOUT, "reasons:\n");
        foreach ($result['reasons'] as $reasonKey => $count) {
            fwrite(STDOUT, '  ' . $reasonKey . ': ' . $count . "\n");
        }
    }

    exit(bdsRetentionExitCode($result));
}
