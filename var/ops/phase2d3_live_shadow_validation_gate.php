<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'phase2d3_intent_parity_report.php';

/**
 * Phase 2-D Step 2-D-3-2B - Live Shadow Validation Gate (read-only validation tool).
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md;
 *       Phase 2-D-3 Runtime Integration Review (Shadow Strategy / Sign-off Criteria);
 *       Phase 2-D-3-2 Pilot Parity Validation.
 *
 * Role: READ-ONLY Validation Gate. Reuses IntentParityReport to aggregate the live
 * `intent_understanding_shadow_probe` records from saas_router.log, then evaluates the
 * Phase 2-D-3-2 Sign-off Criteria and emits a single gate verdict (PASS / FAIL /
 * NO_DATA). This is the last validation gate before Phase 2-D-3-3 (Authoritative Switch).
 *
 * Strict boundary:
 *   - Read-only: only reads logs (via IntentParityReport); never writes files, never
 *     touches any Runtime / Reply Flow / SSOT.
 *   - never-throw: defensive throughout; missing/garbage input yields NO_DATA, not an error.
 *   - Data protection: inherits IntentParityReport (message_hash only; no raw messages).
 *
 * Sign-off Criteria (all must hold over a non-empty sample):
 *   - Intent Parity        = 100%
 *   - Routing Parity       = 100% (over non-human denominator; N/A when 0 non-human)
 *   - Clarification Parity = 100%
 *   - Mismatch Count       = 0   (=> Allowed Divergence Only: Ambiguous -> clarification)
 *
 * CLI usage:
 *   php var/ops/phase2d3_live_shadow_validation_gate.php [logPath]
 *   exit code: 0 = PASS, 1 = FAIL, 2 = NO_DATA
 */
final class LiveShadowValidationGate
{
    public const VERDICT_PASS = 'PASS';
    public const VERDICT_FAIL = 'FAIL';
    public const VERDICT_NO_DATA = 'NO_DATA';

    public const EXIT_PASS = 0;
    public const EXIT_FAIL = 1;
    public const EXIT_NO_DATA = 2;

    /**
     * Evaluate a parity summary against the sign-off criteria (never-throw).
     *
     * @param array<string, mixed> $summary  output of IntentParityReport::summarize / fromFile
     * @return array{verdict: string, reasons: list<string>, total: int}
     */
    public static function evaluate(array $summary): array
    {
        $total = (int) ($summary['total'] ?? 0);
        if ($total <= 0) {
            return ['verdict' => self::VERDICT_NO_DATA, 'reasons' => ['no_shadow_samples'], 'total' => 0];
        }

        $reasons = [];

        $intent = $summary['intent_parity_pct'] ?? null;
        if ($intent === null || (float) $intent !== 100.0) {
            $reasons[] = 'intent_parity_below_100';
        }

        // Routing parity is over the non-human denominator; null means no non-human
        // samples yet (nothing to fail on), so it is not a blocker by itself.
        $routing = $summary['routing_parity_pct'] ?? null;
        if ($routing !== null && (float) $routing !== 100.0) {
            $reasons[] = 'routing_parity_below_100';
        }

        $clar = $summary['clarification_parity_pct'] ?? null;
        if ($clar === null || (float) $clar !== 100.0) {
            $reasons[] = 'clarification_parity_below_100';
        }

        if ((int) ($summary['mismatch_count'] ?? 0) !== 0) {
            $reasons[] = 'mismatches_present';
        }

        return [
            'verdict' => $reasons === [] ? self::VERDICT_PASS : self::VERDICT_FAIL,
            'reasons' => $reasons,
            'total' => $total,
        ];
    }

    /**
     * Read the live log, summarize, and evaluate the gate (never-throw).
     *
     * @return array{verdict: string, reasons: list<string>, total: int, summary: array<string, mixed>}
     */
    public static function fromFile(string $path): array
    {
        $summary = IntentParityReport::fromFile($path);
        $gate = self::evaluate($summary);
        $gate['summary'] = $summary;

        return $gate;
    }

    public static function exitCodeFor(string $verdict): int
    {
        switch ($verdict) {
            case self::VERDICT_PASS:
                return self::EXIT_PASS;
            case self::VERDICT_FAIL:
                return self::EXIT_FAIL;
            default:
                return self::EXIT_NO_DATA;
        }
    }

    /**
     * Render the gate verdict as plain text (report body + gate footer).
     *
     * @param array{verdict: string, reasons: list<string>, total: int, summary: array<string, mixed>} $gate
     */
    public static function format(array $gate): string
    {
        $summary = is_array($gate['summary'] ?? null) ? $gate['summary'] : [];
        $out = IntentParityReport::format($summary);
        $out .= "\n";
        $out .= '=== Live Shadow Validation Gate (Phase 2-D-3-2B) ===' . "\n";
        $out .= 'Verdict             : ' . (string) ($gate['verdict'] ?? '') . "\n";
        $reasons = is_array($gate['reasons'] ?? null) ? $gate['reasons'] : [];
        $out .= 'Reasons             : ' . ($reasons === [] ? '(none)' : implode(', ', $reasons)) . "\n";

        return $out;
    }
}

// --- CLI entry (read-only) -------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $path = isset($argv[1]) && trim((string) $argv[1]) !== ''
        ? (string) $argv[1]
        : IntentParityReport::defaultLogPath();

    fwrite(STDOUT, 'Log source: ' . $path . "\n");
    $gate = LiveShadowValidationGate::fromFile($path);
    fwrite(STDOUT, LiveShadowValidationGate::format($gate));
    exit(LiveShadowValidationGate::exitCodeFor((string) $gate['verdict']));
}