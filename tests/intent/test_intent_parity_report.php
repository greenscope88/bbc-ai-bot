<?php

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'DispatchPlan.php';
require_once $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ops'
    . DIRECTORY_SEPARATOR . 'phase2d3_intent_parity_report.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/**
 * Build a log line in the real Logger format: `[ts][step] {json}`.
 *
 * @param array<string, mixed> $context
 */
function shadow_line(array $context): string
{
    return '[2026-06-30 10:00:00][' . IntentParityReport::SHADOW_STEP . '] '
        . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @return array<string, mixed>
 */
function shadow_record(
    string $legacy,
    string $aiu,
    string $dispatch,
    ?string $hint,
    string $owner = 'AI',
    bool $clar = false
): array {
    return [
        'trace_id' => 'tr-' . $aiu,
        'tenant_sno' => '5f99b8d665e8444d',
        'conversation_id' => '5f99b8d665e8444d:line:Ux',
        'legacy_intent_type' => $legacy,
        'aiu_intent' => $aiu,
        'dispatch_plan' => $dispatch,
        'execution_hint' => $hint,
        'owner_snapshot' => $owner,
        'clarification_required' => $clar,
        'clarification_reason' => $clar ? 'date_required' : '',
        'message_hash' => substr(hash('sha256', $legacy . $aiu . $dispatch), 0, 16),
        'parity' => true,
        'shadow_mode' => true,
    ];
}

// ============================================================================
// 1. Empty log => zeroed summary, null percentages, never-throw
// ============================================================================
$empty = IntentParityReport::summarize([]);
test_assert($empty['total'] === 0, 'empty: total 0');
test_assert($empty['mismatch_count'] === 0, 'empty: mismatch 0');
test_assert($empty['intent_parity_pct'] === null, 'empty: intent pct null');
test_assert($empty['routing_parity_pct'] === null, 'empty: routing pct null');
test_assert($empty['clarification_parity_pct'] === null, 'empty: clarification pct null');
test_assert(is_string(IntentParityReport::format($empty)), 'empty: format returns string');

// ============================================================================
// 2. parseLine: shadow vs non-shadow vs bad json
// ============================================================================
$line = shadow_line(shadow_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::PRODUCT, 'product_search'));
test_assert(IntentParityReport::parseLine($line) !== null, 'parse: valid shadow line decoded');
test_assert(IntentParityReport::parseLine('[2026-06-30 10:00:00][other_step] {"a":1}') === null, 'parse: non-shadow line null');
test_assert(IntentParityReport::parseLine('[2026-06-30 10:00:00][' . IntentParityReport::SHADOW_STEP . '] not-json') === null, 'parse: bad json null');
test_assert(IntentParityReport::parseLine('') === null, 'parse: empty line null');

// ============================================================================
// 3. Normal log: product + knowledge + ambiguous, all parity => 100%
// ============================================================================
$lines = [
    shadow_line(shadow_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::PRODUCT, 'product_search')),
    shadow_line(shadow_record('knowledge_query', AiIntentCategory::KNOWLEDGE, DispatchPlan::KNOWLEDGE, 'knowledge_resolve')),
    shadow_line(shadow_record('ambiguous', AiIntentCategory::AMBIGUOUS, DispatchPlan::CLARIFICATION, null, 'AI', true)),
    'random noise line that should be ignored',
    shadow_line(shadow_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::CLARIFICATION, 'product_clarification', 'AI', true)),
];
$records = IntentParityReport::parseLines($lines);
test_assert(count($records) === 4, 'normal: 4 shadow records parsed (noise skipped)');

$sum = IntentParityReport::summarize($records);
test_assert($sum['total'] === 4, 'normal: total 4');
test_assert($sum['counts']['product'] === 2, 'normal: product count 2');
test_assert($sum['counts']['knowledge'] === 1, 'normal: knowledge count 1');
test_assert($sum['counts']['ambiguous'] === 1, 'normal: ambiguous count 1');
test_assert($sum['intent_parity_pct'] === 100.0, 'normal: intent parity 100%');
test_assert($sum['routing_parity_pct'] === 100.0, 'normal: routing parity 100%');
test_assert($sum['clarification_parity_pct'] === 100.0, 'normal: clarification parity 100%');
test_assert($sum['mismatch_count'] === 0, 'normal: zero mismatch');

