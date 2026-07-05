<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AsscValidationComparator.php';

/**
 * Aggregates ASSC semantic validation run results.
 */
final class AsscValidationReport
{
    /**
     * @param array<string, mixed> $runResult
     * @return array<string, mixed>
     */
    public static function build(array $runResult, AsscValidationComparator $comparator): array
    {
        $cases = [];
        $counts = [
            AsscValidationComparator::STATUS_PASS => 0,
            AsscValidationComparator::STATUS_FAIL => 0,
            AsscValidationComparator::STATUS_SKIP => 0,
            AsscValidationComparator::STATUS_ERROR => 0,
        ];

        foreach ($runResult['cases'] ?? [] as $runCase) {
            if (!is_array($runCase)) {
                continue;
            }
            $comparison = $comparator->compare($runCase);
            $status = (string) ($comparison['status'] ?? AsscValidationComparator::STATUS_ERROR);
            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            ++$counts[$status];

            $cases[] = [
                'assc_id' => (string) ($runCase['assc_id'] ?? ''),
                'primary_scenario' => (string) ($runCase['primary_scenario'] ?? ''),
                'utterance_hash' => (string) ($runCase['customer_utterance_hash'] ?? ''),
                'runner_status' => (string) ($runCase['status'] ?? ''),
                'validation_status' => $status,
                'validation_reason' => (string) ($comparison['reason'] ?? ''),
                'checks' => $comparison['checks'] ?? [],
                'actual_intent' => is_array($runCase['actual'] ?? null)
                    ? (string) ($runCase['actual']['intent'] ?? '')
                    : '',
                'actual_dispatch_plan' => is_array($runCase['actual'] ?? null)
                    ? (string) ($runCase['actual']['dispatch_plan'] ?? '')
                    : '',
                'expected_intent' => is_array($runCase['expected'] ?? null)
                    ? (string) ($runCase['expected']['expected_intent'] ?? '')
                    : '',
                'expected_dispatch_plan' => is_array($runCase['expected'] ?? null)
                    ? (string) ($runCase['expected']['expected_dispatch_plan'] ?? '')
                    : '',
            ];
        }

        $total = count($cases);
        $evaluated = $counts[AsscValidationComparator::STATUS_PASS]
            + $counts[AsscValidationComparator::STATUS_FAIL]
            + $counts[AsscValidationComparator::STATUS_ERROR];

        return [
            'mode' => (string) ($runResult['mode'] ?? ''),
            'subset' => (string) ($runResult['subset'] ?? ''),
            'corpus_version' => (string) ($runResult['corpus_version'] ?? ''),
            'expected_version' => (string) ($runResult['expected_version'] ?? ''),
            'executed_at' => (string) ($runResult['executed_at'] ?? ''),
            'total' => $total,
            'evaluated' => $evaluated,
            'counts' => $counts,
            'pass_rate_pct' => $evaluated > 0
                ? round(100.0 * $counts[AsscValidationComparator::STATUS_PASS] / $evaluated, 2)
                : null,
            'contract_pass_count' => self::countContractPass($cases),
            'cases' => $cases,
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function formatCliSummary(array $report): string
    {
        $counts = $report['counts'] ?? [];
        $lines = [
            'ASSC Gold Semantic Validation Report',
            '=====================================',
            'Mode: ' . ($report['mode'] ?? ''),
            'Subset: ' . ($report['subset'] ?? ''),
            'Corpus: ' . ($report['corpus_version'] ?? ''),
            'Expected: ' . ($report['expected_version'] ?? ''),
            'Executed: ' . ($report['executed_at'] ?? ''),
            '',
            'Total: ' . ($report['total'] ?? 0),
            'PASS: ' . ($counts['PASS'] ?? 0),
            'FAIL: ' . ($counts['FAIL'] ?? 0),
            'SKIP: ' . ($counts['SKIP'] ?? 0),
            'ERROR: ' . ($counts['ERROR'] ?? 0),
            'Pass Rate (evaluated): ' . ($report['pass_rate_pct'] !== null ? $report['pass_rate_pct'] . '%' : 'n/a'),
            'Contract Pass: ' . ($report['contract_pass_count'] ?? 0) . '/' . ($report['total'] ?? 0),
        ];

        $failures = [];
        foreach ($report['cases'] ?? [] as $case) {
            if (!is_array($case)) {
                continue;
            }
            if (($case['validation_status'] ?? '') === AsscValidationComparator::STATUS_FAIL) {
                $failures[] = sprintf(
                    '  %s [%s] expected %s/%s actual %s/%s — %s',
                    $case['assc_id'] ?? '',
                    $case['primary_scenario'] ?? '',
                    $case['expected_intent'] ?? '',
                    $case['expected_dispatch_plan'] ?? '',
                    $case['actual_intent'] ?? '',
                    $case['actual_dispatch_plan'] ?? '',
                    $case['validation_reason'] ?? ''
                );
            }
        }

        if ($failures !== []) {
            $lines[] = '';
            $lines[] = 'Failures:';
            $lines = array_merge($lines, $failures);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function writeJson(array $report, string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('failed to encode ASSC validation report JSON');
        }

        if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
            throw new \RuntimeException('failed to write ASSC validation report: ' . $outputPath);
        }
    }

    /**
     * @param list<array<string, mixed>> $cases
     */
    private static function countContractPass(array $cases): int
    {
        $n = 0;
        foreach ($cases as $case) {
            foreach ($case['checks'] ?? [] as $check) {
                if (!is_array($check)) {
                    continue;
                }
                if (($check['layer'] ?? '') === 'contract' && ($check['pass'] ?? false) === true) {
                    ++$n;
                    break;
                }
            }
        }

        return $n;
    }
}
