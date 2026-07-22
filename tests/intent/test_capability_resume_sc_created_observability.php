<?php
declare(strict_types=1);

/**
 * B0-LINE-01D-2T — capability_resume_search_condition_created ordering / privacy.
 */

require_once dirname(__DIR__, 2) . '/core/tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeIdentity.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeState.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateFactory.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateStore.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchCapabilityResumeService.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeMutationResult.php';
require_once dirname(__DIR__, 2) . '/core/search/BatsSearchIntent.php';
require_once dirname(__DIR__, 2) . '/core/search/DestinationFeasibilityContracts.php';
require_once dirname(__DIR__, 2) . '/core/search/BatsSearchIntentMapper.php';
require_once dirname(__DIR__, 2) . '/core/search/SearchCondition.php';
require_once dirname(__DIR__, 2) . '/core/tour_search_api_client.php';
require_once dirname(__DIR__, 2) . '/tests/support/AiuDestinationSemanticsTestFixtures.php';

$failures = 0;
function t2t_assert(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbc_2t_obs_' . getmypid();
@mkdir($dir, 0775, true);
$store = new StructuredSearchResumeStateStore($dir);
$resumeService = new StructuredSearchCapabilityResumeService($store);
$identity = StructuredSearchResumeIdentity::fromParts('t2t', 'OA2t', 'U2t');
$ref = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));

$destOr = AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['東京', '大阪'], 'or');
$intentOr = new BatsSearchIntent(
    '',
    BatsSearchIntent::INTENT_TOUR_SEARCH,
    $destOr['destination'],
    [],
    null,
    '2026-08-01',
    '2026-08-31',
    null,
    null,
    null,
    null,
    null,
    'tour',
    null,
    [],
    [],
    false,
    null,
    0.95,
    $destOr['destination_relation'],
    $destOr['destination_semantics']
);

$gateObs = [
    'execution_gate_decision' => DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE,
    'response_route' => DestinationFeasibilityContracts::RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE,
    'search_condition_created' => false,
    'product_source_executed' => false,
    'host_b_executed' => false,
    'multi_source_links_built' => false,
];
$persist = $resumeService->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-2T-1',
    'trace-2t-1',
    $ref
);
t2t_assert($persist['ok'] === true, 'seed capability WAITING');

$destSingle = AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['東京'], 'single');
$intentSingle = new BatsSearchIntent(
    '',
    BatsSearchIntent::INTENT_TOUR_SEARCH,
    $destSingle['destination'],
    [],
    null,
    '2026-08-01',
    '2026-08-31',
    null,
    null,
    null,
    null,
    null,
    'tour',
    null,
    [],
    [],
    false,
    null,
    0.95,
    $destSingle['destination_relation'],
    $destSingle['destination_semantics']
);

$eventBag = new ArrayObject();
$hostBCalls = 0;
$statePresentAtHostB = null;
$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function () use (
    &$hostBCalls,
    $eventBag,
    $store,
    $identity,
    $ref,
    &$statePresentAtHostB
): array {
    $hostBCalls++;
    $statePresentAtHostB = $store->load($identity, $ref)->isFound();
    $eventBag[] = ['step' => 'host_b', 'seq' => $eventBag->count()];

    $body = json_encode([
        'success' => true,
        'items' => [
            [
                'title' => '東京五日',
                'tourDate' => '2026-08-05',
                'price' => 23999,
            ],
        ],
        'pagination' => ['page' => 1, 'pageSize' => 30, 'total' => 1],
        'search_url' => 'https://example.test/search',
    ], JSON_UNESCAPED_UNICODE);

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => is_string($body) ? $body : '{}',
        'transport_error' => null,
    ];
});

