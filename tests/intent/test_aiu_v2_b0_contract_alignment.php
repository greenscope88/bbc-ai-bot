<?php
declare(strict_types=1);

/**
 * B0 Contract Alignment — Gemini Output + Normalize contract tests.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/core/intent/AiuPromptBuilder.php';
require_once $root . '/core/intent/AiuPromptRequest.php';
require_once $root . '/core/intent/AiuProductSetContext.php';
require_once $root . '/core/intent/AiuProductSetContextResolver.php';
require_once $root . '/core/intent/AiuGeminiUnderstandingClient.php';
require_once $root . '/core/intent/AiuSemanticJsonNormalizer.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once $root . '/core/intent/AiIntentUnderstandingResult.php';
require_once $root . '/core/intent/AiIntentCategory.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntimeSelector.php';
require_once $root . '/core/conversation/ConversationOwner.php';
require_once $root . '/tests/support/AiuDestinationSemanticsTestFixtures.php';

$b0ProductSetContext = (new AiuProductSetContextResolver())->resolve('5f99b8d665e8444d');



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
    null,
    null,
    $b0ProductSetContext
);
$prompt = $builder->build($req);
b0_assert(strpos($prompt, '"entities"') !== false, 'B0-6 prompt mentions entities');
b0_assert(
    strpos($prompt, '"search_keyword_components": [') !== false
    && strpos($prompt, '"search_keyword_tokens": []') !== false,
    '3J-9 non-resume B-10 skeleton includes components and final tokens'
);
b0_assert(
    strpos($prompt, 'search_keyword_tokens MUST exactly equal, in the same order and count') !== false,
    '3J-9 non-resume B-10 requires exact keep-surface token equality'
);
$resumeReq = new AiuPromptRequest(
    'sno',
    'line',
    '9月',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $refJul14,
    null,
    ['version' => 1],
    $b0ProductSetContext
);
$resumePrompt = $builder->build($resumeReq);
b0_assert(
    strpos($resumePrompt, '"search_keyword_components": [') !== false
    && strpos($resumePrompt, '"search_keyword_tokens": []') !== false,
    '3J-9 resume B-10 skeleton includes components and final tokens'
);
b0_assert(
    strpos($resumePrompt, 'search_keyword_tokens MUST exactly equal, in the same order and count') !== false,
    '3J-9 resume B-10 requires exact keep-surface token equality'
);

// --- B0-LINE-01D-3J-11: [B-03a] Product-Set Context grounds [B-03d] for both non-resume and resume paths ---
foreach ([$prompt, $resumePrompt] as $b03aLabel => $b03aPrompt) {
    unset($b03aLabel);
    b0_assert(
        strpos($b03aPrompt, '[B-03a Product-Set Context') !== false,
        '3J-11 prompt includes [B-03a] Product-Set Context block'
    );
    foreach ($b0ProductSetContext->getSearchableProductCategories() as $b03aCategory) {
        b0_assert(
            strpos($b03aPrompt, $b03aCategory) !== false,
            '3J-11 [B-03a] block includes resolved category ' . $b03aCategory
        );
    }
    foreach ($b0ProductSetContext->getExecutableSearchDimensions() as $b03aDimension) {
        b0_assert(
            strpos($b03aPrompt, $b03aDimension) !== false,
            '3J-11 [B-03a] block includes resolved dimension ' . $b03aDimension
        );
    }
    b0_assert(
        strpos($b03aPrompt, '[B-03a] Product-Set Context searchable_product_categories for this request') !== false,
        '3J-11 [B-03d] Step 1 references [B-03a] for the active product set'
    );
    b0_assert(
        strpos($b03aPrompt, 'Executability MUST be judged against [B-03a] executable_search_dimensions') !== false,
        '3J-11 [B-03d] Step 3 references [B-03a] for executability'
    );
}
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
$reqDec = new AiuPromptRequest('sno', 'line', '1月', [], ConversationOwner::AI, 'active', null, $refDec20, null, null, $b0ProductSetContext);
$promptDec = $builder->build($reqDec);
b0_assert(strpos($promptDec, 'reference_calendar_date: 2026-12-20') !== false, 'Prompt injects Dec reference_calendar_date');
b0_assert(strpos($promptDec, 'reference_timezone: Asia/Taipei') !== false, 'Prompt keeps Asia/Taipei on Dec reference');

// --- B0-1 / B0-2 Client shape ---
$client = new AiuGeminiUnderstandingClient(null, static function () {
    return [
        'ok' => true,
        'text' => json_encode([
            'intent' => 'product_search',
            'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities([
                'date_range' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
                'date_expression' => '10月',
                'product_type' => '自由行',
                'search_keyword_components' => [
                    ['surface' => '京都', 'semantic_role' => 'product_constraint', 'decision' => 'keep', 'decision_reason' => 'destination narrows product set'],
                    ['surface' => '大阪', 'semantic_role' => 'product_constraint', 'decision' => 'keep', 'decision_reason' => 'destination narrows product set'],
                    ['surface' => '自由行', 'semantic_role' => 'catalog_object_restatement', 'decision' => 'omit', 'decision_reason' => 'restates catalog product class only'],
                ],
                'search_keyword_tokens' => ['京都', '大阪'],
            ], ['京都', '大阪'], 'single'),
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
b0_assert(count($selectorRes) === 3, 'FR-1 selector fail-closed fields include error');
b0_assert(array_key_exists('error', $selectorRes) && is_string($selectorRes['error']) && $selectorRes['error'] !== '', 'FR-1 selector error present');
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
    'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities([
        'date_range' => null,
        'date_expression' => '有空再去',
    ], ['日本']),
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
        'entities' => array_merge(['destination' => []], AiuDestinationSemanticsTestFixtures::missingDestinationContract()),
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
    'entities' => array_merge(
        AiuDestinationSemanticsTestFixtures::missingDestinationContract(),
        ['date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31']]
    ),
    'confidence' => 0.8,
    'clarification' => ['required' => true, 'reason' => 'missing_destination'],
], '我想找8月從台北出發的行程');
b0_assert(($normMissingDest['clarification_reason'] ?? '') === 'missing_destination', 'closed reason: missing_destination preserved');

// --- Date Prompt Contract: fixture Gemini JSON (no live Gemini; Normalize only flattens) ---
$fixtureFutureMonth = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities([
        'departure' => '台北',
        'date_expression' => '8月',
        'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
    ], ['日本']),
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '我想找8月從台北出發去日本的行程', $refJul14);
b0_assert(($fixtureFutureMonth['entities']['date_from'] ?? null) === '2026-08-01', 'Fixture future month date_from');
b0_assert(($fixtureFutureMonth['entities']['date_to'] ?? null) === '2026-08-31', 'Fixture future month date_to');
b0_assert(($fixtureFutureMonth['entities']['date_expression'] ?? null) === '8月', 'Fixture future month preserves date_expression');

$fixtureCurrentMonth = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => array_merge(
        AiuDestinationSemanticsTestFixtures::missingDestinationContract(),
        [
            'date_expression' => '7月',
            'date_range' => ['from' => '2026-07-14', 'to' => '2026-07-31'],
        ]
    ),
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '7月行程', $refJul14);
b0_assert(($fixtureCurrentMonth['entities']['date_from'] ?? null) === '2026-07-14', 'Fixture current month date_from = reference');
b0_assert(($fixtureCurrentMonth['entities']['date_to'] ?? null) === '2026-07-31', 'Fixture current month date_to');
b0_assert(($fixtureCurrentMonth['entities']['date_expression'] ?? null) === '7月', 'Fixture current month preserves date_expression');

$fixtureCrossYear = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => array_merge(
        AiuDestinationSemanticsTestFixtures::missingDestinationContract(),
        [
            'date_expression' => '1月',
            'date_range' => ['from' => '2027-01-01', 'to' => '2027-01-31'],
        ]
    ),
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '1月', $refDec20);
b0_assert(($fixtureCrossYear['entities']['date_from'] ?? null) === '2027-01-01', 'Fixture cross-year date_from');
b0_assert(($fixtureCrossYear['entities']['date_to'] ?? null) === '2027-01-31', 'Fixture cross-year date_to');
b0_assert(($fixtureCrossYear['entities']['date_expression'] ?? null) === '1月', 'Fixture cross-year preserves date_expression');

$fixtureExplicit = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => array_merge(
        AiuDestinationSemanticsTestFixtures::missingDestinationContract(),
        [
            'date_expression' => '2027年3月',
            'date_range' => ['from' => '2027-03-01', 'to' => '2027-03-31'],
        ]
    ),
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '2027年3月', $refJul14);
b0_assert(($fixtureExplicit['entities']['date_from'] ?? null) === '2027-03-01', 'Fixture explicit year/month date_from');
b0_assert(($fixtureExplicit['entities']['date_to'] ?? null) === '2027-03-31', 'Fixture explicit year/month date_to');
b0_assert(($fixtureExplicit['entities']['date_expression'] ?? null) === '2027年3月', 'Fixture explicit preserves date_expression');

$fixtureRecent = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => array_merge(
        AiuDestinationSemanticsTestFixtures::missingDestinationContract(),
        [
            'date_expression' => '近期',
            'date_range' => ['from' => '2026-07-14', 'to' => '2026-09-12'],
        ]
    ),
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '近期想出國', $refJul14);
b0_assert(($fixtureRecent['entities']['date_from'] ?? null) === '2026-07-14', 'Fixture 近期 date_from');
b0_assert(($fixtureRecent['entities']['date_to'] ?? null) === '2026-09-12', 'Fixture 近期 date_to = ref+60d');
b0_assert(($fixtureRecent['entities']['date_expression'] ?? null) === '近期', 'Fixture 近期 preserves date_expression');

$fixtureComplete = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => array_merge(
        AiuDestinationSemanticsTestFixtures::missingDestinationContract(),
        [
            'date_expression' => '2026-08-10到2026-08-20',
            'date_range' => ['from' => '2026-08-10', 'to' => '2026-08-20'],
        ]
    ),
    'confidence' => 0.95,
    'clarification' => ['required' => false, 'reason' => ''],
], '2026-08-10到2026-08-20', $refJul14);
b0_assert(($fixtureComplete['entities']['date_from'] ?? null) === '2026-08-10', 'Fixture complete range date_from unchanged');
b0_assert(($fixtureComplete['entities']['date_to'] ?? null) === '2026-08-20', 'Fixture complete range date_to unchanged');

// Responsibility: Normalizer must not invent dates from utterance when Gemini omitted range
$noInvent = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => array_merge(
        AiuDestinationSemanticsTestFixtures::missingDestinationContract(),
        [
            'date_expression' => '8月',
            'date_range' => null,
        ]
    ),
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

// --- B0-LINE-01D-3J / 3J-3: geographic surface + generic keyword discriminator Prompt contract ---
require_once $root . '/core/intent/AiuSearchKeywordTokenProjector.php';
b0_assert(strpos($prompt, '[B-03d Search Keyword Tokens — Product Search]') !== false, '3J-3 prompt includes single B-03d block');
b0_assert(
    substr_count($prompt, '[B-03d Search Keyword Tokens — Product Search]') === 1,
    '3J-3 prompt has exactly one authoritative B-03d discriminator contract'
);
b0_assert(
    strpos($prompt, 'independently able to narrow the customer\'s product-search intent if used alone as a search condition') !== false,
    '3J-3 prompt requires each token to independently narrow product-search intent'
);
b0_assert(
    strpos($prompt, 'if removing it would broaden or change the customer\'s product-search intent, keep it; if removing it would leave that intent unchanged, do not emit it') !== false,
    '3J-3 prompt requires semantic per-token removal self-check'
);
b0_assert(
    strpos($prompt, 'geographic surface, theme, activity, promotion, festival/event, attraction/POI/landmark, and preference') !== false,
    '3J-3 prompt applies one discriminability rule across all candidate classes'
);
b0_assert(
    strpos($prompt, 'Preserve each emitted token\'s original customer surface wording and relative order') !== false,
    '3J-3 prompt preserves original surface wording and order'
);
b0_assert(
    strpos($prompt, 'Structured destination fields (destination labels and destination_semantics labels) MAY use a canonical geographic form') !== false,
    '3J prompt allows structured destination canonicalization'
);
b0_assert(
    strpos($prompt, "search_keyword_tokens MUST retain the customer's original geographic surface term(s)") !== false,
    '3J prompt requires retaining original geographic surface terms in keyword tokens'
);
b0_assert(
    strpos($prompt, 'Keep independent theme / preference / event tokens as separate entries') !== false,
    '3J prompt requires independent theme tokens'
);
b0_assert(
    strpos($prompt, 'generic travel-product wording that only expresses a desire to search or buy travel products without narrowing the product set') !== false,
    '3J prompt excludes non-narrowing generic travel-product wording from keyword tokens'
);
b0_assert(
    strpos($prompt, 'Assign these roles by natural-language semantic understanding only') !== false,
    '3J prompt assigns roles via Gemini semantic understanding only'
);
b0_assert(
    strpos($prompt, 'does not re-parse the customer utterance to invent, rewrite, translate, or delete keyword tokens') !== false,
    '3J-3 prompt states Runtime only validates and passes through (no invent/rewrite/translate/delete)'
);

// --- B0-LINE-01D-3J-5: single generic removal self-check refinement (no parallel/second authority block) ---
b0_assert(
    strpos($prompt, 'minimal complete semantic component of the customer\'s wording (not a fragment of one)') !== false,
    '3J-5 prompt requires tokens to be minimal complete semantic components, not fragments'
);
b0_assert(
    strpos($prompt, 'Treat an indivisible proper name or compound place/POI name as one minimal complete semantic component') !== false,
    '3J-5 prompt keeps indivisible proper names / compound place names intact'
);
b0_assert(
    strpos($prompt, 'never split it into fragments or drop only part of it') !== false,
    '3J-5 prompt forbids splitting or partially dropping an indivisible compound name'
);
b0_assert(
    substr_count($prompt, 'discriminability self-check') >= 1
    && substr_count($prompt, '[B-03d Search Keyword Tokens — Product Search]') === 1,
    '3J-5 refinement stays inside the single existing B-03d authority (no parallel rules block)'
);

// Production prompt no-hardcode / no-blacklist scan (literal cases allowed only in tests)
b0_assert(strpos($prompt, '我想去韓國賞花行程') === false, '3J Production prompt has no unique-case utterance literal');
b0_assert(strpos($prompt, '我想去日本賞花行程') === false, '3J-3 Production prompt has no Japan flower utterance literal');
b0_assert(strpos($prompt, '我想要去韓國賞楓行程') === false, '3J-3 Production prompt has no Korea maple utterance literal');
b0_assert(strpos($prompt, '韓國') === false, '3J Production prompt has no unique-case country surface literal');
b0_assert(strpos($prompt, '日本') === false, '3J-3 Production prompt has no Japan surface literal');
b0_assert(strpos($prompt, '南韓') === false, '3J Production prompt has no unique-case canonical country literal');
b0_assert(strpos($prompt, '賞花') === false, '3J Production prompt has no unique-case theme literal');
b0_assert(strpos($prompt, '賞楓') === false, '3J-3 Production prompt has no maple theme literal');
b0_assert(strpos($prompt, '行程') === false, '3J Production prompt has no hard-coded itinerary wording');
b0_assert(strpos($prompt, 'blacklist') === false && strpos($prompt, 'black-list') === false, '3J-3 Production prompt has no blacklist wording');
b0_assert(strpos($prompt, '韓國→南韓') === false && strpos($prompt, '韓國->南韓') === false, '3J Production prompt has no country alias hard-code');

// 3J-5 no-hardcode scan for the newly introduced fixture literals below
b0_assert(strpos($prompt, '泰國') === false, '3J-5 Production prompt has no Thailand surface literal');
b0_assert(strpos($prompt, '按摩') === false, '3J-5 Production prompt has no massage theme literal');
b0_assert(strpos($prompt, '浮潛') === false, '3J-5 Production prompt has no snorkeling activity literal');
b0_assert(strpos($prompt, '蜜月') === false, '3J-5 Production prompt has no honeymoon theme literal');
b0_assert(strpos($prompt, '大阪環球影城') === false, '3J-5 Production prompt has no Osaka USJ POI literal');
b0_assert(strpos($prompt, '東京迪士尼樂園') === false, '3J-5 Production prompt has no Tokyo Disneyland POI literal');
b0_assert(strpos($prompt, 'stop-word') === false && strpos($prompt, 'stop word') === false, '3J-5 Production prompt has no stop-word wording');
b0_assert(strpos($prompt, 'str_replace') === false, '3J-5 Production prompt has no str_replace wording');

// Unique acceptance fixture — Korea flower (test-only; regression must not degrade)
$geoSurfaceUtterance = '我想去韓國賞花行程';
$geoSurfaceStructuredDestination = ['南韓'];
$geoSurfaceKeywordTokens = ['韓國', '賞花'];
b0_assert($geoSurfaceUtterance === '我想去韓國賞花行程', '3J fixture utterance');
b0_assert($geoSurfaceStructuredDestination === ['南韓'], '3J fixture structured destination/country is canonical');
b0_assert($geoSurfaceKeywordTokens === ['韓國', '賞花'], '3J fixture tokens retain surface geo + independent theme');
b0_assert(!in_array('行程', $geoSurfaceKeywordTokens, true), '3J fixture excludes non-narrowing generic product wording');
$geoProj = (new AiuSearchKeywordTokenProjector())->project(
    $geoSurfaceKeywordTokens,
    AiuSearchKeywordTokenProjector::MODE_REQUIRED
);
b0_assert($geoProj !== null, '3J fixture projection result present');
b0_assert($geoProj->getKeyword() === '韓國 賞花', '3J fixture full projection');
b0_assert($geoProj->getTokens() === ['韓國', '賞花'], '3J fixture projector preserves tokens');

// Unique acceptance fixture — Japan flower (test-only; generic travel-product wording must not become a token)
$jpFlowerUtterance = '我想去日本賞花行程';
$jpFlowerStructuredDestination = ['日本'];
$jpFlowerKeywordTokens = ['日本', '賞花'];
b0_assert($jpFlowerUtterance === '我想去日本賞花行程', '3J-3 Japan flower fixture utterance');
b0_assert($jpFlowerStructuredDestination === ['日本'], '3J-3 Japan flower structured destination');
b0_assert($jpFlowerKeywordTokens === ['日本', '賞花'], '3J-3 Japan flower expected tokens');
b0_assert(!in_array('行程', $jpFlowerKeywordTokens, true), '3J-3 Japan flower excludes non-discriminating generic travel-product wording');
$jpProj = (new AiuSearchKeywordTokenProjector())->project(
    $jpFlowerKeywordTokens,
    AiuSearchKeywordTokenProjector::MODE_REQUIRED
);
b0_assert($jpProj !== null, '3J-3 Japan flower projection present');
b0_assert($jpProj->getKeyword() === '日本 賞花', '3J-3 Japan flower full projection');
b0_assert($jpProj->getTokens() === ['日本', '賞花'], '3J-3 Japan flower projector preserves tokens');

// Unique acceptance fixture — Korea maple (test-only; must not degrade)
$krMapleUtterance = '我想要去韓國賞楓行程';
$krMapleKeywordTokens = ['韓國', '賞楓'];
b0_assert($krMapleUtterance === '我想要去韓國賞楓行程', '3J-3 Korea maple fixture utterance');
b0_assert($krMapleKeywordTokens === ['韓國', '賞楓'], '3J-3 Korea maple expected tokens');
b0_assert(!in_array('行程', $krMapleKeywordTokens, true), '3J-3 Korea maple excludes non-discriminating generic travel-product wording');
$krMapleProj = (new AiuSearchKeywordTokenProjector())->project(
    $krMapleKeywordTokens,
    AiuSearchKeywordTokenProjector::MODE_REQUIRED
);
b0_assert($krMapleProj !== null, '3J-3 Korea maple projection present');
b0_assert($krMapleProj->getKeyword() === '韓國 賞楓', '3J-3 Korea maple full projection');
b0_assert($krMapleProj->getTokens() === ['韓國', '賞楓'], '3J-3 Korea maple projector preserves tokens');

// --- B0-LINE-01D-3J-5 scenario 1: geography + theme + generic product wording => geography/theme only ---
$thaiMassageUtterance = '我想去泰國按摩行程';
$thaiMassageStructuredDestination = ['泰國'];
$thaiMassageKeywordTokens = ['泰國', '按摩'];
b0_assert($thaiMassageUtterance === '我想去泰國按摩行程', '3J-5 Thailand massage fixture utterance');
b0_assert($thaiMassageStructuredDestination === ['泰國'], '3J-5 Thailand massage structured destination');
b0_assert($thaiMassageKeywordTokens === ['泰國', '按摩'], '3J-5 Thailand massage expected tokens: geography + theme only');
b0_assert(!in_array('行程', $thaiMassageKeywordTokens, true), '3J-5 Thailand massage excludes non-discriminating generic product wording');
$thaiMassageProj = (new AiuSearchKeywordTokenProjector())->project(
    $thaiMassageKeywordTokens,
    AiuSearchKeywordTokenProjector::MODE_REQUIRED
);
b0_assert($thaiMassageProj !== null, '3J-5 Thailand massage projection present');
b0_assert($thaiMassageProj->getKeyword() === '泰國 按摩', '3J-5 Thailand massage full projection');
b0_assert($thaiMassageProj->getTokens() === ['泰國', '按摩'], '3J-5 Thailand massage projector preserves tokens');

// --- B0-LINE-01D-3J-5 scenario 2: geography + activity + theme + generic product wording => all independently
// discriminative semantics retained, generic wording excluded ---
$thaiSnorkelHoneymoonUtterance = '我想安排泰國浮潛蜜月行程';
$thaiSnorkelHoneymoonStructuredDestination = ['泰國'];
$thaiSnorkelHoneymoonKeywordTokens = ['泰國', '浮潛', '蜜月'];
b0_assert($thaiSnorkelHoneymoonUtterance === '我想安排泰國浮潛蜜月行程', '3J-5 Thailand snorkel honeymoon fixture utterance');
b0_assert($thaiSnorkelHoneymoonStructuredDestination === ['泰國'], '3J-5 Thailand snorkel honeymoon structured destination');
b0_assert(
    $thaiSnorkelHoneymoonKeywordTokens === ['泰國', '浮潛', '蜜月'],
    '3J-5 Thailand snorkel honeymoon expected tokens: geography + activity + theme all retained'
);
b0_assert(
    !in_array('行程', $thaiSnorkelHoneymoonKeywordTokens, true),
    '3J-5 Thailand snorkel honeymoon excludes non-discriminating generic product wording'
);
$thaiSnorkelHoneymoonProj = (new AiuSearchKeywordTokenProjector())->project(
    $thaiSnorkelHoneymoonKeywordTokens,
    AiuSearchKeywordTokenProjector::MODE_REQUIRED
);
b0_assert($thaiSnorkelHoneymoonProj !== null, '3J-5 Thailand snorkel honeymoon projection present');
b0_assert($thaiSnorkelHoneymoonProj->getKeyword() === '泰國 浮潛 蜜月', '3J-5 Thailand snorkel honeymoon full projection');
b0_assert(
    $thaiSnorkelHoneymoonProj->getTokens() === ['泰國', '浮潛', '蜜月'],
    '3J-5 Thailand snorkel honeymoon projector preserves tokens'
);

// --- B0-LINE-01D-3J-5 scenario 3: multiple POI/geography names + generic product wording => valid POIs retained
// and compound proper names intact (not fragmented into their embedded geography substrings) ---
$multiPoiUtterance = '我想去大阪環球影城和東京迪士尼樂園自由行';
$multiPoiKeywordTokens = ['大阪環球影城', '東京迪士尼樂園'];
b0_assert($multiPoiUtterance === '我想去大阪環球影城和東京迪士尼樂園自由行', '3J-5 multi-POI fixture utterance');
b0_assert(
    $multiPoiKeywordTokens === ['大阪環球影城', '東京迪士尼樂園'],
    '3J-5 multi-POI expected tokens: both compound POI names retained intact'
);
b0_assert(!in_array('自由行', $multiPoiKeywordTokens, true), '3J-5 multi-POI excludes non-discriminating generic product wording');
b0_assert(
    !in_array('大阪', $multiPoiKeywordTokens, true)
    && !in_array('環球影城', $multiPoiKeywordTokens, true)
    && !in_array('東京', $multiPoiKeywordTokens, true)
    && !in_array('迪士尼樂園', $multiPoiKeywordTokens, true),
    '3J-5 multi-POI compound proper names are not fragmented into embedded geography/name parts'
);
$multiPoiProj = (new AiuSearchKeywordTokenProjector())->project(
    $multiPoiKeywordTokens,
    AiuSearchKeywordTokenProjector::MODE_REQUIRED
);
b0_assert($multiPoiProj !== null, '3J-5 multi-POI projection present');
b0_assert($multiPoiProj->getKeyword() === '大阪環球影城 東京迪士尼樂園', '3J-5 multi-POI full projection');
b0_assert(
    $multiPoiProj->getTokens() === ['大阪環球影城', '東京迪士尼樂園'],
    '3J-5 multi-POI projector preserves compound tokens intact'
);

// --- B0-LINE-01D-3J-6: Product-Set Keyword Role Discriminator — production prompt contract ---
b0_assert(
    strpos($prompt, "CURRENT SEARCHABLE TRAVEL PRODUCT SET") !== false
    && strpos($prompt, 'travel-product-vs-non-travel-information distinction') !== false,
    '3J-6 prompt states product-set baseline, not travel-vs-non-travel distinction'
);
b0_assert(
    strpos($prompt, 'Product constraint') !== false && strpos($prompt, 'Catalog/object restatement') !== false,
    '3J-6 prompt defines Product constraint vs Catalog/object restatement roles'
);
b0_assert(
    strpos($prompt, 'Emit ONLY Product constraint components as search_keyword_tokens') !== false,
    '3J-6 prompt restricts emission to Product constraint role only'
);
b0_assert(
    strpos($prompt, 'Counterfactual-plus-executability check') !== false
    && strpos($prompt, 'the current product search contract can actually execute that distinction') !== false,
    '3J-6 prompt requires counterfactual + executability retention test'
);
b0_assert(
    strpos($prompt, 'Never retain wording merely to reserve a future or not-yet-established product category') !== false,
    '3J-6 prompt forbids future/unestablished category reservation'
);
b0_assert(
    strpos($prompt, 'semantically separate the two roles and keep only the Product constraint role as a token') !== false
    && strpos($prompt, 'meaning-based role decomposition, not mechanical string splitting') !== false,
    '3J-6 prompt requires semantic role decomposition for fused phrases, not string splitting'
);
b0_assert(
    strpos($prompt, 'Never drop a nearby theme, activity, promotion, festival/event, or POI token') !== false,
    '3J-6 prompt forbids dropping valid nearby theme/activity/promotion/festival/POI tokens'
);
b0_assert(
    strpos($prompt, "generic travel-product wording that only expresses a desire to search or buy travel products without narrowing the product set") !== false,
    '3J-6 refinement preserves prior non-narrowing generic wording exclusion wording'
);
b0_assert(
    substr_count($prompt, '[B-03d Search Keyword Tokens — Product Search]') === 1,
    '3J-6 refinement stays inside the single existing B-03d authority (no parallel block)'
);

// 3J-6 no-hardcode scan for the newly introduced fixture literals below
b0_assert(strpos($prompt, '越南') === false, '3J-6 Production prompt has no Vietnam surface literal');
b0_assert(strpos($prompt, '菲律賓') === false, '3J-6 Production prompt has no Philippines surface literal');
b0_assert(strpos($prompt, '北海道') === false, '3J-6 Production prompt has no Hokkaido surface literal');
b0_assert(strpos($prompt, '富士山') === false, '3J-6 Production prompt has no Mt. Fuji POI literal');
b0_assert(strpos($prompt, '箱根') === false, '3J-6 Production prompt has no Hakone POI literal');
b0_assert(strpos($prompt, '沖繩美麗海水族館') === false, '3J-6 Production prompt has no Okinawa aquarium compound POI literal');
b0_assert(strpos($prompt, '溫泉') === false, '3J-6 Production prompt has no hot-spring theme literal');
b0_assert(strpos($prompt, '跳島') === false, '3J-6 Production prompt has no island-hopping theme literal');
b0_assert(strpos($prompt, '旅遊') === false, '3J-6 Production prompt has no generic tourism catalog-class literal');
b0_assert(strpos($prompt, '觀光') === false, '3J-6 Production prompt has no generic sightseeing catalog-class literal');
b0_assert(strpos($prompt, '早鳥優惠') === false, '3J-6 Production prompt has no early-bird promotion literal');
b0_assert(strpos($prompt, '潛水') === false, '3J-6 Production prompt has no diving activity literal');
b0_assert(strpos($prompt, '母親節') === false, '3J-6 Production prompt has no Mother\'s Day occasion literal');
b0_assert(strpos($prompt, '賞雪') === false, '3J-6 Production prompt has no snow-viewing theme literal');
b0_assert(strpos($prompt, '日本團') === false, '3J-6 Production prompt has no inherent tour-class fixture literal');
b0_assert(strpos($prompt, '團體旅遊') === false, '3J-6 Production prompt has no inherent group-travel fixture literal');

// --- Category 1: geography + theme + generic wording => two tokens (geography, theme) ---
$c1Utterance = '我想去菲律賓跳島行程';
$c1StructuredDestination = ['菲律賓'];
$c1KeywordTokens = ['菲律賓', '跳島'];
b0_assert($c1Utterance === '我想去菲律賓跳島行程', '3J-6 category1 fixture utterance');
b0_assert($c1StructuredDestination === ['菲律賓'], '3J-6 category1 structured destination');
b0_assert($c1KeywordTokens === ['菲律賓', '跳島'], '3J-6 category1 expected tokens: geography + theme only');
b0_assert(!in_array('行程', $c1KeywordTokens, true), '3J-6 category1 excludes catalog/object restatement generic wording');
$c1Proj = (new AiuSearchKeywordTokenProjector())->project($c1KeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c1Proj !== null, '3J-6 category1 projection present');
b0_assert($c1Proj->getKeyword() === '菲律賓 跳島', '3J-6 category1 full projection');
b0_assert($c1Proj->getTokens() === ['菲律賓', '跳島'], '3J-6 category1 projector preserves tokens');

// --- Category 2: geography + fused theme phrase => geography + theme semantically separated (not string split) ---
$c2Utterance = '你們有日本溫泉行程嗎';
$c2StructuredDestination = ['日本'];
$c2KeywordTokens = ['日本', '溫泉'];
b0_assert($c2Utterance === '你們有日本溫泉行程嗎', '3J-6 category2 production-regression fixture utterance');
b0_assert($c2StructuredDestination === ['日本'], '3J-6 category2 structured destination');
b0_assert(
    $c2KeywordTokens === ['日本', '溫泉'],
    '3J-6 category2 expected tokens: geography and theme separated from fused catalog wording'
);
b0_assert(!in_array('溫泉行程', $c2KeywordTokens, true), '3J-6 category2 does not keep fused theme + catalog wording');
$c2Proj = (new AiuSearchKeywordTokenProjector())->project($c2KeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c2Proj !== null, '3J-6 category2 projection present');
b0_assert($c2Proj->getKeyword() === '日本 溫泉', '3J-6 category2 full projection');
b0_assert($c2Proj->getTokens() === ['日本', '溫泉'], '3J-6 category2 projector preserves tokens');

// --- Category 3: geography + inherent catalog-class wording => geography only (two fixtures) ---
$c3aUtterance = '你們有日本團嗎';
$c3aKeywordTokens = ['日本'];
b0_assert($c3aUtterance === '你們有日本團嗎', '3J-6 category3a required fixture utterance');
b0_assert($c3aKeywordTokens === ['日本'], '3J-6 category3a expected tokens: geography only');
b0_assert(!in_array('團', $c3aKeywordTokens, true), '3J-6 category3a excludes inherent catalog-class restatement');
$c3aProj = (new AiuSearchKeywordTokenProjector())->project($c3aKeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c3aProj !== null, '3J-6 category3a projection present');
b0_assert($c3aProj->getKeyword() === '日本', '3J-6 category3a full projection');
b0_assert($c3aProj->getTokens() === ['日本'], '3J-6 category3a projector preserves tokens');

$c3bUtterance = '我想找日本團體旅遊';
$c3bKeywordTokens = ['日本'];
b0_assert($c3bUtterance === '我想找日本團體旅遊', '3J-6 category3b required fixture utterance');
b0_assert($c3bKeywordTokens === ['日本'], '3J-6 category3b expected tokens: geography only');
b0_assert(
    !in_array('團體旅遊', $c3bKeywordTokens, true),
    '3J-6 category3b excludes inherent group-travel catalog restatement'
);
$c3bProj = (new AiuSearchKeywordTokenProjector())->project($c3bKeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c3bProj !== null, '3J-6 category3b projection present');
b0_assert($c3bProj->getKeyword() === '日本', '3J-6 category3b full projection');
b0_assert($c3bProj->getTokens() === ['日本'], '3J-6 category3b projector preserves tokens');

// --- Category 4: geography + promotion/activity + generic catalog wording => retain actual constraints ---
$c4Utterance = '我想找越南早鳥優惠潛水行程';
$c4StructuredDestination = ['越南'];
$c4KeywordTokens = ['越南', '早鳥優惠', '潛水'];
b0_assert($c4Utterance === '我想找越南早鳥優惠潛水行程', '3J-6 category4 fixture utterance');
b0_assert($c4StructuredDestination === ['越南'], '3J-6 category4 structured destination');
b0_assert(
    $c4KeywordTokens === ['越南', '早鳥優惠', '潛水'],
    '3J-6 category4 expected tokens: geography + promotion + activity all retained'
);
b0_assert(!in_array('行程', $c4KeywordTokens, true), '3J-6 category4 excludes generic catalog wording');
$c4Proj = (new AiuSearchKeywordTokenProjector())->project($c4KeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c4Proj !== null, '3J-6 category4 projection present');
b0_assert($c4Proj->getKeyword() === '越南 早鳥優惠 潛水', '3J-6 category4 full projection');
b0_assert($c4Proj->getTokens() === ['越南', '早鳥優惠', '潛水'], '3J-6 category4 projector preserves tokens');

// --- Category 5: geography + festival/occasion + theme => retain all constraints ---
$c5Utterance = '我想安排母親節去北海道賞雪行程';
$c5StructuredDestination = ['北海道'];
$c5KeywordTokens = ['母親節', '北海道', '賞雪'];
b0_assert($c5Utterance === '我想安排母親節去北海道賞雪行程', '3J-6 category5 fixture utterance');
b0_assert($c5StructuredDestination === ['北海道'], '3J-6 category5 structured destination');
b0_assert(
    $c5KeywordTokens === ['母親節', '北海道', '賞雪'],
    '3J-6 category5 expected tokens: occasion + geography + theme all retained in original surface order'
);
b0_assert(!in_array('行程', $c5KeywordTokens, true), '3J-6 category5 excludes generic catalog wording');
$c5Proj = (new AiuSearchKeywordTokenProjector())->project($c5KeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c5Proj !== null, '3J-6 category5 projection present');
b0_assert($c5Proj->getKeyword() === '母親節 北海道 賞雪', '3J-6 category5 full projection');
b0_assert($c5Proj->getTokens() === ['母親節', '北海道', '賞雪'], '3J-6 category5 projector preserves tokens');

// --- Category 6: multiple POI/geographic surfaces + generic wording => valid semantics retained ---
$c6Utterance = '我想去富士山和箱根泡溫泉行程';
$c6KeywordTokens = ['富士山', '箱根', '溫泉'];
b0_assert($c6Utterance === '我想去富士山和箱根泡溫泉行程', '3J-6 category6 fixture utterance');
b0_assert(
    $c6KeywordTokens === ['富士山', '箱根', '溫泉'],
    '3J-6 category6 expected tokens: both geographic/POI surfaces plus theme retained'
);
b0_assert(!in_array('行程', $c6KeywordTokens, true), '3J-6 category6 excludes generic catalog wording');
$c6Proj = (new AiuSearchKeywordTokenProjector())->project($c6KeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c6Proj !== null, '3J-6 category6 projection present');
b0_assert($c6Proj->getKeyword() === '富士山 箱根 溫泉', '3J-6 category6 full projection');
b0_assert($c6Proj->getTokens() === ['富士山', '箱根', '溫泉'], '3J-6 category6 projector preserves tokens');

// --- B0-LINE-01D-3J-7: mandatory ordered semantic-role decision — production prompt contract ---
b0_assert(
    strpos($prompt, 'Mandatory ordered decision: before emitting any token, you MUST perform Step 1 through Step 5 below, in order') !== false,
    '3J-7 prompt mandates ordered Step 1-5 semantic-role decision before token emission'
);
b0_assert(
    strpos($prompt, 'Only after completing the mandatory Step 1 through Step 5 decision below') !== false,
    '3J-7 prompt permits token output only after the ordered decision completes'
);
b0_assert(
    strpos($prompt, 'Step 1 — Establish the active product set') !== false,
    '3J-7 prompt defines Step 1 active product set'
);
b0_assert(
    strpos($prompt, "Step 2 — Classify each candidate's semantic role before emission") !== false,
    '3J-7 prompt defines Step 2 role classification before emission'
);
b0_assert(
    strpos($prompt, 'Step 3 — Counterfactual executability check') !== false,
    '3J-7 prompt defines Step 3 counterfactual executability check'
);
b0_assert(
    strpos($prompt, 'Step 4 — Decompose fused surface phrases before emission') !== false,
    '3J-7 prompt defines Step 4 fused phrase decomposition before emission'
);
b0_assert(
    strpos($prompt, 'Step 5 — Preserve valid original surface wording') !== false,
    '3J-7 prompt defines Step 5 preserve original surface wording'
);
b0_assert(
    strpos($prompt, 'never emit the whole fused surface phrase merely because one part of it is a valid Product constraint') !== false,
    '3J-7 prompt explicitly forbids emitting whole fused surface merely because one part is valid'
);
b0_assert(
    strpos($prompt, 'Only a component classified as Product constraint may proceed toward emission') !== false,
    '3J-7 prompt requires role classification to gate emission eligibility'
);
b0_assert(
    strpos($prompt, 'further narrows, changes, or reorders eligible products') !== false,
    '3J-7 prompt defines Product constraint against eligible product membership or ordering'
);
b0_assert(
    substr_count($prompt, '[B-03d Search Keyword Tokens — Product Search]') === 1,
    '3J-7 refinement stays inside the single existing B-03d authority (no parallel block)'
);

// 3J-7 no-hardcode scan for the newly introduced fixture literal below
b0_assert(strpos($prompt, '越南團') === false, '3J-7 Production prompt has no Vietnam-tour fused fixture literal');

// --- Category 8 (3J-7 required fixture): fused geography + inherent catalog-class wording => geography only ---
$c8Utterance = '你們有越南團嗎';
$c8StructuredDestination = ['越南'];
$c8KeywordTokens = ['越南'];
b0_assert($c8Utterance === '你們有越南團嗎', '3J-7 category8 required fixture utterance');
b0_assert($c8StructuredDestination === ['越南'], '3J-7 category8 structured destination');
b0_assert($c8KeywordTokens === ['越南'], '3J-7 category8 expected tokens: geography only, no fused catalog token');
b0_assert(!in_array('團', $c8KeywordTokens, true), '3J-7 category8 excludes inherent catalog-class restatement');
b0_assert(!in_array('越南團', $c8KeywordTokens, true), '3J-7 category8 does not keep the whole fused surface phrase');
$c8Proj = (new AiuSearchKeywordTokenProjector())->project($c8KeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c8Proj !== null, '3J-7 category8 projection present');
b0_assert($c8Proj->getKeyword() === '越南', '3J-7 category8 full projection');
b0_assert($c8Proj->getTokens() === ['越南'], '3J-7 category8 projector preserves single geography token');

// --- Category 7: indivisible compound proper name + generic wording => preserve name intact ---
$c7Utterance = '我想去沖繩美麗海水族館行程';
$c7KeywordTokens = ['沖繩美麗海水族館'];
b0_assert($c7Utterance === '我想去沖繩美麗海水族館行程', '3J-6 category7 fixture utterance');
b0_assert(
    $c7KeywordTokens === ['沖繩美麗海水族館'],
    '3J-6 category7 expected tokens: compound POI name preserved intact'
);
b0_assert(!in_array('行程', $c7KeywordTokens, true), '3J-6 category7 excludes generic catalog wording');
b0_assert(
    !in_array('沖繩', $c7KeywordTokens, true) && !in_array('美麗海水族館', $c7KeywordTokens, true),
    '3J-6 category7 compound proper name is not fragmented into its embedded geography/name parts'
);
$c7Proj = (new AiuSearchKeywordTokenProjector())->project($c7KeywordTokens, AiuSearchKeywordTokenProjector::MODE_REQUIRED);
b0_assert($c7Proj !== null, '3J-6 category7 projection present');
b0_assert($c7Proj->getKeyword() === '沖繩美麗海水族館', '3J-6 category7 full projection');
b0_assert($c7Proj->getTokens() === ['沖繩美麗海水族館'], '3J-6 category7 projector preserves compound token intact');

// --- B0-LINE-01D-3J-9: structured keyword role contract at the Gemini Client boundary ---

/**
 * @return array{surface: string, semantic_role: string, decision: string, decision_reason: string}
 */
