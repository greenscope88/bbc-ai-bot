<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$intentDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';
$searchDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientInterface.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once $intentDir . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $searchDir . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsTestFixtures.php';

$failures = 0;

function dpo_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$reference = new DateTimeImmutable('2026-07-27', $tz);
$sno = '5f99b8d665e8444d';
$cid = $sno . ':line:Udateobs01';

$fixedClient = new class implements AiuGeminiUnderstandingClientInterface {
    /** @var array<string, mixed> */
    public array $payload = [];

    public function understand(AiuPromptRequest $request): array
    {
        unset($request);

        return $this->payload;
    }
};

$runtime = new AiIntentUnderstandingRuntime(
    $fixedClient,
    null,
    null,
    AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
);

// Case 1 — Exact Request Reference
$fixedClient->payload = [
    'intent' => 'Product Search',
    'entities' => array_merge(
        ['date_expression' => '10月'],
        AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['日本'])
    ),
    'confidence' => 0.9,
    'clarification' => ['required' => true, 'reason' => 'missing_travel_dates'],
];
$case1 = $runtime->understand('10月', [
    'tenant_sno' => $sno,
    'conversation_id' => $cid,
    'reference_date' => $reference,
    'now' => $reference,
]);
$refCtx = $case1->getDatePipelineReferenceContext();
dpo_assert($refCtx !== null, 'case1: reference context attached');
dpo_assert(($refCtx['reference_calendar_date'] ?? '') === '2026-07-27', 'case1: reference_calendar_date');
dpo_assert(($refCtx['reference_timezone'] ?? '') === 'Asia/Taipei', 'case1: reference_timezone');

$translator = new AiuProductIntentTranslator();
$translated1 = $translator->translate($case1);
$event1 = AiIntentUnderstandingRuntime::buildDatePipelineLogEvent('trace-case1', $case1, $translated1);
dpo_assert(($event1['reference_calendar_date'] ?? '') === '2026-07-27', 'case1: log reference_calendar_date');
dpo_assert(($event1['reference_timezone'] ?? '') === 'Asia/Taipei', 'case1: log reference_timezone');

// Case 2 — Raw Date Passthrough (2026)
$fixedClient->payload = [
    'intent' => 'Product Search',
    'entities' => array_merge(
        [
            'date_range' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'date_expression' => '10月',
            'search_keyword_tokens' => ['日本', '賞花'],
        ],
        AiuDestinationSemanticsTestFixtures::productSearchDestinationPatch(['日本'])
    ),
    'confidence' => 0.95,
    'clarification' => ['required' => false, 'reason' => ''],
];
$case2a = $runtime->understand('10月', [
    'tenant_sno' => $sno,
    'conversation_id' => $cid . '-2a',
    'reference_date' => $reference,
]);
$presence2a = $case2a->getDatePipelineRawPresence();
dpo_assert(($presence2a['raw_date_range_present'] ?? false) === true, 'case2a: raw_date_range_present');
dpo_assert(($presence2a['raw_date_from_present'] ?? false) === true, 'case2a: raw_date_from_present');
dpo_assert(($presence2a['raw_date_to_present'] ?? false) === true, 'case2a: raw_date_to_present');
dpo_assert(($presence2a['raw_date_from'] ?? '') === '2026-10-01', 'case2a: raw_date_from');
dpo_assert(($presence2a['raw_date_to'] ?? '') === '2026-10-31', 'case2a: raw_date_to');
dpo_assert(($case2a->getEntities()['date_from'] ?? '') === '2026-10-01', 'case2a: normalized date_from unchanged');
dpo_assert(($case2a->getEntities()['date_to'] ?? '') === '2026-10-31', 'case2a: normalized date_to unchanged');

// Case 2 — Raw Date Passthrough (2027)
$fixedClient->payload['entities']['date_range'] = ['from' => '2027-10-01', 'to' => '2027-10-31'];
$case2b = $runtime->understand('10月', [
    'tenant_sno' => $sno,
    'conversation_id' => $cid . '-2b',
    'reference_date' => $reference,
]);
$presence2b = $case2b->getDatePipelineRawPresence();
dpo_assert(($presence2b['raw_date_from'] ?? '') === '2027-10-01', 'case2b: raw_date_from');
dpo_assert(($presence2b['raw_date_to'] ?? '') === '2027-10-31', 'case2b: raw_date_to');
dpo_assert(($case2b->getEntities()['date_from'] ?? '') === '2027-10-01', 'case2b: normalized date_from unchanged');