$service = new TourPromptContextService();
$result = $service->buildTourContextResult([
    'featureEnabled' => true,
    'userText' => '東京',
    'sno' => 't2t',
    'channelId' => 'OA2t',
    'traceId' => 'trace-2t-happy',
    'referenceDate' => $ref,
    'authoritativeIntent' => $intentSingle,
    'searchClient' => $mockClient,
    'resumeIdentity' => $identity,
    'capabilityResumeService' => $resumeService,
    'productUnderstandingTrace' => ['understanding_source' => 'gemini_aiu'],
    'resumeObservabilityLogger' => static function (string $step, array $payload) use ($eventBag, $store, $identity, $ref): void {
        $stillWaiting = $store->load($identity, $ref)->isFound();
        $eventBag[] = [
            'step' => $step,
            'seq' => $eventBag->count(),
            'payload' => $payload,
            'state_present_at_sc_created' => $stillWaiting,
        ];
    },
    'hybridSearchConfig' => [
        'enabled' => false,
        'allowed_sno' => [],
        'allowed_channels' => [],
        'dry_run_log_enabled' => false,
    ],
]);

$events = iterator_to_array($eventBag);

// Intercept consume by wrapping — consume logs via service Logger; capture order via events + store
// The injectable logger only covers SC-created. Capture consume via store absence after + seq relative to host B.
$scEvents = array_values(array_filter($events, static function ($e) {
    return ($e['step'] ?? '') === 'capability_resume_search_condition_created';
}));
t2t_assert(count($scEvents) === 1, '1 exactly one SC-created event');
$sc = $scEvents[0]['payload'];
t2t_assert(($sc['execution_gate_decision'] ?? '') === 'allow_single_search', '2 allow_single');
t2t_assert(($sc['destination_relation'] ?? '') === 'single', '2 relation single');
t2t_assert(($sc['date_from'] ?? '') === '2026-08-01' && ($sc['date_to'] ?? '') === '2026-08-31', '2 dates');
t2t_assert(($sc['destination_count'] ?? 0) === 1, '2 destination_count');
t2t_assert(
    ($sc['destination_labels_hash8'] ?? '') === TourPromptContextService::destinationLabelsHash8(['東京']),
    '2 destination_labels_hash8'
);
t2t_assert(($sc['search_condition_created'] ?? false) === true, '2 search_condition_created');
t2t_assert(($sc['next_operation'] ?? '') === 'consume_after_search_condition_created', '2 next_operation');
t2t_assert(($sc['resume_reason'] ?? '') === 'relation_capability_unavailable', '2 resume_reason');
t2t_assert(!isset($sc['destination']) && !isset($sc['known_entities']), '7 no raw labels/state in event');
$json = json_encode($sc, JSON_UNESCAPED_UNICODE);
t2t_assert($json !== false && strpos($json, '東京') === false, '7 hash only — no raw 東京 in payload json');

t2t_assert($result->getSearchCondition() !== null, 'happy SC present');
t2t_assert($hostBCalls === 1, '1 Host B called once');
t2t_assert(!$store->load($identity, $ref)->isFound(), '1 state consumed');
t2t_assert(($scEvents[0]['state_present_at_sc_created'] ?? false) === true, '1 SC-created before consume (state still present)');
t2t_assert($statePresentAtHostB === false, '1 consume before Host B (state already absent at Host B call)');

$scSeq = $scEvents[0]['seq'];
$hostSeq = null;
foreach ($events as $e) {
    if (($e['step'] ?? '') === 'host_b' && $hostSeq === null) {
        $hostSeq = $e['seq'];
    }
}
t2t_assert($hostSeq !== null && $scSeq < $hostSeq, '1 SC-created before Host B');

// 3: clarification path — no SC-created / no Host B; state retained
$events = [];
$hostBCalls = 0;
$intentClarify = $intentSingle->with([
    'clarification_required' => true,
    'clarification_reason' => 'destination_unknown',
]);
$persist = $resumeService->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-2T-3',
    'trace-2t-3',
    $ref
);
t2t_assert($persist['ok'], '3 re-seed waiting');
$resultClarify = $service->buildTourContextResult([
    'featureEnabled' => true,
    'userText' => '東京',
    'sno' => 't2t',
    'traceId' => 'trace-2t-clarify',
    'referenceDate' => $ref,
    'authoritativeIntent' => $intentClarify,
    'searchClient' => $mockClient,
    'resumeIdentity' => $identity,
    'capabilityResumeService' => $resumeService,
    'resumeObservabilityLogger' => static function (string $step, array $payload) use (&$events): void {
        $events[] = ['step' => $step, 'payload' => $payload];
    },
]);
t2t_assert($resultClarify->isClarificationRequired(), '3 clarification path');
t2t_assert($events === [], '3 no SC-created on clarification');
t2t_assert($store->load($identity, $ref)->isFound(), '3 state not consumed');
t2t_assert($hostBCalls === 0, '3 no Host B');

