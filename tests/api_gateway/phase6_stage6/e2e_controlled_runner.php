<?php
declare(strict_types=1);

/**
 * Phase 6 Stage 6 — Controlled E2E harness for production skeletons (Stages 1–5 wiring).
 * No SQL, no DB/Redis writes, no Host B HTTP, no edits to isolated/ or htdocs entry.
 */

$root = dirname(__DIR__, 3);
$production = $root
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR . 'production'
    . DIRECTORY_SEPARATOR;
$apiGatewayCore = $root
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR;

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
 * @param array<int, string> $patterns lower-case needle substrings
 */
function assertProductionPhpExcludesPatterns(string $baseDir, array $patterns): void
{
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (substr($path, -4) !== '.php') {
            continue;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            t(false, 'Unreadable ' . $path);
            continue;
        }
        $lower = strtolower($contents);
        foreach ($patterns as $p) {
            t(strpos($lower, $p) === false, $path . ' must not contain "' . $p . '"');
        }
    }
}

// --- Bootstrap + gateway config (Stage 1 config context) ---
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

t(function_exists('app_config_get'), 'bootstrap must define app_config_get');
t(is_array(app_config()), 'app_config() must return array');
t(is_array(app_config_get('gateway', [])), 'gateway config section must exist');
t(array_key_exists('host_b', (array) app_config_get('gateway', [])), 'gateway.host_b must exist');
t(array_key_exists('audit_log', (array) app_config_get('gateway', [])), 'gateway.audit_log must exist');
t(array_key_exists('rate_limit', (array) app_config_get('gateway', [])), 'gateway.rate_limit must exist');

// --- Stage 1: HTTP entry smoke (read-only if present) + core gateway context ---
$htdocsEntry = 'C:\\Web\\xampp\\htdocs\\www\\api\\gateway\\index.php';
if (is_file($htdocsEntry)) {
    $head = @file_get_contents($htdocsEntry, false, null, 0, 64);
    t($head !== false && strpos($head, '<?php') === 0, 'HTTP entry file should be PHP when present (read-only smoke)');
} else {
    t(true, 'HTTP entry path not present on this host; skipping file smoke (not a failure)');
}

require_once $apiGatewayCore . 'TraceIdMiddleware.php';
require_once $apiGatewayCore . 'ErrorResponseBuilder.php';
require_once $apiGatewayCore . 'GatewayKernel.php';

$ctx = [];
TraceIdMiddleware::apply($ctx, []);
t(isset($ctx['traceId']) && is_string($ctx['traceId']) && $ctx['traceId'] !== '', 'TraceIdMiddleware must populate traceId');

$built = ErrorResponseBuilder::build(false, 'E_E2E', 'controlled', 'trace-e2e-1', null);
t(isset($built['traceId']) && $built['traceId'] === 'trace-e2e-1', 'unified error envelope must include traceId');
foreach (['success', 'errorCode', 'message', 'traceId', 'timestamp', 'details'] as $k) {
    t(array_key_exists($k, $built), 'error envelope must include key: ' . $k);
}

$gk = new ReflectionClass(GatewayKernel::class);
t($gk->hasMethod('execute'), 'GatewayKernel must expose execute() for pipeline context');

// --- Stage 2: SQL repository skeletons (no DB) ---
require_once $production . 'GatewaySqlConnectionConfig.php';
require_once $production . 'SqlGatewayConnectionFactory.php';
require_once $production . 'contracts' . DIRECTORY_SEPARATOR . 'TenantMappingRepositoryInterface.php';
require_once $production . 'contracts' . DIRECTORY_SEPARATOR . 'AuditLogRepositoryInterface.php';
require_once $production . 'repositories' . DIRECTORY_SEPARATOR . 'SqlTenantMappingRepository.php';
require_once $production . 'repositories' . DIRECTORY_SEPARATOR . 'SqlAuditLogRepository.php';

$factoryThrew = false;
try {
    (new SqlGatewayConnectionFactory(null))->createReadOnlyPdo();
} catch (RuntimeException $e) {
    $factoryThrew = strpos($e->getMessage(), 'Phase 6 Stage 2') !== false;
}
t($factoryThrew, 'SqlGatewayConnectionFactory must refuse PDO creation (Stage 2 skeleton)');

$sqlTenantThrew = false;
try {
    (new SqlTenantMappingRepository())->findBySno('any');
} catch (RuntimeException $e) {
    $sqlTenantThrew = strpos($e->getMessage(), 'Phase 6 Stage 2') !== false;
}
t($sqlTenantThrew, 'SqlTenantMappingRepository must throw Stage 2 skeleton (no SQL)');

$sqlAuditThrew = false;
try {
    (new SqlAuditLogRepository())->append(['trace_id' => 'x']);
} catch (RuntimeException $e) {
    $sqlAuditThrew = strpos($e->getMessage(), 'Phase 6 Stage 2') !== false;
}
t($sqlAuditThrew, 'SqlAuditLogRepository must throw Stage 2 skeleton (no SQL)');

