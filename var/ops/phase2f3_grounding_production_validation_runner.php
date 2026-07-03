<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-3 — Grounding Production Validation ops runner.
 *
 * Runs grounding regression suite, composer pilot, saas router pilot, and the
 * Phase 2-F-3 production validation test; aggregates results via gate evaluator.
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md Appendix E.
 */

$root = dirname(__DIR__, 2);
$phpBin = getenv('PHP_BIN') !== false && trim((string) getenv('PHP_BIN')) !== ''
    ? trim((string) getenv('PHP_BIN'))
    : 'C:\\Web\\xampp\\php\\php.exe';

require_once $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ops'
    . DIRECTORY_SEPARATOR . 'phase2f3_grounding_production_validation_gate.php';

/** @var list<array{id: string, passed: bool, note?: string}> */
$regressionRuns = [];

/** @var list<array{id: string, passed: bool, note?: string}> */
$acceptanceChecklist = [];

/** @var array<string, mixed>|null */
$productionValidation = null;

/**
 * @return array{passed: bool, exit_code: int, output: string}
 */
function phase2f3_run_test(string $phpBin, string $scriptPath): array
{
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
    $outputLines = [];
    $exitCode = 0;
    exec($cmd, $outputLines, $exitCode);

    return [
        'passed' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => implode("\n", $outputLines),
    ];
}

/**
 * @param list<array{id: string, passed: bool, note?: string}> $bucket
 */
function phase2f3_record(array &$bucket, string $id, bool $passed, string $note = ''): void
{
    $entry = ['id' => $id, 'passed' => $passed];
    if ($note !== '') {
        $entry['note'] = $note;
    }
    $bucket[] = $entry;
}

$groundingTests = glob($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'grounding'
    . DIRECTORY_SEPARATOR . 'test_*.php') ?: [];
sort($groundingTests);

$groundingFailures = [];
foreach ($groundingTests as $testScript) {
    $basename = basename($testScript);
    $result = phase2f3_run_test($phpBin, $testScript);
    phase2f3_record(
        $regressionRuns,
        'grounding:' . $basename,
        $result['passed'],
        $result['passed'] ? '' : 'exit ' . $result['exit_code']
    );
    if (!$result['passed']) {
        $groundingFailures[] = $basename;
    }
}

phase2f3_record(
    $acceptanceChecklist,
    'A1',
    $groundingFailures === [],
    $groundingFailures === [] ? 'tests/grounding/test_*.php all PASS' : 'failed: ' . implode(', ', $groundingFailures)
);

$composerPilot = phase2f3_run_test(
    $phpBin,
    $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_composer_pilot_validation.php'
);
phase2f3_record(
    $regressionRuns,
    'composer_pilot',
    $composerPilot['passed'],
    $composerPilot['passed'] ? '' : 'exit ' . $composerPilot['exit_code']
);
phase2f3_record(
    $acceptanceChecklist,
    'A2',
    $composerPilot['passed'],
    $composerPilot['passed'] ? 'test_composer_pilot_validation.php PASS' : 'composer pilot failed'
);

$saasRouterPilot = phase2f3_run_test(
    $phpBin,
    $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'product_sources'
        . DIRECTORY_SEPARATOR . 'test_saas_router_phase9c1_pilot.php'
);
phase2f3_record(
    $regressionRuns,
    'saas_router_pilot',
    $saasRouterPilot['passed'],
    $saasRouterPilot['passed'] ? '' : 'exit ' . $saasRouterPilot['exit_code']
);
phase2f3_record(
    $acceptanceChecklist,
    'A3',
    $saasRouterPilot['passed'],
    $saasRouterPilot['passed'] ? 'test_saas_router_phase9c1_pilot.php PASS' : 'saas router pilot failed'
);

$productionTestPath = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'grounding'
    . DIRECTORY_SEPARATOR . 'test_grounding_production_validation.php';
$productionResult = phase2f3_run_test($phpBin, $productionTestPath);
phase2f3_record(
    $regressionRuns,
    'grounding_production_validation',
    $productionResult['passed'],
    $productionResult['passed'] ? '' : 'exit ' . $productionResult['exit_code']
);

if ($productionResult['passed'] && trim($productionResult['output']) !== '') {
    $decoded = json_decode($productionResult['output'], true);
    if (is_array($decoded)) {
        $productionValidation = $decoded;
        if (isset($decoded['acceptance_checklist']) && is_array($decoded['acceptance_checklist'])) {
            foreach ($decoded['acceptance_checklist'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '' || in_array($id, ['A1', 'A2', 'A3'], true)) {
                    continue;
                }
                phase2f3_record(
                    $acceptanceChecklist,
                    $id,
                    (bool) ($item['passed'] ?? false),
                    trim((string) ($item['note'] ?? ''))
                );
            }
        }
    }
}

$gate = GroundingProductionValidationGate::evaluate([
    'scenarios' => is_array($productionValidation['scenarios'] ?? null) ? $productionValidation['scenarios'] : [],
    'rollback_drills' => is_array($productionValidation['rollback_drills'] ?? null)
        ? $productionValidation['rollback_drills']
        : [],
    'acceptance_checklist' => $acceptanceChecklist,
    'regression_runs' => $regressionRuns,
]);

$summary = [
    'phase' => '2-F-3',
    'runner' => 'phase2f3_grounding_production_validation_runner.php',
    'php_bin' => $phpBin,
    'regression_runs' => $regressionRuns,
    'acceptance_checklist' => $acceptanceChecklist,
    'production_validation' => $productionValidation,
    'gate' => $gate,
];

$json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "FAIL: json_encode error\n");
    exit(GroundingProductionValidationGate::EXIT_FAIL);
}

fwrite(STDOUT, $json . "\n");

if ($gate['technical_verdict'] !== GroundingProductionValidationGate::VERDICT_PASS) {
    fwrite(STDERR, sprintf(
        "FAILED: technical_verdict=%s; reasons=%s\n",
        $gate['technical_verdict'],
        implode(',', $gate['reasons'])
    ));
    exit(GroundingProductionValidationGate::EXIT_FAIL);
}

exit(GroundingProductionValidationGate::technicalExitCodeFor($gate));