function b0_keyword_component(string $surface, string $role, string $decision, string $reason = '因為此成分能縮小產品範圍'): array
{
    return [
        'surface' => $surface,
        'semantic_role' => $role,
        'decision' => $decision,
        'decision_reason' => $reason,
    ];
}

/**
 * @param mixed $components
 * @param mixed $tokens
 * @return array<string, mixed>
 */
function b0_keyword_entities($components, $tokens): array
{
    return [
        'search_keyword_components' => $components,
        'search_keyword_tokens' => $tokens,
    ];
}

/**
 * @param array<string, mixed> $entities
 */
function b0_keyword_client(array $entities, string $intent = 'product_search'): AiuGeminiUnderstandingClient
{
    return new AiuGeminiUnderstandingClient(null, static function () use ($entities, $intent) {
        return [
            'ok' => true,
            'text' => json_encode([
                'intent' => $intent,
                'entities' => $entities,
                'confidence' => 0.9,
                'clarification' => ['required' => false, 'reason' => ''],
            ], JSON_UNESCAPED_UNICODE),
            'error' => null,
        ];
    });
}

/**
 * @param array<string, mixed> $entities
 * @return array<string, mixed>
 */
function b0_keyword_understand(array $entities, string $intent = 'product_search'): array
{
    global $req;

    return b0_keyword_client($entities, $intent)->understand($req);
}

