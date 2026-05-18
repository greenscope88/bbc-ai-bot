<?php
declare(strict_types=1);

/**
 * Phase 6 Stage 2 — load/signature smoke for production SQL repository skeletons.
 * No SQL execution, no DB connection, no Host B.
 */

$root = dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR . 'production'
    . DIRECTORY_SEPARATOR;

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap.php';

require_once $root . 'GatewaySqlConnectionConfig.php';
require_once $root . 'SqlGatewayConnectionFactory.php';

require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'TenantMappingRepositoryInterface.php';
require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'ApiKeyRepositoryInterface.php';
require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'RateLimitPolicyRepositoryInterface.php';
require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'ServicePermissionRepositoryInterface.php';
require_once $root . 'contracts' . DIRECTORY_SEPARATOR . 'AuditLogRepositoryInterface.php';

require_once $root . 'repositories' . DIRECTORY_SEPARATOR . 'SqlTenantMappingRepository.php';
require_once $root . 'repositories' . DIRECTORY_SEPARATOR . 'SqlApiKeyRepository.php';
require_once $root . 'repositories' . DIRECTORY_SEPARATOR . 'SqlRateLimitPolicyRepository.php';
require_once $root . 'repositories' . DIRECTORY_SEPARATOR . 'SqlServicePermissionRepository.php';
require_once $root . 'repositories' . DIRECTORY_SEPARATOR . 'SqlAuditLogRepository.php';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

// Interfaces exist
t(interface_exists(TenantMappingRepositoryInterface::class), 'interface TenantMappingRepositoryInterface');
t(interface_exists(ApiKeyRepositoryInterface::class), 'interface ApiKeyRepositoryInterface');
t(interface_exists(RateLimitPolicyRepositoryInterface::class), 'interface RateLimitPolicyRepositoryInterface');
t(interface_exists(ServicePermissionRepositoryInterface::class), 'interface ServicePermissionRepositoryInterface');
t(interface_exists(AuditLogRepositoryInterface::class), 'interface AuditLogRepositoryInterface');

// Classes load and implement interfaces
$tRepo = new SqlTenantMappingRepository();
t($tRepo instanceof TenantMappingRepositoryInterface, 'SqlTenantMappingRepository implements interface');

$aRepo = new SqlApiKeyRepository();
t($aRepo instanceof ApiKeyRepositoryInterface, 'SqlApiKeyRepository implements interface');

$rRepo = new SqlRateLimitPolicyRepository();
t($rRepo instanceof RateLimitPolicyRepositoryInterface, 'SqlRateLimitPolicyRepository implements interface');

$sRepo = new SqlServicePermissionRepository();
t($sRepo instanceof ServicePermissionRepositoryInterface, 'SqlServicePermissionRepository implements interface');

$auRepo = new SqlAuditLogRepository();
t($auRepo instanceof AuditLogRepositoryInterface, 'SqlAuditLogRepository implements interface');

// Reflection: expected public instance methods
$expect = static function (string $class, string $method, int $paramCount): bool {
    if (!class_exists($class)) {
        return false;
    }
    $rc = new ReflectionClass($class);
    if (!$rc->hasMethod($method)) {
        return false;
    }
    $m = $rc->getMethod($method);
    if (!$m->isPublic() || $m->isStatic()) {
        return false;
    }

    return $m->getNumberOfRequiredParameters() === $paramCount;
};

t($expect(SqlTenantMappingRepository::class, 'findBySno', 1), 'SqlTenantMappingRepository::findBySno signature');
t($expect(SqlApiKeyRepository::class, 'findByIncomingPlainKey', 1), 'SqlApiKeyRepository::findByIncomingPlainKey');
t($expect(SqlApiKeyRepository::class, 'hasPrefixButHashMismatch', 1), 'SqlApiKeyRepository::hasPrefixButHashMismatch');
t($expect(SqlRateLimitPolicyRepository::class, 'resolvePolicy', 3), 'SqlRateLimitPolicyRepository::resolvePolicy');
t($expect(SqlServicePermissionRepository::class, 'listRulesForTenantAndKey', 2), 'SqlServicePermissionRepository::listRulesForTenantAndKey');
t($expect(SqlAuditLogRepository::class, 'append', 1), 'SqlAuditLogRepository::append');

// Config reads without DB
$cfg = GatewaySqlConnectionConfig::fromAppConfig();
t(is_string($cfg->getHost()), 'GatewaySqlConnectionConfig host is string');
t(is_string($cfg->getDatabaseName()), 'GatewaySqlConnectionConfig database name is string');

// Factory refuses PDO
$factory = new SqlGatewayConnectionFactory($cfg);
t($factory->getConfig() === $cfg, 'SqlGatewayConnectionFactory holds config');
$threw = false;
try {
    $factory->createReadOnlyPdo();
} catch (RuntimeException $e) {
    $threw = strpos($e->getMessage(), 'Phase 6 Stage 2') !== false;
}
t($threw, 'SqlGatewayConnectionFactory::createReadOnlyPdo throws (no connection)');

// Repository methods throw (no SQL)
foreach (
    [
        [SqlTenantMappingRepository::class, 'findBySno', ['x']],
        [SqlApiKeyRepository::class, 'findByIncomingPlainKey', ['k']],
        [SqlApiKeyRepository::class, 'hasPrefixButHashMismatch', ['k']],
        [SqlRateLimitPolicyRepository::class, 'resolvePolicy', ['s', 1, 'svc']],
        [SqlServicePermissionRepository::class, 'listRulesForTenantAndKey', ['s', 1]],
    ] as $call
) {
    $cls = $call[0];
    $meth = $call[1];
    $args = $call[2];
    $obj = new $cls();
    $caught = false;
    try {
        $obj->{$meth}(...$args);
    } catch (RuntimeException $e) {
        $caught = strpos($e->getMessage(), 'Phase 6 Stage 2') !== false;
    }
    t($caught, "{$cls}::{$meth} throws skeleton");
}

$caughtA = false;
try {
    $auRepo->append(['trace_id' => 't']);
} catch (RuntimeException $e) {
    $caughtA = strpos($e->getMessage(), 'Phase 6 Stage 2') !== false;
}
t($caughtA, 'SqlAuditLogRepository::append throws skeleton');

if ($failures === 0) {
    echo "OK: Phase 6 Stage 2 SQL repository skeleton tests passed.\n";
    exit(0);
}

echo "DONE with {$failures} failure(s).\n";
exit(1);
