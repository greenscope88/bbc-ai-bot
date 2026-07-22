<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentContextLoader.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuGeminiUnderstandingClientStub.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuPromptRequest.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateStore.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeIdentity.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeLoadResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeMutationResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeDispositionContract.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentCategory.php';
require_once dirname(__DIR__, 2) . '/tests/support/AiuDestinationSemanticsTestFixtures.php';

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbc_resume_rt_' . getmypid();
@mkdir($dir, 0775, true);
$store = new StructuredSearchResumeStateStore($dir);
$identity = StructuredSearchResumeIdentity::fromParts('5f99b8d665e8444d', 'OArt', 'Urt');
$ref = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));

$lastPrompt = null;
$script = [];

$client = new AiuGeminiUnderstandingClientStub(function (AiuPromptRequest $request) use (&$lastPrompt, &$script) {
    $lastPrompt = $request;
    if ($script === []) {
        throw new RuntimeException('stub script empty');
    }
    $next = array_shift($script);

    return is_callable($next) ? $next($request) : $next;
});

$runtime = new AiIntentUnderstandingRuntime(
    $client,
    null,
    null,
    AiIntentContextLoader::createForTesting(),
    $store
);

$baseCtx = [
    'tenant_sno' => '5f99b8d665e8444d',
    'conversation_id' => '5f99b8d665e8444d:line:Urt',
    'channel' => 'line',
    'channel_id' => 'OArt',
    'line_user_id' => 'Urt',
    'trace_id' => 'tid-1',
    'now' => $ref,
    'reference_date' => $ref,
];

function product_clarif_missing_dates(array $dest, string $disposition = ''): array
{
    $out = [
        'intent' => 'product_search',
        'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities([
            'date_range' => null,
            'date_from' => null,
            'date_to' => null,
        ], $dest, 'single'),
        'confidence' => 0.9,
        'clarification' => ['required' => true, 'reason' => 'missing_travel_dates'],
    ];
    if ($disposition !== '') {
        $out['resume_disposition'] = $disposition;
    }

    return $out;
}

function product_ready(array $dest, string $from, string $to, string $disposition = ''): array
{
    $out = [
        'intent' => 'product_search',
        'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities([
            'date_range' => ['from' => $from, 'to' => $to],
            'date_from' => $from,
            'date_to' => $to,
            'date_expression' => '8月',
        ], $dest, 'single'),
        'confidence' => 0.95,
        'clarification' => ['required' => false, 'reason' => ''],
    ];
    if ($disposition !== '') {
        $out['resume_disposition'] = $disposition;
    }

    return $out;
}

// 20 no prior state disposition rule + 23 save-before-clarification
$script = [product_clarif_missing_dates(['日本'])];
$r1 = $runtime->understand('日本旅遊', $baseCtx + ['webhook_event_id' => 'EVT-A']);
assert_true($r1->isClarificationRequired(), 'clarif required');
assert_true($lastPrompt !== null && !$lastPrompt->hasStructuredSearchResumeState(), 'no prior injected');
$load = $store->load($identity, $ref);
assert_true($load->isFound(), 'saved before clarif return');
assert_true($load->getState()->getStateVersion() === 1, 'v1 state_version saved');
assert_true($load->getState()->getSchemaVersion() === 2, 'schema v2 saved');
assert_true($load->getState()->getStatus() === 'WAITING_CLARIFICATION', 'aiu waiting status');
assert_true($load->getState()->getKnownEntities()['destination'] === ['日本'], 'dest persisted');

// 17 continue_pending — Gemini full authority, no Runtime merge
$script = [product_clarif_missing_dates(['日本'], 'continue_pending')];
$r2 = $runtime->understand('8月', $baseCtx + ['webhook_event_id' => 'EVT-B']);
assert_true($lastPrompt->hasStructuredSearchResumeState(), 'prior injected');
assert_true($r2->getResumeDisposition() === 'continue_pending', 'continue_pending');
assert_true($store->load($identity, $ref)->getState()->getStateVersion() === 2, 'replace v2');
// Runtime must not invent merge: entities come only from stub output
assert_true($r2->getEntities()['destination'] === ['日本'], 'entities from Gemini only');

