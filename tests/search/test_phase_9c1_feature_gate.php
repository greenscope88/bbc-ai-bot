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



// Case A: BATS-enabled tenant + legacy prefix → true

$caseA = Phase9C1FeatureGate::evaluate([

    'sno' => $travelBSno,

    'userMessage' => 'BATS測試北海道7月',

]);

test_assert(($caseA['enabled'] ?? false) === true, 'caseA: enabled tenant + legacy prefix');

test_assert(($caseA['message_prefix_present'] ?? false) === true, 'caseA: prefix present logged');

test_assert(Phase9C1FeatureGate::isEnabled([

    'sno' => $travelBSno,

    'userMessage' => 'BATS測試 大阪',

]) === true, 'caseA: isEnabled with prefix');



// Case B: BATS-enabled tenant + no prefix → true

$caseB = Phase9C1FeatureGate::evaluate([

    'sno' => $travelBSno,

    'userMessage' => '北海道7月',

]);

test_assert(($caseB['enabled'] ?? false) === true, 'caseB: enabled tenant without prefix');

test_assert(($caseB['reason'] ?? '') === 'all_conditions_met', 'caseB: tenant gate reason');

test_assert(($caseB['message_prefix_present'] ?? true) === false, 'caseB: prefix not present');



// Case C: non-enabled tenant → false

$caseC = Phase9C1FeatureGate::evaluate([

    'sno' => $otherSno,

    'userMessage' => 'BATS測試北海道',

]);

test_assert(($caseC['enabled'] ?? false) === false, 'caseC: non-enabled tenant disabled');

test_assert(($caseC['reason'] ?? '') === 'tenant_sno_not_allowlisted', 'caseC: sno reason');



// Case D: stripPilotQueryPrefix backward compatibility

test_assert(

    Phase9C1FeatureGate::stripPilotQueryPrefix('BATS測試北海道7月') === '北海道7月',

    'caseD: strip prefix'

);

test_assert(

    Phase9C1FeatureGate::stripPilotQueryPrefix('東京') === '東京',

    'caseD: no strip when missing prefix'

);



// Case E: isBatsEnabledTenant helper

test_assert(Phase9C1FeatureGate::isBatsEnabledTenant($travelBSno) === true, 'caseE: travel_b enabled');

test_assert(Phase9C1FeatureGate::isBatsEnabledTenant($otherSno) === false, 'caseE: other tenant disabled');



// Case F: travel_d third tenant allowlisted (product path gate)

$travelDSno = '5fecdf66e9224bee';

$caseF = Phase9C1FeatureGate::evaluate([

    'sno' => $travelDSno,

    'userMessage' => '北海道7月',

]);

test_assert(($caseF['enabled'] ?? false) === true, 'caseF: travel_d enabled');

test_assert(($caseF['reason'] ?? '') === 'all_conditions_met', 'caseF: travel_d reason');

test_assert(Phase9C1FeatureGate::isBatsEnabledTenant($travelDSno) === true, 'caseF: isBatsEnabledTenant travel_d');

test_assert(Phase9C1FeatureGate::isBatsEnabledTenant($travelBSno) === true, 'caseF: travel_b still enabled');



if ($failures > 0) {

    fwrite(STDERR, "\n{$failures} test failure(s)\n");

    exit(1);

}



fwrite(STDOUT, "OK: test_phase_9c1_feature_gate (all passed)\n");

exit(0);