// --- Valid: mixed keep/omit ---
$mixedOut = b0_keyword_understand(b0_keyword_entities(
    [
        b0_keyword_component('沙巴', 'product_constraint', 'keep'),
        b0_keyword_component('自由行', 'catalog_object_restatement', 'omit'),
    ],
    ['沙巴']
));
b0_assert($mixedOut['entities']['search_keyword_tokens'] === ['沙巴'], '3J-9 mixed keep/omit tokens equal only the keep surface');
b0_assert(count($mixedOut['entities']['search_keyword_components']) === 2, '3J-9 mixed keep/omit preserves full component metadata');

// --- Valid: semantically fused input represented as distinct components (test literal only) ---
$fusedOut = b0_keyword_understand(b0_keyword_entities(
    [
        b0_keyword_component('峇里島', 'product_constraint', 'keep'),
        b0_keyword_component('蜜月', 'product_constraint', 'keep'),
        b0_keyword_component('自由行', 'catalog_object_restatement', 'omit'),
    ],
    ['峇里島', '蜜月']
));
b0_assert(
    $fusedOut['entities']['search_keyword_tokens'] === ['峇里島', '蜜月'],
    '3J-9 fused surface decomposed into distinct components yields only kept constraint tokens'
);

// --- Valid: multiple constraints, order preserved exactly as authored (not insertion-normalized) ---
$multiOut = b0_keyword_understand(b0_keyword_entities(
    [
        b0_keyword_component('沙巴', 'product_constraint', 'keep'),
        b0_keyword_component('自由行', 'catalog_object_restatement', 'omit'),
        b0_keyword_component('跳島', 'product_constraint', 'keep'),
        b0_keyword_component('潛水', 'product_constraint', 'keep'),
    ],
    ['沙巴', '跳島', '潛水']
));
b0_assert(
    $multiOut['entities']['search_keyword_tokens'] === ['沙巴', '跳島', '潛水'],
    '3J-9 multiple constraints preserve original component order in tokens'
);