// ============================================================================
// 4. Intent + routing mismatch: legacy product_search but AIU knowledge/knowledge
// ============================================================================
$mm = IntentParityReport::summarize([
    shadow_record('product_search', AiIntentCategory::KNOWLEDGE, DispatchPlan::KNOWLEDGE, 'knowledge_resolve'),
]);
test_assert($mm['total'] === 1, 'mismatch: total 1');
test_assert($mm['intent_parity_pct'] === 0.0, 'mismatch: intent parity 0%');
test_assert($mm['routing_parity_pct'] === 0.0, 'mismatch: routing parity 0% (knowledge not legal for product_search)');
test_assert($mm['mismatch_count'] === 1, 'mismatch: one mismatch recorded');
$detail = $mm['mismatches'][0];
test_assert($detail['legacy_intent'] === 'product_search', 'mismatch detail: legacy_intent');
test_assert($detail['aiu_intent'] === AiIntentCategory::KNOWLEDGE, 'mismatch detail: aiu_intent');
test_assert($detail['dispatch_plan'] === DispatchPlan::KNOWLEDGE, 'mismatch detail: dispatch_plan');
test_assert($detail['message_hash'] !== '', 'mismatch detail: message_hash present');
test_assert(in_array('intent', $detail['failed'], true), 'mismatch detail: intent flagged');
test_assert(in_array('routing', $detail['failed'], true), 'mismatch detail: routing flagged');

// ============================================================================
// 5. Clarification parity mismatch: required but dispatch != clarification
// ============================================================================
$cm = IntentParityReport::summarize([
    shadow_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::PRODUCT, 'product_search', 'AI', true),
]);
test_assert($cm['clarification_parity_pct'] === 0.0, 'clar-mismatch: clarification parity 0%');
test_assert($cm['mismatch_count'] === 1, 'clar-mismatch: one mismatch');
test_assert(in_array('clarification', $cm['mismatches'][0]['failed'], true), 'clar-mismatch: clarification flagged');

// ============================================================================
// 6. Human bucket: owner=HUMAN excluded from routing denominator
// ============================================================================
$hb = IntentParityReport::summarize([
    shadow_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::HUMAN, 'human_blocked', 'HUMAN', false),
    shadow_record('knowledge_query', AiIntentCategory::KNOWLEDGE, DispatchPlan::KNOWLEDGE, 'knowledge_resolve', 'AI', false),
]);
test_assert($hb['human_bucket'] === 1, 'human: human_bucket 1');
test_assert($hb['routing_denominator'] === 1, 'human: routing denominator excludes human');
test_assert($hb['routing_parity_pct'] === 100.0, 'human: routing parity 100% over non-human');
test_assert($hb['mismatch_count'] === 0, 'human: no mismatch (human observational)');

// ============================================================================
// 7. fromFile: missing file => empty summary (never-throw)
// ============================================================================
$missing = IntentParityReport::fromFile($root . DIRECTORY_SEPARATOR . 'no_such_log_' . uniqid() . '.log');
test_assert($missing['total'] === 0, 'fromFile: missing file => total 0');

// ============================================================================
// 8. never-throw: garbage records are tolerated
// ============================================================================
$threw = false;
try {
    $g = IntentParityReport::summarize([
        ['legacy_intent_type' => null, 'aiu_intent' => 123],
        [],
    ]);
    test_assert($g['total'] === 2, 'never-throw: garbage counted without exception');
} catch (\Throwable $e) {
    $threw = true;
}
test_assert($threw === false, 'never-throw: summarize tolerates garbage');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_intent_parity_report\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_intent_parity_report\n");
exit(1);
