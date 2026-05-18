<?php
declare(strict_types=1);

/**
 * Phase 6 Stage 7 — Validate rollout preparation: no live modes, Host B HTTP off.
 */

$root = dirname(__DIR__, 3);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $root
    . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'api_gateway'
    . DIRECTORY_SEPARATOR . 'production'
    . DIRECTORY_SEPARATOR . 'rollout'
    . DIRECTORY_SEPARATOR . 'RolloutActivationGuard.php';

$failures = 0;

function t(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

RolloutActivationGuard::assertControlledPreparationPhase();

$audit = strtolower(trim((string) app_config_get('gateway.audit_log.persistence_mode', 'disabled')));
$rate = strtolower(trim((string) app_config_get('gateway.rate_limit.persistence_mode', 'disabled')));
$hostb = (bool) app_config_get('gateway.host_b.http_enabled', false);

$auditNorm = $audit === 'dry-run' ? 'dry_run' : $audit;
$rateNorm = $rate === 'dry-run' ? 'dry_run' : $rate;

t($audit !== 'live', 'audit persistence_mode must not be live (got: ' . $audit . ')');
t($rate !== 'live', 'rate_limit persistence_mode must not be live (got: ' . $rate . ')');
t($hostb === false, 'Host B http_enabled must be false');

t(
    in_array($auditNorm, ['disabled', 'dry_run'], true),
    'audit mode must be exactly disabled or dry_run (got: ' . $audit . ')'
);
t(
    in_array($rateNorm, ['disabled', 'dry_run'], true),
    'rate_limit mode must be exactly disabled or dry_run (got: ' . $rate . ')'
);

if ($failures > 0) {
    fwrite(STDERR, "Phase 6 Stage 7 rollout readiness validation failed ({$failures}).\n");
    exit(1);
}

fwrite(STDOUT, "Phase 6 Stage 7 rollout readiness validation passed.\n");
fwrite(STDOUT, '  gateway.audit_log.persistence_mode=' . $audit . "\n");
fwrite(STDOUT, '  gateway.rate_limit.persistence_mode=' . $rate . "\n");
fwrite(STDOUT, '  gateway.host_b.http_enabled=' . ($hostb ? 'true' : 'false') . "\n");
exit(0);
