<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-3 — Composer Pilot Validation gate (read-only evaluator).
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §7 / Phase 2-E-3 Pilot Validation.
 *
 * Maps validation case results to a single rollout verdict. Does not modify Runtime
 * or Composer architecture.
 */
final class ComposerPilotValidationGate
{
    public const VERDICT_PASS = 'PASS';
    public const VERDICT_FAIL = 'FAIL';

    public const EXIT_PASS = 0;
    public const EXIT_FAIL = 1;

    /**
     * @param array{
     *   cases?: list<array{id: string, passed: bool, note?: string}>,
     *   definition_of_done?: list<array{id: string, passed: bool, note?: string}>,
     *   regression?: list<array{id: string, passed: bool, note?: string}>
     * } $report
     *
     * @return array{
     *   verdict: string,
     *   reasons: list<string>,
     *   passed_count: int,
     *   failed_count: int,
     *   total_count: int
     * }
     */
    public static function evaluate(array $report): array
    {
        $reasons = [];
        $passed = 0;
        $failed = 0;

        foreach (['cases', 'definition_of_done', 'regression'] as $section) {
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
                if ($ok) {
                    ++$passed;
                } else {
                    ++$failed;
                    if ($id !== '') {
                        $reasons[] = $section . ':' . $id;
                    }
                }
            }
        }

        $total = $passed + $failed;
        if ($total === 0) {
            return [
                'verdict' => self::VERDICT_FAIL,
                'reasons' => ['empty_validation_report'],
                'passed_count' => 0,
                'failed_count' => 0,
                'total_count' => 0,
            ];
        }

        return [
            'verdict' => $failed === 0 ? self::VERDICT_PASS : self::VERDICT_FAIL,
            'reasons' => array_values(array_unique($reasons)),
            'passed_count' => $passed,
            'failed_count' => $failed,
            'total_count' => $total,
        ];
    }

    public static function exitCodeFor(string $verdict): int
    {
        return $verdict === self::VERDICT_PASS ? self::EXIT_PASS : self::EXIT_FAIL;
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
}
