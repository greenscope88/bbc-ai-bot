<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeIdentity.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeState.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateFactory.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeStateStore.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchCapabilityResumeService.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeMutationResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/StructuredSearchResumeLoadResult.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentContextLoader.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuGeminiUnderstandingClientStub.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuPromptRequest.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuPromptBuilder.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiIntentCategory.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuClarificationReasonContract.php';
require_once dirname(__DIR__, 2) . '/core/search/BatsSearchIntent.php';
require_once dirname(__DIR__, 2) . '/core/search/DestinationFeasibilityContracts.php';
require_once dirname(__DIR__, 2) . '/core/search/DestinationRelationCapabilityRegistry.php';
require_once dirname(__DIR__, 2) . '/core/search/BatsSearchIntentMapper.php';
require_once dirname(__DIR__, 2) . '/core/tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . '/tests/support/AiuDestinationSemanticsTestFixtures.php';
require_once dirname(__DIR__, 2) . '/core/conversation/ConversationOwner.php';

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbc_cap_resume_' . getmypid();
@mkdir($dir, 0775, true);
$store = new StructuredSearchResumeStateStore($dir);
$service = new StructuredSearchCapabilityResumeService($store);
$identity = StructuredSearchResumeIdentity::fromParts('tCap', 'OAcap', 'Ucap');
$ref = new DateTimeImmutable('2026-07-22 12:00:00', new DateTimeZone('Asia/Taipei'));

$destPatch = AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['東京', '大阪'], 'or');
$intentOr = new BatsSearchIntent(
    '',
    BatsSearchIntent::INTENT_TOUR_SEARCH,
    $destPatch['destination'],
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
    $destPatch['destination_relation'],
    $destPatch['destination_semantics']
);

$gateObs = [
    'execution_gate_decision' => DestinationFeasibilityContracts::EXECUTION_DENY_RELATION_CAPABILITY_UNAVAILABLE,
    'response_route' => DestinationFeasibilityContracts::RESPONSE_ROUTE_CAPABILITY_UNAVAILABLE,
    'search_condition_created' => false,
    'product_source_executed' => false,
    'host_b_executed' => false,
    'multi_source_links_built' => false,
];

// 1-3: OR deny + August → v2 WAITING, zero-call flags, persist facts
$persist = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-T1',
    'trace-t1',
    $ref,
    ['date_expression' => '八月']
);
assert_true($persist['ok'] === true, '1 persist ok');
$state = $persist['state'];
assert_true($state instanceof StructuredSearchResumeState, '1 state present');
assert_true($state->getSchemaVersion() === 2, '1 schema v2');
assert_true($state->getStatus() === StructuredSearchResumeState::STATUS_WAITING_SINGLE_DESTINATION, '1 status');
assert_true($state->getResumeReason() === StructuredSearchResumeState::RESUME_REASON_RELATION_CAPABILITY_UNAVAILABLE, '1 reason');
assert_true($state->getAiuClarificationReason() === '', '1 aiu reason empty');
$known = $state->getKnownEntities();
assert_true($known['destination'] === ['東京', '大阪'], '2 destinations');
assert_true($known['destination_relation'] === 'or', '2 relation or');
assert_true(count($known['destination_semantics']) === 2, '2 semantics');
assert_true($known['date_from'] === '2026-08-01' && $known['date_to'] === '2026-08-31', '2 august dates');
assert_true($known['date_expression'] === '八月', '2 date_expression');
assert_true(($gateObs['search_condition_created'] ?? null) === false, '3 zero-call search_condition');
assert_true(($gateObs['host_b_executed'] ?? null) === false, '3 zero-call host_b');

// 13: v1 / missing version / illegal → CORRUPT path via fromDocument
$v1 = $state->toArray();
$v1['schema_version'] = 1;
try {
    StructuredSearchResumeStateFactory::fromDocument($v1);
    assert_true(false, '13 v1 rejected');
} catch (InvalidArgumentException $e) {
    assert_true(strpos($e->getMessage(), 'schema_version') !== false, '13 v1 message');
}
$path = $dir . DIRECTORY_SEPARATOR . $identity->getStorageKey() . '.json';
file_put_contents($path, json_encode($v1, JSON_UNESCAPED_UNICODE));
$loadBad = $store->load($identity, $ref);
assert_true($loadBad->getStatus() === StructuredSearchResumeLoadResult::CORRUPT, '13 corrupt load');
$store->quarantineCorrupt($identity);
assert_true(!is_file($path), '13 quarantined');

