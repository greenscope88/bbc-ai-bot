<?php

/**
 * Phase 2-D Step 2-D-3-2 — Pilot Parity Validation (travel_b).
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md；Phase 2-D-3 Runtime Integration
 *       Review（Shadow Strategy / Sign-off Criteria）；Phase 2-D-3-2A Parity Report.
 *
 * 目的：在「shadow 模式」下，對 travel_b Pilot Tenant 的代表性訊息語料同時執行
 * Legacy Intent Detection 與 AIU Runtime（經 Shadow Probe），再以 IntentParityReport
 * 量化 Intent / Routing / Clarification Parity，作為 Pilot Validation 的可重現證據。
 *
 * 嚴格邊界（Shadow Validation Only / Production Behavior Must Remain Identical）：
 *   - **不**改動全域 config feature flag（intent_understanding_shadow_enabled 仍預設
 *     OFF）；本驗證以「注入式 flag-ON config」於記憶體內啟用 shadow，正式 Runtime /
 *     Reply Flow 完全不受影響。
 *   - **唯讀 / Owner First**：AIU 僅讀取 Owner / Memory / State；in-memory facade
 *     與磁碟隔離；不寫任何狀態。
 *   - 端對端保真：捕捉到的 shadow log context 會序列化為真實 log 行格式，再經
 *     IntentParityReport::parseLine() 解析後彙整，等同正式 log → 報表的流程。
 */

$root = dirname(__DIR__, 2);
require_once $root . '/core/search/KnowledgeIntentDetector.php';
require_once $root . '/core/intent/AiIntentUnderstandingShadowProbe.php';
require_once $root . '/core/intent/AiIntentContextLoader.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once $root . '/core/intent/DispatchPlan.php';
require_once $root . '/core/intent/ExecutionHint.php';
require_once $root . '/core/conversation/ConversationRuntimeFacade.php';
require_once $root . '/var/ops/phase2d3_intent_parity_report.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$base = new DateTimeImmutable('2026-06-30 13:00:00', $tz);
$pilotSno = '5f99b8d665e8444d'; // travel_b
$detector = new KnowledgeIntentDetector();

$flagOn = [
    AiIntentUnderstandingShadowProbe::FLAG_ENABLED => true,
    AiIntentUnderstandingShadowProbe::FLAG_TENANTS => [$pilotSno],
];

/**
 * Representative travel_b pilot corpus.
 * owner: 'AI' (normal) | 'HUMAN' (takeover, observational bucket).
 */
$corpus = [
    ['msg' => '我想3月去東京自由行', 'legacy' => 'product_search', 'owner' => 'AI'],
    ['msg' => '我想去東京自由行', 'legacy' => 'product_search', 'owner' => 'AI'], // missing date -> clarification
    ['msg' => '請問你們的客服電話是多少', 'legacy' => 'knowledge_query', 'owner' => 'AI'],
    ['msg' => '請問護照怎麼辦', 'legacy' => 'knowledge_query', 'owner' => 'AI'],
    ['msg' => '可以刷卡嗎', 'legacy' => 'knowledge_query', 'owner' => 'AI'],
    ['msg' => '我想出去玩', 'legacy' => 'ambiguous', 'owner' => 'AI'],
    ['msg' => '幫我看看', 'legacy' => 'ambiguous', 'owner' => 'AI'],
    // Owner=HUMAN takeover: AIU still understands, dispatch=human (observational).
    ['msg' => '我想3月去東京自由行', 'legacy' => 'product_search', 'owner' => 'HUMAN'],
];

$captured = [];
$logger = static function (string $step, array $context) use (&$captured): void {
    $captured[] = ['step' => $step, 'context' => $context];
};