// Case 3 — Absent / null / invalid
$absent = AiIntentUnderstandingRuntime::observeRawDateFieldPresence([
    'intent' => 'Product Search',
    'entities' => ['destination' => ['日本']],
]);
dpo_assert(($absent['raw_date_range_present'] ?? true) === false, 'case3 absent: raw_date_range_present');
dpo_assert(($absent['raw_date_from_present'] ?? true) === false, 'case3 absent: raw_date_from_present');
dpo_assert(array_key_exists('raw_date_from', $absent) && $absent['raw_date_from'] === null, 'case3 absent: raw_date_from null');

$nullRange = AiIntentUnderstandingRuntime::observeRawDateFieldPresence([
    'intent' => 'Product Search',
    'entities' => [
        'destination' => ['日本'],
        'date_range' => null,
        'date_from' => null,
        'date_to' => null,
    ],
]);
dpo_assert(($nullRange['raw_date_range_present'] ?? false) === true, 'case3 null: raw_date_range_present');
dpo_assert(($nullRange['raw_has_date_range'] ?? true) === false, 'case3 null: raw_has_date_range false');
dpo_assert(($nullRange['raw_date_from_present'] ?? false) === true, 'case3 null: raw_date_from_present');
dpo_assert(array_key_exists('raw_date_from', $nullRange) && $nullRange['raw_date_from'] === null, 'case3 null: raw_date_from value null');

$invalid = AiIntentUnderstandingRuntime::observeRawDateFieldPresence([
    'intent' => 'Product Search',
    'entities' => [
        'destination' => ['日本'],
        'date_range' => ['from' => ['nested' => 'bad'], 'to' => '2026-10-31'],
    ],
]);
dpo_assert(($invalid['raw_date_from_present'] ?? false) === true, 'case3 invalid: raw_date_from_present');
dpo_assert(($invalid['raw_has_date_from'] ?? true) === false, 'case3 invalid: raw_has_date_from false');
dpo_assert(array_key_exists('raw_date_from', $invalid) && $invalid['raw_date_from'] === null, 'case3 invalid: unsafe from not logged');
dpo_assert(($invalid['raw_date_to'] ?? '') === '2026-10-31', 'case3 invalid: safe to still logged');

// Case 4 — Log Safety
$translated2b = $translator->translate($case2b);
$eventSafe = AiIntentUnderstandingRuntime::buildDatePipelineLogEvent('trace-safe', $case2b, $translated2b);
$encoded = json_encode($eventSafe, JSON_UNESCAPED_UNICODE);
dpo_assert(is_string($encoded), 'case4: event encodes');
dpo_assert(strpos($encoded, '10月') === false, 'case4: no utterance in event');
dpo_assert(strpos($encoded, 'customer') === false, 'case4: no customer content');
dpo_assert(strpos($encoded, 'destination') === false, 'case4: no destination entities');
dpo_assert(strpos($encoded, 'authorization') === false, 'case4: no secrets');
dpo_assert(strpos($encoded, 'replyToken') === false, 'case4: no reply token');
dpo_assert(strpos($encoded, 'GEMINI') === false, 'case4: no prompt markers');
$allowedKeys = [
    'trace_id', 'intent_category', 'reference_calendar_date', 'reference_timezone',
    'raw_has_date_range', 'raw_has_date_from', 'raw_has_date_to', 'raw_has_date_expression',
    'raw_date_range_present', 'raw_date_from_present', 'raw_date_to_present',
    'raw_date_from', 'raw_date_to',
    'normalized_date_from', 'normalized_date_to', 'normalized_has_date_expression',
    'clarification_required', 'clarification_reason',
    'translated_date_from', 'translated_date_to',
];
dpo_assert(array_keys($eventSafe) === $allowedKeys, 'case4: event key allowlist');

if ($failures === 0) {
    echo "ALL PASS test_aiu_date_pipeline_observation\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S)\n");
exit(1);
