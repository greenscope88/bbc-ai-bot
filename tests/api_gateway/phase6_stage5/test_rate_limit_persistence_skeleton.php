<?php
declare(strict_types=1);

/**
 * Phase 6 Stage 5 — Rate limit persistence skeleton (no SQL, no Redis, no DB).
 */

$root = dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR . 'production'
    . DIRECTORY_SEPARATOR;

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap.php';

require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'RateLimitPersistenceResult.php';
require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'RateLimitCounterPersistenceInterface.php';
require_once $root . 'rate_limit' . DIRECTORY_SEPARATOR . 'GuardedRateLimitCounterRepository.php';
require_once $root . 'rate_limit' . DIRECTORY_SEPARATOR . 'GatewayRateLimitPersistDto.php';

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
    if (!is_dir($dir)) {
        t(false, 'Missing directory ' . $dir);
        return;
    }
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

t(RateLimitFieldWhitelist::allowedKeys() === [
    'sno',
    'api_key_id',
    'api_key_prefix',
    'service',
    'client_ip',
    'limit_per_minute',
    'limit_per_hour',
    'limit_per_day',
    'burst_limit',
    'window_key',
    'current_count',
    'reset_at',
], 'Whitelist must match Phase 6 Stage 5 baseline field set');

$raw = [
    'sno' => 'TEN1',
    'api_key_id' => 99,
    'api_key_prefix' => 'gw_',
    'service' => 'tour.search',
    'client_ip' => '198.51.100.2',
    'limit_per_minute' => 60,
    'limit_per_hour' => 1000,
    'limit_per_day' => 5000,
    'burst_limit' => 5,
    'window_key' => 'burst:slot-1',
    'current_count' => 5,
    'reset_at' => '2026-05-14T00:00:00Z',
    'authorization' => 'Bearer secret',
    'cookie' => 'a=b',
    'api_key' => 'full-secret-key',
    'request_body' => '{"x":1}',
];

$dto = GatewayRateLimitPersistDto::fromLooseContext($raw);
$arr = $dto->toArray();
t(!isset($arr['authorization']), 'authorization must be stripped');
t(!isset($arr['cookie']), 'cookie must be stripped');
t(!isset($arr['api_key']), 'raw api_key must be stripped');
t(!isset($arr['request_body']), 'request_body must be stripped');
t(isset($arr['api_key_id']) && $arr['api_key_id'] === 99, 'api_key_id must be preserved');
t(isset($arr['api_key_prefix']) && $arr['api_key_prefix'] === 'gw_', 'api_key_prefix must be preserved');
foreach (array_keys($arr) as $k) {
    t(RateLimitFieldWhitelist::isAllowedKey($k), 'DTO keys must stay on whitelist: ' . $k);
}

$iface = new ReflectionClass(RateLimitCounterPersistenceInterface::class);
$m = $iface->getMethod('evaluateConsumption');
t($m->getNumberOfParameters() === 1, 'evaluateConsumption must accept one context parameter');
$rt = (string) $m->getReturnType();
t($rt === RateLimitPersistenceResult::class, 'evaluateConsumption must return RateLimitPersistenceResult');

$repoClass = new ReflectionClass(GuardedRateLimitCounterRepository::class);
t($repoClass->implementsInterface(RateLimitCounterPersistenceInterface::class), 'Guarded repository must implement persistence interface');

$disabledRepo = new GuardedRateLimitCounterRepository(new RateLimitPersistenceConfig(RateLimitPersistenceMode::DISABLED));
$res = $disabledRepo->evaluateConsumption($raw);
t($res->wouldAllow() === true && $res->wouldReject() === false, 'disabled mode must allow (no persisted enforcement)');
t($res->skippedDueToDisabled() === true, 'disabled mode must mark skipped');

$dryRepo = new GuardedRateLimitCounterRepository(new RateLimitPersistenceConfig(RateLimitPersistenceMode::DRY_RUN));
$dryReject = $dryRepo->evaluateConsumption($raw);
t($dryReject->wouldReject() === true, 'dry_run must reject when count >= effective limit');

$rawAllow = $raw;
$rawAllow['current_count'] = 2;
$dryAllow = $dryRepo->evaluateConsumption($rawAllow);
t($dryAllow->wouldAllow() === true && $dryAllow->wouldReject() === false, 'dry_run must allow when under limit');

$liveRepo = new GuardedRateLimitCounterRepository(new RateLimitPersistenceConfig(RateLimitPersistenceMode::LIVE));
$liveThrew = false;
try {
    $liveRepo->evaluateConsumption($rawAllow);
} catch (RuntimeException $e) {
    $liveThrew = strpos($e->getMessage(), 'Phase 6 Stage 5') !== false;
}
t($liveThrew, 'live mode must throw RuntimeException (no store I/O in this stage)');

$patterns = [
    'insert into',
    'update ',
    'delete ',
    'pdo::exec',
    'pdo::query',
    'pdo::prepare',
    'redis_connect',
    'new redis(',
];
assertDirPhpExcludesPatterns($root . 'rate_limit', $patterns);

if ($failures > 0) {
    fwrite(STDERR, "Phase 6 Stage 5 rate limit persistence skeleton tests failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "Phase 6 Stage 5 rate limit persistence skeleton tests passed.\n");
exit(0);
