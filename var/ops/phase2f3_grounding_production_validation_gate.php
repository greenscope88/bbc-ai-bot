<?php
declare(strict_types=1);

/**
 * Phase 2-F Step 2-F-3 — Grounding Production Validation gate (read-only evaluator).
 *
 * SSOT: docs/BATS_AI_GROUNDING_LAYER.md Appendix E / Phase 2-F-3.
 *
 * Maps scenario, rollback, acceptance, and regression results to rollout verdict.
 * Does not modify Runtime, Composer, or Pipeline architecture.
 */
final class GroundingProductionValidationGate
{
    public const VERDICT_PASS = 'PASS';
    public const VERDICT_FAIL = 'FAIL';

    public const EXIT_PASS = 0;
    public const EXIT_FAIL = 1;

    public const NOTE_OPS_PENDING = 'ops_pending';

    /**
     * @param array{
     *   scenarios?: list<array{id: string, passed: bool, note?: string}>,
     *   rollback_drills?: list<array{id: string, passed: bool, note?: string}>,
     *   acceptance_checklist?: list<array{id: string, passed: bool, note?: string}>,
     *   regression_runs?: list<array{id: string, passed: bool, note?: string}>
     * } $report
     *
     * @return array{
     *   verdict: string,
     *   technical_verdict: string,
     *   reasons: list<string>,
     *   passed_count: int,
     *   failed_count: int,
     *   ops_pending_count: int,
     *   technical_failed_count: int,
     *   total_count: int
     * }
     */
    public static function evaluate(array $report): array
    {
        $reasons = [];
        $passed = 0;
        $failed = 0;
        $opsPending = 0;

        foreach (['scenarios', 'rollback_drills', 'acceptance_checklist', 'regression_runs'] as $section) {
            $items = $report[$section] ?? [];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = trim((string) ($item['id'] ?? ''));
                $ok = (bool) ($item['passed'] ?? false);
                $note = trim((string) ($item['note'] ?? ''));
                $isOpsPending = ($note === self::NOTE_OPS_PENDING);

                if ($ok) {
                    ++$passed;
                } else {
                    ++$failed;
                    if ($isOpsPending) {
                        ++$opsPending;
                    } elseif ($id !== '') {
                        $reasons[] = $section . ':' . $id;
                    }
                }
            }
        }

        $total = $passed + $failed;
        if ($total === 0) {
            return [
                'verdict' => self::VERDICT_FAIL,
                'technical_verdict' => self::VERDICT_FAIL,
                'reasons' => ['empty_validation_report'],
                'passed_count' => 0,
                'failed_count' => 0,
                'ops_pending_count' => 0,
                'technical_failed_count' => 0,
                'total_count' => 0,
            ];
        }

        $technicalFailed = $failed - $opsPending;
        $technicalVerdict = $technicalFailed === 0 ? self::VERDICT_PASS : self::VERDICT_FAIL;
        $verdict = $failed === 0 ? self::VERDICT_PASS : self::VERDICT_FAIL;

        return [
            'verdict' => $verdict,
            'technical_verdict' => $technicalVerdict,
            'reasons' => array_values(array_unique($reasons)),
            'passed_count' => $passed,
            'failed_count' => $failed,
            'ops_pending_count' => $opsPending,
            'technical_failed_count' => $technicalFailed,
            'total_count' => $total,
        ];
    }

    public static function exitCodeFor(string $verdict): int
    {
        return $verdict === self::VERDICT_PASS ? self::EXIT_PASS : self::EXIT_FAIL;
    }

    /**
     * Exit code for technical validation (ops_pending items do not fail).
     *
     * @param array{technical_verdict?: string} $gateResult
     */
    public static function technicalExitCodeFor(array $gateResult): int
    {
        $verdict = trim((string) ($gateResult['technical_verdict'] ?? ''));

        return self::exitCodeFor($verdict);
    }

    /**
     * @param list<array{id: string, passed: bool, note?: string}> $items
     */
    public static function allPassed(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item) || !((bool) ($item['passed'] ?? false))) {
                return false;
            }
        }

        return $items !== [];
    }

    /**
     * @param list<array{id: string, passed: bool, note?: string}> $items
     */
    public static function allTechnicalPassed(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                return false;
            }
            if ((bool) ($item['passed'] ?? false)) {
                continue;
            }
            $note = trim((string) ($item['note'] ?? ''));
            if ($note === self::NOTE_OPS_PENDING) {
                continue;
            }

            return false;
        }

        return $items !== [];
    }
}