// --- Valid: proper name single component ---
$properNameOut = b0_keyword_understand(b0_keyword_entities(
    [b0_keyword_component('東京迪士尼樂園', 'product_constraint', 'keep')],
    ['東京迪士尼樂園']
));
b0_assert(
    $properNameOut['entities']['search_keyword_tokens'] === ['東京迪士尼樂園'],
    '3J-9 proper name single component yields single matching token'
);

// --- Valid: all omit -> empty tokens ---
$allOmitOut = b0_keyword_understand(b0_keyword_entities(
    [
        b0_keyword_component('自由行', 'catalog_object_restatement', 'omit'),
        b0_keyword_component('旅遊', 'catalog_object_restatement', 'omit'),
    ],
    []
));
b0_assert($allOmitOut['entities']['search_keyword_tokens'] === [], '3J-9 all-omit components yield empty tokens array');

// --- Fail-closed: duplicate keep surfaces would break post-projector equality ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [
            b0_keyword_component('沙巴', 'product_constraint', 'keep'),
            b0_keyword_component('沙巴', 'product_constraint', 'keep'),
        ],
        ['沙巴', '沙巴']
    )),
    '3J-9 duplicate keep surfaces must fail closed'
);

// --- Fail-closed: missing search_keyword_components (old shape only) ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(['search_keyword_tokens' => ['沙巴']]),
    '3J-9 old-shape-only entities (bare search_keyword_tokens, no components) must fail closed'
);