/** Serialize a captured context to a real Logger-format line. */
function as_log_line(string $step, array $context): string
{
    return sprintf(
        '[%s][%s] %s',
        '2026-06-30 13:00:00',
        $step,
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$logLines = [];
$contractViolations = 0;
$executedCount = 0;
$idx = 0;

foreach ($corpus as $case) {
    $idx++;
    $cid = $pilotSno . ':line:Upilot' . $idx;
    $now = $base->modify('+1 minute');

    // --- Legacy intent (production path source of truth) ---
    $legacy = (string) ($detector->detect($case['msg'])['intent_type'] ?? '');
    test_assert($legacy === $case['legacy'], "corpus legacy classification stable: {$case['msg']}");

    // --- Conversation facade (isolated, in-memory). Owner=HUMAN via takeover ---
    $facade = ConversationRuntimeFacade::createForTesting();
    if ($case['owner'] === 'HUMAN') {
        $facade->state()->recordHumanAgentMessage($cid, $base);
    }

    $runtime = AiIntentUnderstandingRuntime::createForTesting(
        null,
        null,
        AiIntentContextLoader::createForTesting($facade)
    );

    // --- Shadow probe (flag ON via injected config; global config untouched) ---
    $captured = [];
    $res = AiIntentUnderstandingShadowProbe::run([
        'config' => $flagOn,
        'tenant_sno' => $pilotSno,
        'conversation_id' => $cid,
        'message' => $case['msg'],
        'legacy_intent_type' => $legacy,
        'trace_id' => 'pilot-' . $idx,
        'now' => $now,
        'reference_date' => $now,
    ], $runtime, $logger);

    test_assert($res['executed'] === true, "shadow executed: {$case['msg']} ({$case['owner']})");
    if ($res['executed'] === true) {
        $executedCount++;
    }

    // --- Contract validation: closed enums only ---
    if (!DispatchPlan::isValid((string) ($res['dispatch_plan'] ?? ''))) {
        $contractViolations++;
    }
    if (!ExecutionHint::isValid($res['execution_hint'] ?? null)) {
        $contractViolations++;
    }

    // Owner=HUMAN must surface dispatch_plan=human (Owner First, observational).
    if ($case['owner'] === 'HUMAN') {
        test_assert($res['dispatch_plan'] === DispatchPlan::HUMAN, 'human takeover: dispatch_plan=human');
        test_assert($res['owner_snapshot'] === 'HUMAN', 'human takeover: owner_snapshot=HUMAN');
        // Owner First: AIU did not mutate stored owner.
        test_assert($facade->state()->get($cid)->getOwner() === 'HUMAN', 'human takeover: state owner unchanged by AIU');
    }

    test_assert(count($captured) === 1, "exactly one shadow log line: {$case['msg']}");
    if ($captured !== []) {
        $logLines[] = as_log_line($captured[0]['step'], $captured[0]['context']);
    }
}

test_assert($executedCount === count($corpus), 'all corpus messages executed shadow understanding');
test_assert($contractViolations === 0, 'contract: all dispatch_plan / execution_hint within closed enums');

// ============================================================================
// End-to-end: log lines -> parse -> parity report (same path as production logs)
// ============================================================================
$records = IntentParityReport::parseLines($logLines);
test_assert(count($records) === count($corpus), 'report: every shadow line parsed back');

$summary = IntentParityReport::summarize($records);

test_assert($summary['total'] === count($corpus), 'report: total matches corpus size');
test_assert($summary['counts']['product'] === 3, 'report: product count 3');
test_assert($summary['counts']['knowledge'] === 3, 'report: knowledge count 3');
test_assert($summary['counts']['ambiguous'] === 2, 'report: ambiguous count 2');
test_assert($summary['human_bucket'] === 1, 'report: human bucket 1 (observational)');

// Sign-off criteria.
test_assert($summary['intent_parity_pct'] === 100.0, 'SIGN-OFF: Intent Parity 100%');
test_assert($summary['routing_parity_pct'] === 100.0, 'SIGN-OFF: Routing Parity 100% (non-human)');
test_assert($summary['clarification_parity_pct'] === 100.0, 'SIGN-OFF: Clarification Parity 100%');
test_assert($summary['mismatch_count'] === 0, 'SIGN-OFF: zero mismatch (allowed divergence only)');

// ============================================================================
// Pilot Validation Report (human-readable evidence)
// ============================================================================
echo "\n";
echo IntentParityReport::format($summary);
echo "\n";

if ($failures === 0) {
    echo "ALL PASS test_pilot_parity_validation\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_pilot_parity_validation\n");
exit(1);
