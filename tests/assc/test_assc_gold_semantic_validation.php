<?php

/**
 * ASSC Gold Semantic Validation — harness regression test.
 *
 * Offline only. Does not modify production runtime or ASSC corpus.
 * Legacy semantic pass rate is NOT a gate; harness integrity is.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/tests/assc/AsscGoldCorpusLoader.php';
require_once $root . '/tests/assc/AsscGoldExpectedRepository.php';
require_once $root . '/tests/assc/AsscSemanticValidationRunner.php';
require_once $root . '/tests/assc/AsscValidationComparator.php';
require_once $root . '/tests/assc/AsscValidationReport.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// ============================================================================
// 1. Corpus Loader — ASSC-001~100
// ============================================================================
$loader = new AsscGoldCorpusLoader();
$corpus = $loader->load();
test_assert(count($corpus) === 100, 'loader: 100 cases');
test_assert($corpus[0]['assc_id'] === 'ASSC-001', 'loader: first ASSC-001');
test_assert($corpus[99]['assc_id'] === 'ASSC-100', 'loader: last ASSC-100');
test_assert($corpus[0]['customer_utterance'] !== '', 'loader: utterance preserved');
test_assert($corpus[6]['assc_id'] === 'ASSC-007', 'loader: ASSC-007 present');
test_assert(
    strpos($corpus[6]['customer_utterance'], 'BATS測試') !== false,
    'loader: ASSC-007 prefix preserved verbatim'
);

// ============================================================================
// 2. Expected Repository — Smoke 20
// ============================================================================
$expectedRepo = new AsscGoldExpectedRepository();
$smokeIds = $expectedRepo->getSmokeAsscIds();
test_assert(count($smokeIds) === 20, 'expected: smoke 20 ids');
foreach ($smokeIds as $id) {
    $row = $expectedRepo->getByAsscId($id);
    test_assert($row !== null, 'expected: annotation for ' . $id);
    test_assert(isset($row['expected_intent']), 'expected: intent field ' . $id);
    test_assert(isset($row['expected_dispatch_plan']), 'expected: dispatch field ' . $id);
}

// ============================================================================
// 3. Runner — legacy smoke, never throw
// ============================================================================
$runner = new AsscSemanticValidationRunner();
$runResult = $runner->run(AsscSemanticValidationRunner::MODE_LEGACY, AsscSemanticValidationRunner::SUBSET_SMOKE);
test_assert(count($runResult['cases']) === 20, 'runner: smoke 20 executed slots');
$errorCount = 0;
$ranCount = 0;
foreach ($runResult['cases'] as $case) {
    if (($case['status'] ?? '') === 'ERROR') {
        ++$errorCount;
    }
    if (($case['status'] ?? '') === 'RAN') {
        ++$ranCount;
    }
}
test_assert($errorCount === 0, 'runner: zero ERROR on smoke legacy');
test_assert($ranCount === 20, 'runner: all 20 RAN');

// ============================================================================
// 4. Comparator + Report
// ============================================================================
$comparator = new AsscValidationComparator();
$report = AsscValidationReport::build($runResult, $comparator);
test_assert(($report['total'] ?? 0) === 20, 'report: total 20');
test_assert(isset($report['counts']['PASS']), 'report: PASS count');
test_assert(isset($report['counts']['FAIL']), 'report: FAIL count');
test_assert(($report['contract_pass_count'] ?? 0) === 20, 'report: contract 20/20');
test_assert(is_string(AsscValidationReport::formatCliSummary($report)), 'report: CLI summary');

$outputDir = $root . '/tests/assc/output';
$jsonPath = $outputDir . '/assc_smoke_report_test.json';
AsscValidationReport::writeJson($report, $jsonPath);
test_assert(is_file($jsonPath), 'report: JSON written');

$summary = AsscValidationReport::formatCliSummary($report);
test_assert(strpos($summary, 'ASSC Gold Semantic Validation Report') !== false, 'report: summary header');

// Harness integrity: semantic FAIL allowed; ERROR/SKIP on smoke must be zero
test_assert(($report['counts']['ERROR'] ?? -1) === 0, 'report: zero validation ERROR');
test_assert(($report['counts']['SKIP'] ?? -1) === 0, 'report: zero SKIP on smoke');

fwrite(STDOUT, AsscValidationReport::formatCliSummary($report));

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_assc_gold_semantic_validation (harness passed)\n");
exit(0);