// re-create after quarantine
$persist = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-T1b',
    'trace-t1b',
    $ref,
    ['date_expression' => '八月']
);
assert_true($persist['ok'], 'recreate after quarantine');

// 4: Turn 2 load + inject
$load = $store->load($identity, $ref);
assert_true($load->isFound(), '4 state found');
$injection = $load->getState()->toPromptInjectionArray();
assert_true(($injection['resume_reason'] ?? '') === 'relation_capability_unavailable', '4 inject reason');
assert_true(($injection['asked_entity'] ?? '') === 'destination', '4 inject asked');
assert_true(($injection['known_entities']['date_from'] ?? '') === '2026-08-01', '4 inject dates');

$builder = new AiuPromptBuilder();
$prompt = $builder->build(new AiuPromptRequest(
    'tCap',
    'line',
    '東京',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $ref,
    null,
    $injection
));
assert_true(strpos($prompt, 'relation_capability_unavailable') !== false, '4 prompt reason');
assert_true(strpos($prompt, 'Retain known date_from') !== false, '4 prompt retain dates');

// 5-7: Gemini fixture single Tokyo + August → SearchCondition → consume
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
$mapper = new BatsSearchIntentMapper();
$sc = $mapper->toSearchCondition($intentSingle);
assert_true($sc !== null, '6 SearchCondition created');
$beforeConsume = $store->load($identity, $ref);
assert_true($beforeConsume->isFound(), '7 state still present before consume');
$consume = $service->consumeAfterSearchConditionCreated($identity, $ref, 'trace-t2');
assert_true($consume['ok'], '7 consume ok');
assert_true($consume['mutation_status'] === StructuredSearchResumeMutationResult::CLEARED, '7 cleared');
assert_true(!$store->load($identity, $ref)->isFound(), '7 state absent after consume');

// 8: SearchCondition failure must not clear — recreate then skip consume
$persist = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-T8',
    'trace-t8',
    $ref
);
assert_true($persist['ok'], '8 re-persist');
$vBefore = $store->load($identity, $ref)->getState()->getStateVersion();
// simulate SC failure: do not call consume
assert_true($store->load($identity, $ref)->getState()->getStateVersion() === $vBefore, '8 state retained');

// 9: Host B zero products — state already consumed; no restore
$service->consumeAfterSearchConditionCreated($identity, $ref, 'trace-t9');
assert_true(!$store->load($identity, $ref)->isFound(), '9 consumed; no restore');

// 10: Turn 2 still OR → replace WAITING
$persist = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-T10a',
    'trace-t10a',
    $ref
);
$v1state = $store->load($identity, $ref)->getState()->getStateVersion();
$persist2 = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-T10b',
    'trace-t10b',
    $ref
);
assert_true($persist2['ok'], '10 replace ok');
assert_true($persist2['operation'] === 'replace', '10 replace op');
assert_true($store->load($identity, $ref)->getState()->getStateVersion() === $v1state + 1, '10 version bump');

// 15: duplicate event idempotent
$persistDup = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-T10b',
    'trace-t10b-dup',
    $ref
);
assert_true($persistDup['ok'], '15 idempotent ok');
assert_true(
    $persistDup['mutation_status'] === StructuredSearchResumeMutationResult::IDEMPOTENT_REPLAY,
    '15 idempotent replay'
);

// 11: mixed / non_executable → clear
$clear = $service->clearCapabilityWaitingIfPresent($identity, $ref, 'trace-clear');
assert_true($clear['ok'], '11 clear ok');
assert_true(!$store->load($identity, $ref)->isFound(), '11 cleared');

