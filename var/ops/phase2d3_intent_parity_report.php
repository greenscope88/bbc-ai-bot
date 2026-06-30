<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-3-2A - AIU Shadow Probe Parity Report (read-only validation tool).
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md (AIU Runtime / AiIntentUnderstandingResult);
 *       Phase 2-D-3 Runtime Integration Review (Shadow Strategy);
 *       Phase 2-D-3-2 Pilot Parity Validation (Sign-off Criteria).
 *
 * Role: READ-ONLY Validation Tool. Reads the AIU Shadow Probe
 * `intent_understanding_shadow_probe` records from saas_router.log and quantifies
 * Intent / Routing / Clarification Parity for Pilot Validation.
 *
 * Strict boundary:
 *   - Read-only: only reads logs; never writes files, never touches any Runtime /
 *     Reply Flow / SSOT.
 *   - never-throw: all parsing is defensive; bad lines / missing files yield empty result.
 *   - Data protection: never outputs raw customer messages; uses message_hash only.
 *
 * Parity definitions (explicit, for Sign-off reference):
 *   - Intent Parity:        mapped(legacy_intent_type) === aiu_intent.
 *   - Routing Parity:       dispatch_plan within the legal route set for legacy_intent_type;
 *                           owner=HUMAN (dispatch=human) is an observational bucket,
 *                           excluded from the routing denominator.
 *   - Clarification Parity: clarification_required <=> (dispatch_plan === clarification)
 *                           (validates AIU clarification routing is self-consistent).
 *
 * CLI usage:
 *   php var/ops/phase2d3_intent_parity_report.php [logPath]
 *   (logPath defaults to <root>/logs/saas_router.log)
 */
final class IntentParityReport
{
    public const SHADOW_STEP = 'intent_understanding_shadow_probe';

    /** legacy intent_type => expected AIU intent category. */
    private const LEGACY_TO_AIU = [
        'product_search' => 'product_search',
        'knowledge_query' => 'knowledge',
        'ambiguous' => 'ambiguous',
    ];

    /** legacy intent_type => legal dispatch_plan set (routing parity). */
    private const LEGACY_TO_DISPATCH = [
        'product_search' => ['product', 'clarification'],
        'knowledge_query' => ['knowledge'],
        'ambiguous' => ['clarification'],
    ];

    /** AIU intent category => report count bucket. */
    private const AIU_TO_BUCKET = [
        'product_search' => 'product',
        'knowledge' => 'knowledge',
        'ambiguous' => 'ambiguous',
    ];

    /**
     * Parse a single log line; non-shadow records or bad lines return null (never-throw).
     *
     * Expected format: `[YYYY-mm-dd HH:ii:ss][step] {json}`.
     *
     * @return array<string, mixed>|null
     */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (strpos($line, '[' . self::SHADOW_STEP . ']') === false) {
            return null;
        }

        $brace = strpos($line, '{');
        if ($brace === false) {
            return null;
        }

        $json = substr($line, $brace);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Parse many lines; returns the list of shadow records (bad lines skipped).
     *
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    public static function parseLines(array $lines): array
    {
        $records = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            $record = self::parseLine($line);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Summarize a list of shadow records (never-throw).
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    public static function summarize(array $records): array
    {
        $summary = [
            'total' => 0,
            'counts' => ['product' => 0, 'knowledge' => 0, 'ambiguous' => 0, 'other' => 0],
            'human_bucket' => 0,
            'intent_ok' => 0,
            'routing_ok' => 0,
            'routing_denominator' => 0,
            'clarification_ok' => 0,
            'intent_parity_pct' => null,
            'routing_parity_pct' => null,
            'clarification_parity_pct' => null,
            'mismatch_count' => 0,
            'mismatches' => [],
        ];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $summary['total']++;

            $legacy = (string) ($record['legacy_intent_type'] ?? '');
            $aiu = (string) ($record['aiu_intent'] ?? '');
            $dispatch = (string) ($record['dispatch_plan'] ?? '');
            $hint = $record['execution_hint'] ?? null;
            $owner = (string) ($record['owner_snapshot'] ?? '');
            $clarRequired = (bool) ($record['clarification_required'] ?? false);

            $bucket = self::AIU_TO_BUCKET[$aiu] ?? 'other';
            $summary['counts'][$bucket]++;

            $expectedAiu = self::LEGACY_TO_AIU[$legacy] ?? null;
            $intentOk = $expectedAiu !== null && $expectedAiu === $aiu;
            if ($intentOk) {
                $summary['intent_ok']++;
            }

            $isHuman = $owner === 'HUMAN' || $dispatch === 'human';
            $routingOk = true;
            if ($isHuman) {
                $summary['human_bucket']++;
            } else {
                $summary['routing_denominator']++;
                $allowed = self::LEGACY_TO_DISPATCH[$legacy] ?? [];
                $routingOk = in_array($dispatch, $allowed, true);
                if ($routingOk) {
                    $summary['routing_ok']++;
                }
            }

            $clarOk = $clarRequired === ($dispatch === 'clarification');
            if ($clarOk) {
                $summary['clarification_ok']++;
            }

            if (!$intentOk || (!$isHuman && !$routingOk) || !$clarOk) {
                $summary['mismatch_count']++;
                $summary['mismatches'][] = [
                    'tenant_sno' => (string) ($record['tenant_sno'] ?? ''),
                    'conversation_id' => (string) ($record['conversation_id'] ?? ''),
                    'trace_id' => (string) ($record['trace_id'] ?? ''),
                    'message_hash' => (string) ($record['message_hash'] ?? ''),
                    'legacy_intent' => $legacy,
                    'aiu_intent' => $aiu,
                    'dispatch_plan' => $dispatch,
                    'execution_hint' => $hint === null ? null : (string) $hint,
                    'failed' => array_values(array_filter([
                        $intentOk ? null : 'intent',
                        (!$isHuman && !$routingOk) ? 'routing' : null,
                        $clarOk ? null : 'clarification',
                    ])),
                ];
            }
        }

        $summary['intent_parity_pct'] = self::pct($summary['intent_ok'], $summary['total']);
        $summary['routing_parity_pct'] = self::pct($summary['routing_ok'], $summary['routing_denominator']);
        $summary['clarification_parity_pct'] = self::pct($summary['clarification_ok'], $summary['total']);

        return $summary;
    }