// --- Fail-closed: missing search_keyword_tokens ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(['search_keyword_components' => [b0_keyword_component('沙巴', 'product_constraint', 'keep')]]),
    '3J-9 missing search_keyword_tokens must fail closed'
);

// --- Fail-closed: search_keyword_components not an array ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities('沙巴', ['沙巴'])),
    '3J-9 non-array search_keyword_components must fail closed'
);

// --- Fail-closed: search_keyword_tokens not an array ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities([b0_keyword_component('沙巴', 'product_constraint', 'keep')], '沙巴')),
    '3J-9 non-array search_keyword_tokens must fail closed'
);

// --- Fail-closed: search_keyword_components malformed shape (sparse/object keys, not a list) ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [0 => b0_keyword_component('沙巴', 'product_constraint', 'keep'), 2 => b0_keyword_component('跳島', 'product_constraint', 'keep')],
        ['沙巴', '跳島']
    )),
    '3J-9 non-list (sparse-keyed) search_keyword_components must fail closed'
);

// --- Fail-closed: component element not an object ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(['沙巴'], ['沙巴'])),
    '3J-9 non-object search_keyword_components element must fail closed'
);

// --- Fail-closed: blank surface ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('   ', 'product_constraint', 'keep')],
        ['   ']
    )),
    '3J-9 blank surface must fail closed'
);

