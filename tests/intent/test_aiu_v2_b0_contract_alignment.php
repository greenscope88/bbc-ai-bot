<?php
declare(strict_types=1);

/**
 * B0 Contract Alignment — Gemini Output + Normalize contract tests.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/core/intent/AiuPromptBuilder.php';
require_once $root . '/core/intent/AiuPromptRequest.php';
require_once $root . '/core/intent/AiuGeminiUnderstandingClient.php';
require_once $root . '/core/intent/AiuSemanticJsonNormalizer.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once $root . '/core/intent/AiIntentUnderstandingResult.php';
require_once $root . '/core/intent/AiIntentCategory.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntimeSelector.php';
require_once $root . '/core/conversation/ConversationOwner.php';

function b0_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "PASS: {$msg}\n";
}

function b0_assert_contract_failure(callable $fn, string $label): void
{
    try {
        $fn();
        fwrite(STDERR, "FAIL: {$label} must throw RuntimeException\n");
        exit(1);
    } catch (\RuntimeException $e) {
        b0_assert(
            strpos($e->getMessage(), 'Gemini output contract invalid') !== false,
            "{$label} throws contract validation failure"
        );
    }
}

// --- B0-6 Prompt + date reference contract ---
$tz = new DateTimeZone('Asia/Taipei');
$refJul14 = new DateTimeImmutable('2026-07-14', $tz);
$builder = new AiuPromptBuilder();
$req = new AiuPromptRequest(
    'sno',
    'line',
    '京都自由行10月',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $refJul14,
    null
);
$prompt = $builder->build($req);
b0_assert(strpos($prompt, '"entities"') !== false, 'B0-6 prompt mentions entities');
b0_assert(strpos($prompt, 'semantic_notes') === false || strpos($prompt, 'Do NOT output semantic_notes') !== false, 'B0-6 prompt forbids semantic_notes');
b0_assert(strpos($prompt, 'dispatch_plan') !== false && strpos($prompt, 'Do NOT output dispatch_plan') !== false, 'B0-6 prompt forbids dispatch_plan');
b0_assert(strpos($prompt, 'reference_calendar_date: 2026-07-14') !== false, 'Prompt injects reference_calendar_date');
b0_assert(strpos($prompt, 'reference_timezone: Asia/Taipei') !== false, 'Prompt injects reference_timezone');
b0_assert(strpos($prompt, 'B-03c Date Resolution Rules') !== false, 'Prompt includes date resolution rules');
b0_assert(strpos($prompt, '2026-08-01') !== false && strpos($prompt, '2026-08-31') !== false, 'Prompt documents future-month example 8月');
b0_assert(strpos($prompt, '2026-07-14') !== false && strpos($prompt, '2026-07-31') !== false, 'Prompt documents current-month example 7月');
b0_assert(strpos($prompt, '2027-01-01') !== false && strpos($prompt, '2027-01-31') !== false, 'Prompt documents cross-year example 1月');
b0_assert(strpos($prompt, '2027-03-01') !== false && strpos($prompt, '2027-03-31') !== false, 'Prompt documents explicit year/month example');
b0_assert(strpos($prompt, '2026-09-12') !== false, 'Prompt documents 近期 +60 days example');
b0_assert(strpos($prompt, 'Preserve date_expression') !== false || strpos($prompt, 'preserve the original phrasing in date_expression') !== false, 'Prompt requires preserving date_expression');
b0_assert(strpos($prompt, 'Set date_range to null') !== false, 'Prompt allows unresolvable date_range null');
b0_assert(strpos($prompt, 'missing_destination') !== false, 'Prompt closed enum includes missing_destination');
b0_assert(strpos($prompt, 'missing_travel_dates') !== false, 'Prompt closed enum includes missing_travel_dates');
b0_assert(strpos($prompt, 'Destination First') !== false, 'Prompt enforces Destination First');
b0_assert(strpos($prompt, 'e.g. missing_travel_dates, intent_ambiguous') === false, 'Prompt no freeform e.g. reason examples');
b0_assert(strpos($prompt, 'critical slots missing') === false, 'Prompt no freeform critical slots prose');

// --- B0-LINE-01C-10B: general NL date understanding (Prompt guides; Gemini resolves) ---
b0_assert(strpos($prompt, 'General natural-language dates') !== false, '10B prompt includes general NL date section');
b0_assert(
    strpos($prompt, 'native language, calendar, and date reasoning') !== false,
    '10B prompt assigns native reasoning to Gemini'
);
b0_assert(
    strpos($prompt, 'MUST output date_range.from and date_range.to') !== false,
    '10B prompt requires complete date_range when reliably resolvable'
);
b0_assert(
    strpos($prompt, 'non-exhaustive') !== false && strpos($prompt, 'NOT an allowlist') !== false,
    '10B prompt marks examples as non-exhaustive not allowlist'
);
b0_assert(
    strpos($prompt, 'NOT a mapping table') !== false,
    '10B prompt forbids mapping table semantics'
);
b0_assert(strpos($prompt, '農曆過年') !== false, '10B prompt includes illustrative 農曆過年 example');
b0_assert(
    preg_match('/農曆過年\s*→\s*\d{4}-\d{2}-\d{2}/u', $prompt) !== 1,
    '10B prompt has no hard-coded 農曆過年 date mapping'
);
b0_assert(
    strpos($prompt, 'Do NOT set clarification.required=true with missing_travel_dates solely') !== false
    || strpos($prompt, 'Do NOT set clarification.required=true with missing_travel_dates') !== false,
    '10B prompt discourages premature missing_travel_dates'
);

// --- B0-LINE-01C-10B-1: event-centered travel departure window ---
b0_assert(
    stripos($prompt, 'Event-Centered Travel Departure Window Policy') !== false,
    '10B-1 prompt includes event-centered travel departure window policy'
);
b0_assert(
    stripos($prompt, 'exactly three calendar days') !== false
    && (stripos($prompt, 'through event_anchor_date') !== false || stripos($prompt, 'through the event date') !== false),
    '10B-1 prompt defines three calendar days before event through event date'
);
b0_assert(
    stripos($prompt, 'Customer explicit date instruction') !== false
    && stripos($prompt, 'Conversation Context') !== false,
    '10B-1 prompt states customer explicit date and conversation context precedence'
);
b0_assert(
    stripos($prompt, 'Customer explicit date instruction > Conversation Context') !== false,
    '10B-1 prompt orders explicit instruction over context over default window'
);
b0_assert(
    stripos($prompt, 'Conversation Context > this default') !== false,
    '10B-1 conversation context has priority over default event window'
);
b0_assert(
    stripos($prompt, 'You determine event meaning') !== false
    || stripos($prompt, 'event calendar date') !== false,
    '10B-1 prompt assigns event date understanding to Gemini'
);
b0_assert(
    stripos($prompt, 'Output complete date_range.from and date_range.to') !== false,
    '10B-1 prompt requires complete date_range endpoints'
);
b0_assert(
    stripos($prompt, 'preserve date_expression') !== false,
    '10B-1 prompt preserves date_expression'
);
b0_assert(
    stripos($prompt, 'Illustrative behaviors only') !== false && stripos($prompt, 'non-exhaustive') !== false,
    '10B-1 examples are illustrative and non-exhaustive'
);
b0_assert(
    stripos($prompt, 'NOT an allowlist') !== false && stripos($prompt, 'NOT a closed-set') !== false,
    '10B-1 examples are not allowlist or closed-set'
);
b0_assert(
    stripos($prompt, 'NOT a mapping table') !== false,
    '10B-1 forbids mapping table semantics for event window'
);
b0_assert(
    stripos($prompt, 'missing_travel_dates') !== false
    && (stripos($prompt, 'unreliable after reference') !== false || stripos($prompt, 'Cannot reliably determine') !== false),
    '10B-1 clarification only when event date or range unreliable'
);
b0_assert(
    preg_match('/跨年\s*→\s*2026-12-31/u', $prompt) !== 1
    && preg_match('/跨年\s*→\s*12\/31/u', $prompt) !== 1,
    '10B-1 prompt has no fixed 跨年 to 12/31 mapping row'
);
b0_assert(
    preg_match('/父親節\s*→\s*2026-08-08/u', $prompt) !== 1
    && preg_match('/父親節\s*→\s*08\/08/u', $prompt) !== 1,
    '10B-1 prompt has no fixed 父親節 to 08/08 mapping row'
);
b0_assert(
    strpos(file_get_contents($root . '/core/intent/AiuDateEntityResolver.php'), '跨年') === false
    && strpos(file_get_contents($root . '/core/intent/AiuDateEntityResolver.php'), '父親節') === false,
    '10B-1 runtime resolver has no event keyword parsing'
);

// --- B0-LINE-01C-10B-2: no static event-date illustration in Production Prompt ---
b0_assert(
    stripos($prompt, 'minus exactly three calendar days') !== false
    && stripos($prompt, 'date_range.to = event_anchor_date') !== false,
    '10B-2 prompt keeps event-minus-3 through event-date general formula'
);
b0_assert(strpos($prompt, '2026-07-20') === false, '10B-2 prompt has no static reference 2026-07-20');
b0_assert(strpos($prompt, '2026-12-28') === false, '10B-2 prompt has no static 2026-12-28');
b0_assert(strpos($prompt, '2026-12-31') === false, '10B-2 prompt has no static 2026-12-31');
b0_assert(
    stripos($prompt, 'Policy illustration only') === false
    && strpos($prompt, 'date_range.from 2026-12-28') === false,
    '10B-2 prompt has no fixed 跨年 event-to-date range illustration'
);
b0_assert(
    stripos($prompt, 'Customer explicit date instruction > Conversation Context') !== false,
    '10B-2 precedence chain preserved'
);
b0_assert(
    stripos($prompt, 'Illustrative behaviors only') !== false && stripos($prompt, 'NOT a mapping table') !== false,
    '10B-2 illustrative non-exhaustive not mapping table preserved'
);

// --- B0-LINE-01C-10B-3: event window arithmetic boundary ---
b0_assert(
    stripos($prompt, 'minus exactly three calendar days') !== false,
    '10B-3 prompt requires minus exactly three calendar days'
);
b0_assert(
    stripos($prompt, 'exactly four calendar dates') !== false,
    '10B-3 prompt requires exactly four calendar dates inclusive'
);
b0_assert(
    stripos($prompt, 'date_range.from + exactly 3 calendar days must equal date_range.to') !== false,
    '10B-3 prompt requires from + 3 calendar days = to self-check'
);
b0_assert(
    stripos($prompt, 'transition events') !== false
    && stripos($prompt, 'primary celebration begins') !== false,
    '10B-3 prompt states transition-event anchor principle'
);
b0_assert(
    stripos($prompt, 'Do NOT treat the event_anchor_date as one of the three days before') !== false,
    '10B-3 prompt forbids counting anchor as one of the three prior days'
);
b0_assert(
    stripos($prompt, 'Customer explicit date instruction > Conversation Context') !== false
    && stripos($prompt, 'Illustrative behaviors only') !== false
    && stripos($prompt, 'non-exhaustive') !== false,
    '10B-3 preserves explicit-date/context priority and non-exhaustive contract'
);
b0_assert(
    preg_match('/跨年\s*[→=].*\d{4}-\d{2}-\d{2}/u', $prompt) !== 1
    && preg_match('/父親節\s*[→=].*\d{4}-\d{2}-\d{2}/u', $prompt) !== 1
    && strpos($prompt, '2026-12-28') === false
    && strpos($prompt, '2026-08-05') === false,
    '10B-3 prompt has no fixed event YYYY-MM-DD mapping'
);

// Cross-year reference injection (Dec 20)
$refDec20 = new DateTimeImmutable('2026-12-20', $tz);
$reqDec = new AiuPromptRequest('sno', 'line', '1月', [], ConversationOwner::AI, 'active', null, $refDec20, null);
$promptDec = $builder->build($reqDec);
b0_assert(strpos($promptDec, 'reference_calendar_date: 2026-12-20') !== false, 'Prompt injects Dec reference_calendar_date');
b0_assert(strpos($promptDec, 'reference_timezone: Asia/Taipei') !== false, 'Prompt keeps Asia/Taipei on Dec reference');

// --- B0-1 / B0-2 Client shape ---
$client = new AiuGeminiUnderstandingClient(null, static function () {
    return [
        'ok' => true,
        'text' => json_encode([
            'intent' => 'product_search',
            'entities' => [
                'destination' => ['京都', '大阪'],
                'date_range' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
                'date_expression' => '10月',
                'product_type' => '自由行',
            ],
            'confidence' => 0.9,
            'clarification' => ['required' => false, 'reason' => ''],
            'semantic_notes' => 'should be dropped',
            'dispatch_plan' => 'product',
            'execution_hint' => 'product_search',
        ], JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});
$shaped = $client->understand($req);
b0_assert(isset($shaped['entities']) && is_array($shaped['entities']), 'B0-1 client outputs entities');
b0_assert(!array_key_exists('entity', $shaped), 'B0-1 client does not output entity key');
b0_assert(!array_key_exists('semantic_notes', $shaped), 'B0-2 client drops semantic_notes');
b0_assert(!array_key_exists('dispatch_plan', $shaped), 'B0-3 client does not pass dispatch_plan');
b0_assert(!array_key_exists('execution_hint', $shaped), 'B0-3 client does not pass execution_hint');

// FR-1: Legacy entity input → contract validation failure (no silent empty entities)
$clientLegacy = new AiuGeminiUnderstandingClient(null, static function () {
    return [
        'ok' => true,
        'text' => json_encode([
            'intent' => 'Product Search',
            'entity' => ['destination' => '東京', 'date_from' => '2026-08-01'],
            'confidence' => 0.8,
            'clarification' => ['required' => false, 'reason' => ''],
        ], JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});
b0_assert_contract_failure(
    static fn () => $clientLegacy->understand($req),
    'FR-1 legacy entity only'
);

// FR-1: Missing entities → contract validation failure
$clientMissingEntities = new AiuGeminiUnderstandingClient(null, static function () {
    return [
        'ok' => true,
        'text' => json_encode([
            'intent' => 'product_search',
            'confidence' => 0.8,
            'clarification' => ['required' => false, 'reason' => ''],
        ], JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});
b0_assert_contract_failure(
    static fn () => $clientMissingEntities->understand($req),
    'FR-1 missing entities'
);

// FR-1: Wrong-type entities → contract validation failure
$clientWrongType = new AiuGeminiUnderstandingClient(null, static function () {
    return [
        'ok' => true,
        'text' => json_encode([
            'intent' => 'product_search',
            'entities' => 'not-an-array',
            'confidence' => 0.8,
            'clarification' => ['required' => false, 'reason' => ''],
        ], JSON_UNESCAPED_UNICODE),
        'error' => null,
    ];
});
b0_assert_contract_failure(
    static fn () => $clientWrongType->understand($req),
    'FR-1 wrong-type entities'
);

// FR-1: Invalid contract stops runtime — selector returns Formal Fail-closed Result
$runtimeInvalid = new AiIntentUnderstandingRuntime($clientLegacy);
b0_assert_contract_failure(
    static fn () => $runtimeInvalid->understand('東京', ['conversation_id' => 'c-fr1', 'tenant_sno' => '5f99b8d665e8444d']),
    'FR-1 runtime stops on invalid contract'
);
$flagOn = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => ['5f99b8d665e8444d'],
];
$selectorRes = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => '5f99b8d665e8444d',
    'conversation_id' => 'c-fr1',
    'message' => '東京',
    'config' => $flagOn,
], $runtimeInvalid);
b0_assert(
    ($selectorRes['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_FAIL_CLOSED,
    'FR-1 selector fail_closed on contract failure'
);
b0_assert(
    ($selectorRes['failure_reason'] ?? '') === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_RUNTIME,
    'FR-1 selector failure_reason is AIU_RUNTIME_FAILURE'
);
b0_assert(count($selectorRes) === 2, 'FR-1 selector only 2 fields');
b0_assert(!array_key_exists('fallback_reason', $selectorRes), 'FR-1 selector no fallback_reason');
b0_assert(!array_key_exists('intent_type', $selectorRes), 'FR-1 selector no intent_type');
b0_assert(!array_key_exists('legacy_intent_type', $selectorRes), 'FR-1 selector no legacy_intent_type');

// --- B0-4 / B0-5 Normalize ---
$normalizer = new AiuSemanticJsonNormalizer();
$norm = $normalizer->normalize($shaped, '想安排京都大阪自由行，10月出發');
b0_assert(isset($norm['entities']), 'Normalize returns entities');
b0_assert(is_array($norm['entities']['destination']), 'B0-4 destination is array');
b0_assert($norm['entities']['destination'] === ['京都', '大阪'], 'B0-4 destination array preserved order');
b0_assert(!array_key_exists('travel_type', $norm['entities']), 'Frozen: no travel_type in entities');
b0_assert(!array_key_exists('human_service_request', $norm['entities']), 'Frozen: no human_service_request in entities');
b0_assert(($norm['entities']['date_from'] ?? null) === '2026-10-01', 'B0-5 date_from mapped from date_range');
b0_assert(($norm['entities']['date_to'] ?? null) === '2026-10-31', 'B0-5 date_to mapped from date_range');
b0_assert(!array_key_exists('dispatch_plan', $norm), 'B0-3 normalize has no dispatch_plan');
b0_assert(!array_key_exists('execution_hint', $norm), 'B0-3 normalize has no execution_hint');
b0_assert(!array_key_exists('semantic_notes', $norm), 'B0-2 normalize has no semantic_notes');

// Fuzzy date: expression only → date_range null, no invent
$fuzzy = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'destination' => ['日本'],
        'date_range' => null,
        'date_expression' => '有空再去',
    ],
    'confidence' => 0.6,
    'clarification' => ['required' => true, 'reason' => 'missing_travel_dates'],
], '有空再去日本');
b0_assert(($fuzzy['entities']['date_from'] ?? null) === null, 'B0-5 fuzzy date_from null');
b0_assert(($fuzzy['entities']['date_to'] ?? null) === null, 'B0-5 fuzzy date_to null');
b0_assert(($fuzzy['entities']['date_expression'] ?? null) === '有空再去', 'B0-5 keeps date_expression');
b0_assert(($fuzzy['clarification_reason'] ?? '') === 'missing_travel_dates', 'B0-5 preserves closed missing_travel_dates');

// Closed reason: invalid freeform rejected (no repair)
try {
    $normalizer->normalize([
        'intent' => 'product_search',
        'entities' => ['destination' => []],
        'confidence' => 0.5,
        'clarification' => ['required' => true, 'reason' => 'critical_slots_missing'],
    ], '想找行程');
    b0_assert(false, 'closed reason: critical_slots_missing must throw');
} catch (\InvalidArgumentException $e) {
    b0_assert(
        $e->getMessage() === 'unsupported_aiu_clarification_reason',
        'closed reason: critical_slots_missing → unsupported_aiu_clarification_reason'
    );
}

$normMissingDest = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'destination' => [],
        'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
    ],
    'confidence' => 0.8,
    'clarification' => ['required' => true, 'reason' => 'missing_destination'],
], '我想找8月從台北出發的行程');
b0_assert(($normMissingDest['clarification_reason'] ?? '') === 'missing_destination', 'closed reason: missing_destination preserved');

// --- Date Prompt Contract: fixture Gemini JSON (no live Gemini; Normalize only flattens) ---
$fixtureFutureMonth = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'destination' => ['日本'],
        'departure' => '台北',
        'date_expression' => '8月',
        'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
    ],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '我想找8月從台北出發去日本的行程', $refJul14);
b0_assert(($fixtureFutureMonth['entities']['date_from'] ?? null) === '2026-08-01', 'Fixture future month date_from');
b0_assert(($fixtureFutureMonth['entities']['date_to'] ?? null) === '2026-08-31', 'Fixture future month date_to');
b0_assert(($fixtureFutureMonth['entities']['date_expression'] ?? null) === '8月', 'Fixture future month preserves date_expression');

$fixtureCurrentMonth = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'date_expression' => '7月',
        'date_range' => ['from' => '2026-07-14', 'to' => '2026-07-31'],
    ],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '7月行程', $refJul14);
b0_assert(($fixtureCurrentMonth['entities']['date_from'] ?? null) === '2026-07-14', 'Fixture current month date_from = reference');
b0_assert(($fixtureCurrentMonth['entities']['date_to'] ?? null) === '2026-07-31', 'Fixture current month date_to');
b0_assert(($fixtureCurrentMonth['entities']['date_expression'] ?? null) === '7月', 'Fixture current month preserves date_expression');

$fixtureCrossYear = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'date_expression' => '1月',
        'date_range' => ['from' => '2027-01-01', 'to' => '2027-01-31'],
    ],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '1月', $refDec20);
b0_assert(($fixtureCrossYear['entities']['date_from'] ?? null) === '2027-01-01', 'Fixture cross-year date_from');
b0_assert(($fixtureCrossYear['entities']['date_to'] ?? null) === '2027-01-31', 'Fixture cross-year date_to');
b0_assert(($fixtureCrossYear['entities']['date_expression'] ?? null) === '1月', 'Fixture cross-year preserves date_expression');

$fixtureExplicit = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'date_expression' => '2027年3月',
        'date_range' => ['from' => '2027-03-01', 'to' => '2027-03-31'],
    ],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '2027年3月', $refJul14);
b0_assert(($fixtureExplicit['entities']['date_from'] ?? null) === '2027-03-01', 'Fixture explicit year/month date_from');
b0_assert(($fixtureExplicit['entities']['date_to'] ?? null) === '2027-03-31', 'Fixture explicit year/month date_to');
b0_assert(($fixtureExplicit['entities']['date_expression'] ?? null) === '2027年3月', 'Fixture explicit preserves date_expression');

$fixtureRecent = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'date_expression' => '近期',
        'date_range' => ['from' => '2026-07-14', 'to' => '2026-09-12'],
    ],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '近期想出國', $refJul14);
b0_assert(($fixtureRecent['entities']['date_from'] ?? null) === '2026-07-14', 'Fixture 近期 date_from');
b0_assert(($fixtureRecent['entities']['date_to'] ?? null) === '2026-09-12', 'Fixture 近期 date_to = ref+60d');
b0_assert(($fixtureRecent['entities']['date_expression'] ?? null) === '近期', 'Fixture 近期 preserves date_expression');

$fixtureComplete = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'date_expression' => '2026-08-10到2026-08-20',
        'date_range' => ['from' => '2026-08-10', 'to' => '2026-08-20'],
    ],
    'confidence' => 0.95,
    'clarification' => ['required' => false, 'reason' => ''],
], '2026-08-10到2026-08-20', $refJul14);
b0_assert(($fixtureComplete['entities']['date_from'] ?? null) === '2026-08-10', 'Fixture complete range date_from unchanged');
b0_assert(($fixtureComplete['entities']['date_to'] ?? null) === '2026-08-20', 'Fixture complete range date_to unchanged');

// Responsibility: Normalizer must not invent dates from utterance when Gemini omitted range
$noInvent = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'date_expression' => '8月',
        'date_range' => null,
    ],
    'confidence' => 0.7,
    'clarification' => ['required' => false, 'reason' => ''],
], '我想找8月從台北出發去日本的行程', $refJul14);
b0_assert(($noInvent['entities']['date_from'] ?? null) === null, 'No Runtime invent: date_from stays null');
b0_assert(($noInvent['entities']['date_to'] ?? null) === null, 'No Runtime invent: date_to stays null');
b0_assert(($noInvent['entities']['date_expression'] ?? null) === '8月', 'No Runtime invent: expression preserved');

// --- B0-3 Runtime Result ---
$runtime = AiIntentUnderstandingRuntime::createForTesting(
    new AiuGeminiUnderstandingClientStub(static function () use ($shaped): array {
        return $shaped;
    })
);
$result = $runtime->understand('京都自由行10月', [
    'conversation_id' => 'c1',
    'tenant_sno' => '5f99b8d665e8444d',
    'now' => $refJul14,
    'reference_date' => $refJul14,
]);
$arr = $result->toArray();
b0_assert(isset($arr['entities']), 'Result toArray has entities');
b0_assert(!array_key_exists('dispatch_plan', $arr), 'B0-3 Result toArray has no dispatch_plan');
b0_assert(!array_key_exists('execution_hint', $arr), 'B0-3 Result toArray has no execution_hint');
b0_assert(!array_key_exists('entity', $arr), 'B0-1 Result toArray has no entity alias');
b0_assert(!array_key_exists('travel_type', $arr['entities']), 'Frozen: Result entities have no travel_type');
b0_assert(is_array($result->getEntities()['destination']), 'Result entities.destination array');

echo "\nB0 CONTRACT VALIDATION: ALL PASS\n";
exit(0);