    /**
     * Read a log file and summarize (never-throw). Missing file => empty summary.
     *
     * @return array<string, mixed>
     */
    public static function fromFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return self::summarize([]);
        }

        $contents = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($contents)) {
            return self::summarize([]);
        }

        return self::summarize(self::parseLines($contents));
    }

    /**
     * Format a summary into a plain-text report.
     *
     * @param array<string, mixed> $summary
     */
    public static function format(array $summary): string
    {
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $lines = [];
        $lines[] = '=== AIU Shadow Probe Parity Report (Phase 2-D-3-2A) ===';
        $lines[] = 'Total Messages      : ' . (int) ($summary['total'] ?? 0);
        $lines[] = 'Product Count       : ' . (int) ($counts['product'] ?? 0);
        $lines[] = 'Knowledge Count     : ' . (int) ($counts['knowledge'] ?? 0);
        $lines[] = 'Ambiguous Count     : ' . (int) ($counts['ambiguous'] ?? 0);
        $lines[] = 'Other Count         : ' . (int) ($counts['other'] ?? 0);
        $lines[] = 'Human Bucket        : ' . (int) ($summary['human_bucket'] ?? 0) . ' (observational, excluded from routing)';
        $lines[] = 'Intent Parity %     : ' . self::fmtPct($summary['intent_parity_pct'] ?? null);
        $lines[] = 'Routing Parity %    : ' . self::fmtPct($summary['routing_parity_pct'] ?? null)
            . ' (denominator=' . (int) ($summary['routing_denominator'] ?? 0) . ')';
        $lines[] = 'Clarification Parity: ' . self::fmtPct($summary['clarification_parity_pct'] ?? null);
        $lines[] = 'Mismatch Count      : ' . (int) ($summary['mismatch_count'] ?? 0);

        $mismatches = is_array($summary['mismatches'] ?? null) ? $summary['mismatches'] : [];
        if ($mismatches !== []) {
            $lines[] = '';
            $lines[] = '--- Mismatch Detail (no raw message; message_hash only) ---';
            foreach ($mismatches as $m) {
                $failed = is_array($m['failed'] ?? null) ? implode('+', $m['failed']) : '';
                $lines[] = sprintf(
                    '[%s] conv=%s trace=%s hash=%s legacy=%s aiu=%s dispatch=%s hint=%s failed=%s',
                    (string) ($m['tenant_sno'] ?? ''),
                    (string) ($m['conversation_id'] ?? ''),
                    (string) ($m['trace_id'] ?? ''),
                    (string) ($m['message_hash'] ?? ''),
                    (string) ($m['legacy_intent'] ?? ''),
                    (string) ($m['aiu_intent'] ?? ''),
                    (string) ($m['dispatch_plan'] ?? ''),
                    $m['execution_hint'] === null ? 'null' : (string) ($m['execution_hint'] ?? ''),
                    $failed
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public static function defaultLogPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'saas_router.log';
    }

    /**
     * @return float|null  null means denominator is 0 (N/A).
     */
    private static function pct(int $ok, int $total): ?float
    {
        if ($total <= 0) {
            return null;
        }

        return round($ok / $total * 100, 2);
    }

    private static function fmtPct(?float $pct): string
    {
        return $pct === null ? 'N/A (0 samples)' : number_format($pct, 2) . '%';
    }
}

// --- CLI entry (read-only) -------------------------------------------------
// Runs only when executed directly; not when require_once'd by tests, so the
// class stays unit-testable.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $path = isset($argv[1]) && trim((string) $argv[1]) !== ''
        ? (string) $argv[1]
        : IntentParityReport::defaultLogPath();

    fwrite(STDOUT, 'Log source: ' . $path . "\n");
    $summary = IntentParityReport::fromFile($path);
    fwrite(STDOUT, IntentParityReport::format($summary));
}