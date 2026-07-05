<?php
declare(strict_types=1);

/**
 * ASSC Gold Regression CLI — offline semantic validation.
 *
 * Usage:
 *   php var/ops/assc_gold_regression.php --mode=legacy --subset=smoke
 */

$root = dirname(__DIR__, 2);
require_once $root . '/tests/assc/AsscSemanticValidationRunner.php';
require_once $root . '/tests/assc/AsscValidationComparator.php';
require_once $root . '/tests/assc/AsscValidationReport.php';

$options = getopt('', ['mode:', 'subset:', 'output:']);
$mode = isset($options['mode']) ? (string) $options['mode'] : AsscSemanticValidationRunner::MODE_LEGACY;
$subset = isset($options['subset']) ? (string) $options['subset'] : AsscSemanticValidationRunner::SUBSET_SMOKE;
$output = isset($options['output']) ? (string) $options['output'] : $root . '/tests/assc/output/assc_regression_report.json';

try {
    $runner = new AsscSemanticValidationRunner();
    $runResult = $runner->run($mode, $subset);
    $report = AsscValidationReport::build($runResult, new AsscValidationComparator());
    AsscValidationReport::writeJson($report, $output);

    fwrite(STDOUT, AsscValidationReport::formatCliSummary($report));
    fwrite(STDOUT, 'JSON: ' . $output . PHP_EOL);

    $errors = (int) ($report['counts']['ERROR'] ?? 0);
    exit($errors > 0 ? 2 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ASSC regression failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}