// 18 modify_pending
$script = [product_clarif_missing_dates(['大阪'], 'modify_pending')];
$r3 = $runtime->understand('改大阪', $baseCtx + ['webhook_event_id' => 'EVT-C']);
assert_true($r3->getResumeDisposition() === 'modify_pending', 'modify_pending');
assert_true($r3->getEntities()['destination'] === ['大阪'], 'modify uses Gemini entities');
assert_true($store->load($identity, $ref)->getState()->getKnownEntities()['destination'] === ['大阪'], 'stored Osaka');

// 21 invalid disposition fail-closed — pending preserved
$before = $store->load($identity, $ref)->getState()->getStateVersion();
$script = [product_clarif_missing_dates(['大阪'], 'not_a_disposition')];
$threw = false;
try {
    $runtime->understand('x', $baseCtx + ['webhook_event_id' => 'EVT-D']);
} catch (Throwable $e) {
    $threw = true;
}
assert_true($threw, 'invalid disposition throws');
assert_true($store->load($identity, $ref)->getState()->getStateVersion() === $before, '25 failure preserves pending');

// missing disposition with prior
$script = [product_clarif_missing_dates(['大阪'], '')];
$threw = false;
try {
    $runtime->understand('x', $baseCtx + ['webhook_event_id' => 'EVT-E']);
} catch (Throwable $e) {
    $threw = true;
}
assert_true($threw, 'missing disposition fail-closed');

// 19 new_request clears then may create new clarif pending
$script = [product_clarif_missing_dates(['北海道'], 'new_request')];
$r4 = $runtime->understand('北海道呢', $baseCtx + ['webhook_event_id' => 'EVT-F']);
assert_true($r4->getResumeDisposition() === 'new_request', 'new_request');
assert_true($store->load($identity, $ref)->getState()->getKnownEntities()['destination'] === ['北海道'], 'new pending');

// same-event replay no bump
$v = $store->load($identity, $ref)->getState()->getStateVersion();
$script = [product_clarif_missing_dates(['北海道'], 'continue_pending')];
$runtime->understand('北海道呢', $baseCtx + ['webhook_event_id' => 'EVT-F']);
assert_true($store->load($identity, $ref)->getState()->getStateVersion() === $v, 'idempotent no bump');

// 24 clear-before-search
$script = [product_ready(['北海道'], '2026-08-01', '2026-08-31', 'continue_pending')];
$r5 = $runtime->understand('8月', $baseCtx + ['webhook_event_id' => 'EVT-G']);
assert_true(!$r5->isClarificationRequired(), 'resolved');
assert_true(!$store->load($identity, $ref)->isFound(), 'cleared before search');
$obs = $r5->getStructuredSearchResumeObservability();
assert_true(
    is_array($obs) && ($obs['clear_before_search_result'] ?? '') === StructuredSearchResumeMutationResult::CLEARED,
    'clear-before-search obs'
);

// 22/27 QueryMerger remains passthrough (no semantic revive in this changeset)
$qmSrc = file_get_contents(dirname(__DIR__, 2) . '/core/search/ConversationQueryMerger.php');
assert_true(strpos($qmSrc, 'function mergeDateClarificationFollowUp') !== false, 'QueryMerger method present');
assert_true(strpos($qmSrc, 'structured_search_resume') === false, 'QueryMerger untouched by resume');

// Host B=0 path is Router fail-closed on throw — simulate storage invalid event id
$script = [product_clarif_missing_dates(['京都'])];
$threw = false;
try {
    $runtime->understand('京都', $baseCtx + ['webhook_event_id' => '']);
} catch (Throwable $e) {
    $threw = true;
    assert_true(strpos($e->getMessage(), 'missing_webhook_event_id') !== false, '26 invalid event fail-closed');
}
assert_true($threw, 'missing event id throws');

foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

if ($failures === 0) {
    echo "ALL PASS test_structured_search_resume_runtime\n";
    exit(0);
}
echo "FAILED {$failures}\n";
exit(1);
