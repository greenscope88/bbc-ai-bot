<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-3 — Composer Pilot Validation runner (ops / CI gate).
 */

$root = dirname(__DIR__, 2);
$phpBin = getenv('PHP_BIN') !== false && getenv('PHP_BIN') !== '' ? (string) getenv('PHP_BIN') : 'php';

require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'ComposerPilotValidationGate.php';

$regression = [];

function run_pilot_test(string $phpBin, string $path): array
{
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $exit = 1;
    exec($cmd, $output, $exit);
    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

$tests = [
    'pilot_validation' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_composer_pilot_validation.php',
    'persona_adapter' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_persona_adapter.php',
    'continuity_presenter' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_conversation_continuity_presenter.php',
    'nba_presenter' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_next_best_action_presenter.php',
    'experience_layer' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_conversation_experience_layer.php',
    'composer_pipeline' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_composer_runtime_pipeline.php',
    'knowledge_layout' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_knowledge_layout_strategy.php',
    'product_layout' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_product_layout_strategy.php',
    'output_validator' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_grounded_output_validator.php',
    'contract_foundation' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_grounded_contract_foundation.php',
    'grounded_composer' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'response'
        . DIRECTORY_SEPARATOR . 'test_grounded_response_composer.php',
    'saas_router_pilot' => $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'product_sources'
        . DIRECTORY_SEPARATOR . 'test_saas_router_phase9c1_pilot.php',
];

foreach ($tests as $id => $path) {
    if (!is_readable($path)) {
        $regression[] = ['id' => $id, 'passed' => false, 'note' => 'missing'];
        continue;
    }
    $result = run_pilot_test($phpBin, $path);
    $passed = $result['exit'] === 0;
    $regression[] = ['id' => $id, 'passed' => $passed, 'note' => $passed ? 'ok' : substr($result['output'], 0, 200)];
    if (!$passed) {
        fwrite(STDERR, "REGRESSION FAIL: {$id}\n{$result['output']}\n");
    }
}

$gate = ComposerPilotValidationGate::evaluate(['regression' => $regression]);
fwrite(STDOUT, json_encode([
    'phase' => '2-E-3',
    'verdict' => $gate['verdict'],
    'passed_count' => $gate['passed_count'],
    'failed_count' => $gate['failed_count'],
    'reasons' => $gate['reasons'],
    'regression' => $regression,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
exit(ComposerPilotValidationGate::exitCodeFor($gate['verdict']));