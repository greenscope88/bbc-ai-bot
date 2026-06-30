<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-3-2B - Live Shadow Validation Gate tests.
 *
 * Verifies the gate evaluator (read-only) maps the Phase 2-D-3-2 Sign-off Criteria to a
 * single verdict (PASS / FAIL / NO_DATA), reuses IntentParityReport faithfully, treats
 * "all-human" (routing N/A) as non-blocking, and never throws on garbage / missing input.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'DispatchPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'ExecutionHint.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
    . 'ops' . DIRECTORY_SEPARATOR . 'phase2d3_live_shadow_validation_gate.php';

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
 * Build a shadow record the way the live AIU Shadow Probe logs it.
 *
 * @return array<string, mixed>
 */
function gate_record(string $legacy, string $aiu, string $dispatch, bool $clar, string $owner = 'AI'): array
{
    return [
        'tenant_sno' => '5f99b8d665e8444d',
        'conversation_id' => 'line:5f99b8d665e8444d:U-test',
        'trace_id' => 'trace-' . substr(md5($legacy . $aiu . $dispatch), 0, 8),
        'message_hash' => substr(hash('sha256', $legacy . $aiu), 0, 16),
        'legacy_intent_type' => $legacy,
        'aiu_intent' => $aiu,
        'dispatch_plan' => $dispatch,
        'execution_hint' => ExecutionHint::PRODUCT_SEARCH,
        'owner_snapshot' => $owner,
        'clarification_required' => $clar,
    ];
}

// --- Case 1: clean live sample -> PASS -------------------------------------
$cleanRecords = [
    gate_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::PRODUCT, false),
    gate_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::CLARIFICATION, true),
    gate_record('knowledge_query', AiIntentCategory::KNOWLEDGE, DispatchPlan::KNOWLEDGE, false),
    gate_record('ambiguous', AiIntentCategory::AMBIGUOUS, DispatchPlan::CLARIFICATION, true),
];
$cleanSummary = IntentParityReport::summarize($cleanRecords);
$passGate = LiveShadowValidationGate::evaluate($cleanSummary);
test_assert($passGate['verdict'] === LiveShadowValidationGate::VERDICT_PASS, 'case1: clean sample PASS');
test_assert($passGate['reasons'] === [], 'case1: no reasons on PASS');
test_assert($passGate['total'] === 4, 'case1: total counted');
test_assert(LiveShadowValidationGate::exitCodeFor($passGate['verdict']) === LiveShadowValidationGate::EXIT_PASS, 'case1: exit 0');

// --- Case 2: empty / no data -> NO_DATA ------------------------------------
$noData = LiveShadowValidationGate::evaluate(IntentParityReport::summarize([]));
test_assert($noData['verdict'] === LiveShadowValidationGate::VERDICT_NO_DATA, 'case2: empty -> NO_DATA');
test_assert(in_array('no_shadow_samples', $noData['reasons'], true), 'case2: no_shadow_samples reason');
test_assert(LiveShadowValidationGate::exitCodeFor($noData['verdict']) === LiveShadowValidationGate::EXIT_NO_DATA, 'case2: exit 2');

// --- Case 3: intent mismatch -> FAIL ---------------------------------------
$intentBad = IntentParityReport::summarize([
    gate_record('product_search', AiIntentCategory::KNOWLEDGE, DispatchPlan::KNOWLEDGE, false),
]);
$intentGate = LiveShadowValidationGate::evaluate($intentBad);
test_assert($intentGate['verdict'] === LiveShadowValidationGate::VERDICT_FAIL, 'case3: intent mismatch FAIL');
test_assert(in_array('intent_parity_below_100', $intentGate['reasons'], true), 'case3: intent reason');
test_assert(in_array('mismatches_present', $intentGate['reasons'], true), 'case3: mismatch reason');
test_assert(LiveShadowValidationGate::exitCodeFor($intentGate['verdict']) === LiveShadowValidationGate::EXIT_FAIL, 'case3: exit 1');

// --- Case 4: clarification mismatch -> FAIL --------------------------------
$clarBad = IntentParityReport::summarize([
    // product but flagged clarification_required while routed to product (not clarification)
    gate_record('product_search', AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::PRODUCT, true),
]);
$clarGate = LiveShadowValidationGate::evaluate($clarBad);
test_assert($clarGate['verdict'] === LiveShadowValidationGate::VERDICT_FAIL, 'case4: clarification mismatch FAIL');
test_assert(in_array('clarification_parity_below_100', $clarGate['reasons'], true), 'case4: clarification reason');

// --- Case 5: all-human (routing N/A) but intent+clar clean -> PASS ----------
$humanOnly = IntentParityReport::summarize([
    gate_record('knowledge_query', AiIntentCategory::KNOWLEDGE, DispatchPlan::HUMAN, false, 'HUMAN'),
]);
test_assert($humanOnly['routing_parity_pct'] === null, 'case5: routing N/A (human only)');
$humanGate = LiveShadowValidationGate::evaluate($humanOnly);
test_assert($humanGate['verdict'] === LiveShadowValidationGate::VERDICT_PASS, 'case5: all-human PASS (routing not blocking)');

// --- Case 6: never-throw on garbage ----------------------------------------
$garbage = LiveShadowValidationGate::evaluate([]);
test_assert($garbage['verdict'] === LiveShadowValidationGate::VERDICT_NO_DATA, 'case6: empty array -> NO_DATA');
$weird = LiveShadowValidationGate::evaluate(['total' => 'x', 'intent_parity_pct' => 'y', 'mismatch_count' => 'z']);
test_assert(is_string($weird['verdict']), 'case6: garbage tolerated, verdict string');

// --- Case 7: fromFile on missing file -> NO_DATA (read-only, never-throw) ----
$missing = LiveShadowValidationGate::fromFile(__DIR__ . DIRECTORY_SEPARATOR . '__no_such_log__.log');
test_assert($missing['verdict'] === LiveShadowValidationGate::VERDICT_NO_DATA, 'case7: missing file -> NO_DATA');
test_assert(is_array($missing['summary'] ?? null), 'case7: summary attached');

// --- Case 8: format renders report body + gate footer -----------------------
$rendered = LiveShadowValidationGate::format($passGate + ['summary' => $cleanSummary]);
test_assert(strpos($rendered, 'Live Shadow Validation Gate (Phase 2-D-3-2B)') !== false, 'case8: gate footer present');
test_assert(strpos($rendered, 'Verdict             : PASS') !== false, 'case8: verdict line present');
test_assert(strpos($rendered, 'AIU Shadow Probe Parity Report') !== false, 'case8: report body reused');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_live_shadow_validation_gate (all passed)\n");
exit(0);