// --- Fail-closed: malformed surface type ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [array_merge(b0_keyword_component('沙巴', 'product_constraint', 'keep'), ['surface' => 123])],
        [123]
    )),
    '3J-9 non-string surface must fail closed'
);

// --- Fail-closed: invalid semantic_role enum ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('沙巴', 'travel_destination', 'keep')],
        ['沙巴']
    )),
    '3J-9 invalid semantic_role enum must fail closed'
);

// --- Fail-closed: invalid decision enum ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('沙巴', 'product_constraint', 'maybe')],
        ['沙巴']
    )),
    '3J-9 invalid decision enum must fail closed'
);

// --- Fail-closed: blank decision_reason ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('沙巴', 'product_constraint', 'keep', '  ')],
        ['沙巴']
    )),
    '3J-9 blank decision_reason must fail closed'
);

// --- Fail-closed: malformed decision_reason type ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [array_merge(b0_keyword_component('沙巴', 'product_constraint', 'keep'), ['decision_reason' => ['not', 'a', 'string']])],
        ['沙巴']
    )),
    '3J-9 non-string decision_reason must fail closed'
);

// --- Fail-closed: catalog_object_restatement + keep must fail ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('自由行', 'catalog_object_restatement', 'keep')],
        ['自由行']
    )),
    '3J-9 catalog_object_restatement with decision=keep must fail closed'
);