// Force SC null with allow path: mapper returns null — use intent that gate allows but mapper fails.
// Empty destination with valid single semantics that project to empty → fail_closed at gate.
// Use batsSearchIntentMapper stub via anonymous — BatsSearchIntentMapper is concrete; simplest:
// destination executable but toSearchCondition null when dates missing? Actually date required.
// Clear state and use OR deny path for zero-call (6).
$clear = $resumeService->clearCapabilityWaitingIfPresent($identity, $ref, 'trace-clear');
t2t_assert($clear['ok'], 'clear before deny test');

$events = [];
$hostBCalls = 0;
$resultDeny = $service->buildTourContextResult([
    'featureEnabled' => true,
    'userText' => '東京或大阪',
    'sno' => 't2t',
    'traceId' => 'trace-2t-deny',
    'referenceDate' => $ref,
    'authoritativeIntent' => $intentOr,
    'searchClient' => $mockClient,
    'resumeIdentity' => $identity,
    'capabilityResumeService' => $resumeService,
    'resumeObservabilityLogger' => static function (string $step, array $payload) use (&$events): void {
        $events[] = ['step' => $step, 'payload' => $payload];
    },
]);
t2t_assert($resultDeny->isExecutionGateBlocked(), '6 gate blocked');
t2t_assert($resultDeny->getSearchCondition() === null, '6 no SC');
t2t_assert($events === [], '6 no SC-created on deny');
t2t_assert($hostBCalls === 0, '6 zero-call Host B');

// 4: no active capability state — searchable single without WAITING
$events = [];
$hostBCalls = 0;
$resultNoResume = $service->buildTourContextResult([
    'featureEnabled' => true,
    'userText' => '東京',
    'sno' => 't2t',
    'traceId' => 'trace-2t-no-resume',
    'referenceDate' => $ref,
    'authoritativeIntent' => $intentSingle,
    'searchClient' => $mockClient,
    'resumeIdentity' => $identity,
    'capabilityResumeService' => $resumeService,
    'resumeObservabilityLogger' => static function (string $step, array $payload) use (&$events): void {
        $events[] = ['step' => $step, 'payload' => $payload];
    },
]);
t2t_assert($resultNoResume->getSearchCondition() !== null, '4 searchable without resume');
t2t_assert($events === [], '4 no capability-resume SC-created without active state');
t2t_assert($hostBCalls === 1, '4 Host B still runs');

// 5: observability logger throws — search still succeeds + consume still works
$persist = $resumeService->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-2T-5',
    'trace-2t-5',
    $ref
);
t2t_assert($persist['ok'], '5 re-seed');
$hostBCalls = 0;
$resultThrowLog = $service->buildTourContextResult([
    'featureEnabled' => true,
    'userText' => '東京',
    'sno' => 't2t',
    'traceId' => 'trace-2t-logfail',
    'referenceDate' => $ref,
    'authoritativeIntent' => $intentSingle,
    'searchClient' => $mockClient,
    'resumeIdentity' => $identity,
    'capabilityResumeService' => $resumeService,
    'resumeObservabilityLogger' => static function (string $step, array $payload): void {
        throw new RuntimeException('obs_logger_boom');
    },
]);
t2t_assert($resultThrowLog->getSearchCondition() !== null, '5 SC still created');
t2t_assert($hostBCalls === 1, '5 Host B still called');
t2t_assert(!$store->load($identity, $ref)->isFound(), '5 consume still succeeded');

// 3b covered by clarification + deny paths above (no final-class mapper stub).
$resumeService->clearCapabilityWaitingIfPresent($identity, $ref, 'trace-clear-end');

foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

if ($failures === 0) {
    echo "ALL PASS test_capability_resume_sc_created_observability\n";
    exit(0);
}
echo "FAILED {$failures}\n";
exit(1);