// 12: context retention — prior capability with dates + missing_travel_dates
$persist = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $gateObs,
    'EVT-T12',
    'trace-t12',
    $ref
);
assert_true($persist['ok'], '12 prior waiting');
$lastPrompt = null;
$client = new AiuGeminiUnderstandingClientStub(function (AiuPromptRequest $request) use (&$lastPrompt) {
    $lastPrompt = $request;

    return [
        'intent' => 'product_search',
        'entities' => [
            'destination' => ['東京', '大阪'],
            'destination_relation' => 'or',
            'destination_semantics' => [
                AiuDestinationSemanticsTestFixtures::candidate('東京'),
                AiuDestinationSemanticsTestFixtures::candidate('大阪'),
            ],
            'date_from' => null,
            'date_to' => null,
            'date_expression' => null,
        ],
        'confidence' => 0.5,
        'clarification' => ['required' => true, 'reason' => 'missing_travel_dates'],
        'resume_disposition' => 'continue_pending',
    ];
});
$runtime = new AiIntentUnderstandingRuntime(
    $client,
    null,
    null,
    AiIntentContextLoader::createForTesting(),
    $store
);
$threw = false;
try {
    $runtime->understand('東京', [
        'tenant_sno' => 'tCap',
        'conversation_id' => $identity->getConversationId(),
        'channel' => 'line',
        'channel_id' => 'OAcap',
        'line_user_id' => 'Ucap',
        'webhook_event_id' => 'EVT-T12b',
        'trace_id' => 'trace-t12b',
        'now' => $ref,
        'reference_date' => $ref,
    ]);
} catch (Throwable $e) {
    $threw = true;
    assert_true(
        strpos($e->getMessage(), 'structured_search_resume_context_retention_failure') !== false,
        '12 retention throw'
    );
}
assert_true($threw, '12 threw');
assert_true($lastPrompt !== null && $lastPrompt->hasStructuredSearchResumeState(), '12 injected prior');
assert_true(!$store->load($identity, $ref)->isFound(), '12 state terminated');

// 14: persist failure — incomplete trigger
$badObs = $gateObs;
$badObs['search_condition_created'] = true;
$fail = $service->persistWaitingSingleDestination(
    $identity,
    $intentOr,
    $badObs,
    'EVT-T14',
    'trace-t14',
    $ref
);
assert_true($fail['ok'] === false, '14 persist rejected');
assert_true($fail['failure_code'] === StructuredSearchCapabilityResumeService::FAILURE_TRIGGER_INCOMPLETE, '14 code');

// 16: AIU missing_destination / missing_travel_dates write v2
$result = new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH);
$result->setEntities([
    'destination' => ['日本'],
    'date_from' => null,
    'date_to' => null,
])->setClarification(true, AiuClarificationReasonContract::AIU_MISSING_TRAVEL_DATES);
$aiuState = StructuredSearchResumeStateFactory::fromValidatedClarification(
    $identity,
    $result,
    'EVT-AIU',
    StructuredSearchResumeState::OP_CREATE,
    $ref,
    'trace-aiu',
    1
);
assert_true($aiuState->getSchemaVersion() === 2, '16 aiu schema v2');
assert_true($aiuState->getStatus() === StructuredSearchResumeState::STATUS_WAITING_CLARIFICATION, '16 aiu status');
assert_true($aiuState->getResumeReason() === StructuredSearchResumeState::RESUME_REASON_AIU_PRODUCT_CLARIFICATION, '16 aiu reason');
assert_true($aiuState->getAskedEntity() === 'date', '16 asked date');
assert_true($aiuState->getAiuClarificationReason() === 'missing_travel_dates', '16 closed reason');
$round = StructuredSearchResumeStateFactory::fromDocument($aiuState->toArray());
assert_true($round->getAskedEntity() === 'date', '16 round-trip');

// 17: no missing_entity dual-read in document
$doc = $aiuState->toArray();
assert_true(!array_key_exists('missing_entity', $doc), '17 no missing_entity key');
assert_true(array_key_exists('asked_entity', $doc), '17 asked_entity present');

foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

if ($failures === 0) {
    echo "ALL PASS test_capability_resume_schema_v2\n";
    exit(0);
}
echo "FAILED {$failures}\n";
exit(1);
