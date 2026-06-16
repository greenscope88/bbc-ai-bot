<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'Phase9C1FeatureGate.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$travelBSno = Phase9C1FeatureGate::TRAVEL_B_SNO;
$otherSno = 'e1fd133c7e8e45a1';

// Case A: travel_b + BATS測試 → true
$caseA = Phase9C1FeatureGate::evaluate([
    'sno' => $travelBSno,
    'userMessage' => 'BATS測試北海道7月',
]);
test_assert(($caseA['enabled'] ?? false) === true, 'caseA: travel_b + BATS測試 enabled');
test_assert(Phase9C1FeatureGate::isEnabled([
    'sno' => $travelBSno,
    'userMessage' => 'BATS測試 大阪',
]) === true, 'caseA: isEnabled true');

// Case B: travel_b + 無前綴 → false
$caseB = Phase9C1FeatureGate::evaluate([
    'sno' => $travelBSno,
    'userMessage' => '北海道7月',
]);
test_assert(($caseB['enabled'] ?? false) === false, 'caseB: no prefix disabled');
test_assert(($caseB['reason'] ?? '') === 'message_prefix_mismatch', 'caseB: prefix reason');

// Case C: non-travel_b + BATS測試 → false
$caseC = Phase9C1FeatureGate::evaluate([
    'sno' => $otherSno,
    'userMessage' => 'BATS測試北海道',
]);
test_assert(($caseC['enabled'] ?? false) === false, 'caseC: non-travel_b disabled');
test_assert(($caseC['reason'] ?? '') === 'tenant_sno_not_allowlisted', 'caseC: sno reason');

// Case D: stripPilotQueryPrefix
test_assert(
    Phase9C1FeatureGate::stripPilotQueryPrefix('BATS測試北海道7月') === '北海道7月',
    'caseD: strip prefix'
);
test_assert(
    Phase9C1FeatureGate::stripPilotQueryPrefix('東京') === '東京',
    'caseD: no strip when missing prefix'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_phase_9c1_feature_gate (all passed)\n");
exit(0);