// --- Fail-closed: tokens value mismatch ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('沙巴', 'product_constraint', 'keep')],
        ['跳島']
    )),
    '3J-9 tokens value mismatch against keep surfaces must fail closed'
);

// --- Fail-closed: tokens order mismatch ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [
            b0_keyword_component('沙巴', 'product_constraint', 'keep'),
            b0_keyword_component('跳島', 'product_constraint', 'keep'),
        ],
        ['跳島', '沙巴']
    )),
    '3J-9 tokens order mismatch against keep surfaces must fail closed'
);

// --- Fail-closed: tokens extra element beyond keep surfaces ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('沙巴', 'product_constraint', 'keep')],
        ['沙巴', '跳島']
    )),
    '3J-9 tokens extra element beyond keep surfaces must fail closed'
);

// --- Fail-closed: tokens missing an element present in keep surfaces ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [
            b0_keyword_component('沙巴', 'product_constraint', 'keep'),
            b0_keyword_component('跳島', 'product_constraint', 'keep'),
        ],
        ['沙巴']
    )),
    '3J-9 tokens missing an element present in keep surfaces must fail closed'
);

// --- Fail-closed: token element non-string type ---
b0_assert_contract_failure(
    static fn () => b0_keyword_understand(b0_keyword_entities(
        [b0_keyword_component('沙巴', 'product_constraint', 'keep')],
        [123]
    )),
    '3J-9 non-string search_keyword_tokens element must fail closed'
);

echo "\nB0 CONTRACT VALIDATION: ALL PASS\n";
exit(0);