// --- Stage 3: Host B HTTP client (disabled, no transport) ---
require_once $production . 'contracts' . DIRECTORY_SEPARATOR . 'HostBHttpRawResponse.php';
require_once $production . 'contracts' . DIRECTORY_SEPARATOR . 'HostBHttpClientInterface.php';
require_once $production . 'http' . DIRECTORY_SEPARATOR . 'HostBHttpDisabledException.php';
require_once $production . 'http' . DIRECTORY_SEPARATOR . 'HostBGatewayHttpConfig.php';
require_once $production . 'http' . DIRECTORY_SEPARATOR . 'HostBHttpClient.php';

$hbDisabled = new HostBHttpClient(new HostBGatewayHttpConfig(false, 'https://mock.invalid', 3, 10));
$hbThrew = false;
try {
    $hbDisabled->send('GET', 'https://mock.invalid/x', [], '');
} catch (HostBHttpDisabledException $e) {
    $hbThrew = true;
}
t($hbThrew, 'HostBHttpClient must reject when disabled (no outbound request)');

$hbClientSrc = file_get_contents($production . 'http' . DIRECTORY_SEPARATOR . 'HostBHttpClient.php');
t($hbClientSrc !== false && strpos($hbClientSrc, 'curl_') === false, 'HostBHttpClient must not reference curl_*');

// --- Stage 4: Audit persistence (disabled / dry_run, no DB) ---
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogPersistenceMode.php';
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogPersistenceConfig.php';
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogPersistenceDisabledException.php';
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogFieldWhitelist.php';
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogSensitiveDataRedactor.php';
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'AuditLogRowFormatter.php';
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'GatewayAuditLogPersistDto.php';
require_once $production . 'audit' . DIRECTORY_SEPARATOR . 'GuardedAuditLogRepository.php';

$auditDisabled = new GuardedAuditLogRepository(new AuditLogPersistenceConfig(AuditLogPersistenceMode::DISABLED));
$auditDisabledThrew = false;
try {
    $auditDisabled->append(['trace_id' => 't-audit']);
} catch (AuditLogPersistenceDisabledException $e) {
    $auditDisabledThrew = true;
}
t($auditDisabledThrew, 'audit persistence disabled must throw safe exception');

$auditDry = new GuardedAuditLogRepository(new AuditLogPersistenceConfig(AuditLogPersistenceMode::DRY_RUN));
$auditDryOk = false;
try {
    $auditDry->append(['trace_id' => 't-audit-2', 'http_status' => 200]);
    $auditDryOk = true;
} catch (Throwable $e) {
    $auditDryOk = false;
}
t($auditDryOk, 'audit dry_run must complete without DB write');

$auditRow = AuditLogRowFormatter::formatForPersistence([
    'trace_id' => 'tid',
    'authorization' => 'Bearer leak',
    'http_status' => 400,
]);
t(!isset($auditRow['authorization']), 'audit formatter must not retain Authorization');

// --- Stage 5: Rate limit persistence (disabled / dry_run, no store) ---
require_once $production . 'rate_limit' . DIRECTORY_SEPARATOR . 'GuardedRateLimitCounterRepository.php';

$rlDisabled = new GuardedRateLimitCounterRepository(new RateLimitPersistenceConfig(RateLimitPersistenceMode::DISABLED));
$rlDis = $rlDisabled->evaluateConsumption(['sno' => 'S1', 'current_count' => 999, 'burst_limit' => 1, 'window_key' => 'burst:x']);
t($rlDis->wouldAllow() && !$rlDis->wouldReject() && $rlDis->skippedDueToDisabled(), 'rate limit disabled must pass-through without store');

$rlDry = new GuardedRateLimitCounterRepository(new RateLimitPersistenceConfig(RateLimitPersistenceMode::DRY_RUN));
$rlDryRes = $rlDry->evaluateConsumption([
    'sno' => 'S1',
    'burst_limit' => 5,
    'window_key' => 'burst:y',
    'current_count' => 5,
]);
t($rlDryRes->wouldReject(), 'rate limit dry_run must compute would_reject without Redis/DB');

// --- Sensitive data: Host B normalizer ---
require_once $production . 'http' . DIRECTORY_SEPARATOR . 'HostBResponseNormalizer.php';
$norm = HostBResponseNormalizer::decodeJsonBody(
    json_encode(['stackTrace' => 'at X.cs:line 1', 'ok' => true], JSON_THROW_ON_ERROR),
    'trace-norm'
);
t(!isset($norm['stackTrace']), 'HostBResponseNormalizer must drop stackTrace from client-visible payload');

// --- Production tree security scan ---
$patterns = [
    'insert into',
    'update ',
    'delete ',
    'pdo::exec',
    'pdo::query',
    'pdo::prepare',
    'curl_',
    'file_get_contents',
    'fsockopen',
    'stream_socket_client',
    'redis_connect',
    'new redis',
    'http://103.1.222.11:8080',
];
assertProductionPhpExcludesPatterns($production, $patterns);

if ($failures > 0) {
    fwrite(STDERR, "Phase 6 Stage 6 controlled E2E runner failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "Phase 6 Stage 6 controlled E2E runner passed.\n");
exit(0);
