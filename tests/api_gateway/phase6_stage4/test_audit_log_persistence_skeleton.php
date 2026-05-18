<?php
declare(strict_types=1);

/**
 * Phase 6 Stage 4 — Audit log persistence skeleton (no SQL, no DB writes).
 */

$root = dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR . 'production'
    . DIRECTORY_SEPARATOR;

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap.php';

require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'AuditLogRepositoryInterface.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogPersistenceMode.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogPersistenceConfig.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogPersistenceDisabledException.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogFieldWhitelist.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogSensitiveDataRedactor.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogRowFormatter.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'GatewayAuditLogPersistDto.php';
require_once $root . 'audit' . DIRECTORY_SEPARATOR . 'GuardedAuditLogRepository.php';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

/**
 * @param array<int, string> $patterns
 */
function assertDirPhpExcludesPatterns(string $dir, array $patterns): void
{
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile() || substr($file->getPathname(), -4) !== '.php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            t(false, 'Unreadable ' . $file->getPathname());
            continue;
        }
        $lower = strtolower($contents);
        foreach ($patterns as $p) {
            t(strpos($lower, strtolower($p)) === false, $file->getPathname() . ' must not contain ' . $p);
        }
    }
}

// --- Whitelist shape ---
t(AuditLogFieldWhitelist::allowedKeys() === [
    'trace_id',
    'sno',
    'api_key_prefix',
    'service',
    'request_method',
    'request_path',
    'http_status',
    'error_code',
    'client_ip',
    'user_agent',
    'duration_ms',
    'created_at',
], 'Whitelist must match Phase 6 Stage 4 baseline field set');

// --- Formatter + DTO ---
$raw = [
    'trace_id' => "ab\x00cd",
    'sno' => 'T1',
    'api_key_prefix' => 'gw_',
    'service' => 'tour.search',
    'request_method' => 'get',
    'request_path' => '/gateway/v1',
    'http_status' => 200,
    'error_code' => null,
    'client_ip' => '203.0.113.1',
    'user_agent' => 'Mozilla/5.0 ' . str_repeat('B', 280),
    'duration_ms' => 12,
    'created_at' => '2026-05-14T00:00:00Z',
    'authorization' => 'Bearer super-secret',
    'cookie' => 'sid=secret',
    'password' => 'hunter2',
    'raw_body' => '{"x":1}',
    'api_key' => 'full-key-material',
];

$formatted = AuditLogRowFormatter::formatForPersistence($raw);
t(!isset($formatted['authorization']), 'authorization must be stripped');
t(!isset($formatted['password']), 'password must be stripped');
t(!isset($formatted['raw_body']), 'raw_body must be stripped');
t(!isset($formatted['api_key']), 'full api_key must be stripped');
t(isset($formatted['api_key_prefix']) && $formatted['api_key_prefix'] === 'gw_', 'api_key_prefix must survive redaction');
t(isset($formatted['trace_id']) && strpos($formatted['trace_id'], "\x00") === false, 'control chars must be stripped from trace_id');
t(isset($formatted['user_agent']) && strlen((string) $formatted['user_agent']) === 256, 'user_agent must be truncated');

$dto = GatewayAuditLogPersistDto::fromLooseRecord($raw);
$arr = $dto->toArray();
foreach (array_keys($arr) as $k) {
    t(AuditLogFieldWhitelist::isAllowedKey($k), 'DTO keys must stay on whitelist: ' . $k);
}

// --- Guarded repository ---
$disabledRepo = new GuardedAuditLogRepository(new AuditLogPersistenceConfig(AuditLogPersistenceMode::DISABLED));
$disabledThrew = false;
try {
    $disabledRepo->append(['trace_id' => 't1']);
} catch (AuditLogPersistenceDisabledException $e) {
    $disabledThrew = true;
}
t($disabledThrew, 'Disabled mode must throw AuditLogPersistenceDisabledException');

$dryRepo = new GuardedAuditLogRepository(new AuditLogPersistenceConfig(AuditLogPersistenceMode::DRY_RUN));
$dryOk = false;
try {
    $dryRepo->append(['trace_id' => 't2', 'http_status' => 201]);
    $dryOk = true;
} catch (Throwable $e) {
    $dryOk = false;
}
t($dryOk, 'dry_run mode must complete without throwing');

$liveRepo = new GuardedAuditLogRepository(new AuditLogPersistenceConfig(AuditLogPersistenceMode::LIVE));
$liveThrew = false;
try {
    $liveRepo->append(['trace_id' => 't3']);
} catch (RuntimeException $e) {
    $liveThrew = strpos($e->getMessage(), 'Phase 6 Stage 4') !== false;
}
t($liveThrew, 'live mode must throw RuntimeException (no DB in this stage)');

$iface = new ReflectionClass(AuditLogRepositoryInterface::class);
$append = $iface->getMethod('append');
t($append->getNumberOfParameters() === 1, 'append must accept one record parameter');

$repoClass = new ReflectionClass(GuardedAuditLogRepository::class);
t($repoClass->implementsInterface(AuditLogRepositoryInterface::class), 'GuardedAuditLogRepository must implement AuditLogRepositoryInterface');

// --- No SQL execution markers in production audit layer ---
$patterns = ['insert into', 'update ', 'delete ', 'pdo::exec', 'pdo::query', 'pdo::prepare'];
assertDirPhpExcludesPatterns($root . 'audit', $patterns);

if ($failures > 0) {
    fwrite(STDERR, "Phase 6 Stage 4 audit persistence skeleton tests failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "Phase 6 Stage 4 audit persistence skeleton tests passed.\n");
exit(0);
