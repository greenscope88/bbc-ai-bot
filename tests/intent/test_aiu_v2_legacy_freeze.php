<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntimeSelector.php';
require_once $root . '/core/intent/AiuProductIntentTranslator.php';
require_once $root . '/core/intent/AiuGeminiUnderstandingClientStub.php';
require_once $root . '/core/intent/AiuPromptRequest.php';
require_once $root . '/core/search/BatsSearchIntentMapper.php';
require_once $root . '/core/product_source/SourceQueryMapper.php';
require_once $root . '/core/tour_prompt_context_service.php';
require_once $root . '/core/saas_router.php';
require_once $root . '/core/search/Phase9C1FeatureGate.php';
require_once $root . '/tests/support/AiuDestinationSemanticsTestFixtures.php';

$failures = 0;
function lf_assert(bool $ok, string $msg): void { global $failures; if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: $msg\n"); } }

$pilotSno = Phase9C1FeatureGate::TRAVEL_B_SNO;
$cid = $pilotSno . ':line:U-legacy-freeze';
$ref = new DateTimeImmutable('2026-07-09', new DateTimeZone('Asia/Taipei'));
$flagOn = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => [$pilotSno],
];
$translator = new AiuProductIntentTranslator();
$mapper = new BatsSearchIntentMapper();

function runCase(string $utterance, AiIntentUnderstandingRuntime $runtime): array {
    global $pilotSno, $cid, $ref, $flagOn, $translator, $mapper;
    $sel = AiIntentUnderstandingRuntimeSelector::resolve([
        'tenant_sno' => $pilotSno,
        'conversation_id' => $cid,
        'message' => $utterance,
        'now' => $ref,
        'reference_date' => $ref,
        'config' => $flagOn,
    ], $runtime);
    $auth = null;
    if (($sel['aiu_result'] ?? null) instanceof AiIntentUnderstandingResult) {
        $auth = $translator->translate($sel['aiu_result']);
    }
    $trace = AiIntentUnderstandingRuntimeSelector::resolveProductUnderstandingTrace($sel, $auth, $pilotSno, $flagOn);
    $cond = $auth !== null ? $mapper->toSearchCondition($auth) : null;
    $src = $cond !== null ? SourceQueryMapper::buildSourceKeywordQueryFromSearchCondition($cond) : '';
    return compact('sel', 'auth', 'trace', 'cond', 'src');
}

$runtime1 = new AiIntentUnderstandingRuntime(new AiuGeminiUnderstandingClientStub(static function (AiuPromptRequest $req): array {
    return [
        'intent' => 'product_search',
        'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'theme' => ['蜜月'],
            'free_text' => $req->getCustomerUtterance(),
        ], ['峇里島']),
        'confidence' => 0.9,
        'clarification' => ['required' => false, 'reason' => ''],
    ];
}));
$c1 = runCase('BATS測試 我想10月去峇里島蜜月', $runtime1);
lf_assert(($c1['trace']['understanding_source'] ?? '') === 'gemini_aiu', 'case1 understanding_source');
lf_assert(!array_key_exists('legacy_understanding_used', $c1['trace'] ?? []), 'case1 legacy_understanding_used absent');
lf_assert($c1['auth']->getDestination() === ['峇里島'], 'case1 destination');
lf_assert(strpos((string)($c1['auth']->getDateFrom() ?? ''), '2026-10') === 0, 'case1 date');
lf_assert($c1['auth']->getDestination() !== ['BATS測試我想10月去峇里島'], 'case1 not legacy dest');

$runtime2 = new AiIntentUnderstandingRuntime(new AiuGeminiUnderstandingClientStub(static function (): array {
    return ['intent' => 'product_search', 'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities(['theme' => ['賞花'], 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'], ['歐洲']), 'confidence' => 0.9, 'clarification' => ['required' => false, 'reason' => '']];
}));
$c2 = runCase('BATS測試 請推薦歐洲賞花，10月', $runtime2);
lf_assert(($c2['trace']['understanding_source'] ?? '') === 'gemini_aiu', 'case2 understanding_source');
lf_assert($c2['src'] === '歐洲', 'case2 source destination only (B2 theme mapping deferred)');

$runtime3 = new AiIntentUnderstandingRuntime(new AiuGeminiUnderstandingClientStub(static function (): array {
    return ['intent' => 'product_search', 'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities(['theme' => ['鐵道','溫泉','賞楓'], 'date_from' => '2026-11-01', 'date_to' => '2026-11-30'], ['京都']), 'confidence' => 0.95, 'clarification' => ['required' => false, 'reason' => '']];
}));
$c3 = runCase('BATS測試 我想安排11月京都鐵道溫泉賞楓', $runtime3);
lf_assert(($c3['trace']['understanding_source'] ?? '') === 'gemini_aiu', 'case3 understanding_source');
lf_assert($c3['src'] === '京都', 'case3 source destination only (B2 theme mapping deferred)');

$runtimeFail = new AiIntentUnderstandingRuntime(new AiuGeminiUnderstandingClientStub(static function (): array {
    throw new RuntimeException('gemini_unavailable');
}));
$c4 = runCase('BATS測試 北海道 8月', $runtimeFail);
lf_assert(($c4['sel']['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_FAIL_CLOSED, 'case4 fail_closed');
lf_assert(($c4['sel']['failure_reason'] ?? '') === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_RUNTIME, 'case4 failure_reason');
lf_assert(count($c4['sel']) === 2, 'case4 only 2 fields');
lf_assert($c4['auth'] === null, 'case4 no authoritative intent');

$routerRuntime = new AiIntentUnderstandingRuntime(new AiuGeminiUnderstandingClientStub(static function (): array {
    return ['intent' => 'product_search', 'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities(['theme' => ['蜜月'], 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'], ['峇里島']), 'confidence' => 0.9, 'clarification' => ['required' => false, 'reason' => '']];
}));
$routerRes = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    ['sno' => $pilotSno, 'company_name' => 'Travel B', 'channel_id' => 'ch', 'tenant_key' => 'travel_b'],
    'BATS測試 我想10月去峇里島蜜月', 'trace-freeze', 'rt', 'https://api.line.me/v2/bot/message/reply', 'tok', 'ch', null,
    new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
        return ['ok' => true, 'http_status' => 200, 'body' => json_encode(['success'=>true,'pagination'=>['total'=>0],'items'=>[]]), 'transport_error' => null];
    }),
    $ref, static function (): array { return ['mock' => true]; }, null, null, $cid, null, null, null, 'U-freeze', null, null, null, $routerRuntime
);
lf_assert(($routerRes['phase_9c1']['understanding_source'] ?? '') === 'gemini_aiu', 'router understanding_source');
lf_assert(!array_key_exists('legacy_understanding_used', $routerRes['phase_9c1'] ?? []), 'router legacy_understanding_used absent');

if ($failures === 0) { echo "ALL PASS test_aiu_v2_legacy_freeze\n"; exit(0); }
fwrite(STDERR, "$failures FAILURE(S)\n"); exit(1);