<?php
declare(strict_types=1);

/**
 * Closed Product Search AIU clarification reason vocabulary tests.
 * Fixtures/stubs only — no live Gemini or LINE.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/core/intent/AiuClarificationReasonContract.php';
require_once $root . '/core/intent/AiuPromptBuilder.php';
require_once $root . '/core/intent/AiuPromptRequest.php';
require_once $root . '/core/intent/AiuProductSetContext.php';
require_once $root . '/core/intent/AiuProductSetContextResolver.php';
require_once $root . '/core/intent/AiuSemanticJsonNormalizer.php';
require_once $root . '/core/intent/AiuProductIntentTranslator.php';
require_once $root . '/core/intent/AiIntentUnderstandingResult.php';
require_once $root . '/core/intent/AiIntentCategory.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntime.php';
require_once $root . '/core/intent/AiuGeminiUnderstandingClientStub.php';
require_once $root . '/core/intent/AiIntentUnderstandingRuntimeSelector.php';
require_once $root . '/core/conversation/ConversationOwner.php';
require_once $root . '/core/search/ClarificationPolicy.php';

$failures = 0;

function crc_assert(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");

        return;
    }
    echo "PASS: {$msg}\n";
}

function crc_expect_throw(callable $fn, string $expectedCode, string $label): void
{
    try {
        $fn();
        crc_assert(false, "{$label}: expected throw {$expectedCode}");
    } catch (\InvalidArgumentException $e) {
        crc_assert($e->getMessage() === $expectedCode, "{$label}: {$expectedCode}");
    }
}

$contract = AiuClarificationReasonContract::class;

// --- Closed vocabulary ---
crc_assert(
    AiuClarificationReasonContract::allowedAiuReasons() === [
        'missing_destination',
        'missing_travel_dates',
    ],
    'closed vocabulary exact'
);
crc_assert(
    AiuClarificationReasonContract::aiuToRuntimeMap() === [
        'missing_destination' => 'destination_unknown',
        'missing_travel_dates' => 'date_required',
    ],
    'explicit runtime mappings'
);

// --- Valid: missing_destination (Destination First; dates may be present) ---
AiuClarificationReasonContract::assertValidProductSearchClarification(
    true,
    'missing_destination',
    ['destination' => [], 'date_from' => '2026-08-01', 'date_to' => '2026-08-31']
);
crc_assert(true, 'valid missing_destination with dates present (Destination First)');

AiuClarificationReasonContract::assertValidProductSearchClarification(
    true,
    'missing_destination',
    ['destination' => []]
);
crc_assert(true, 'valid missing_destination both slots missing');

// --- Valid: missing_travel_dates ---
AiuClarificationReasonContract::assertValidProductSearchClarification(
    true,
    'missing_travel_dates',
    ['destination' => ['北海道'], 'date_from' => null, 'date_to' => null]
);
crc_assert(true, 'valid missing_travel_dates');

// --- Invalid: critical_slots_missing / freeform / null / Runtime aliases ---
crc_expect_throw(
    static function (): void {
        AiuClarificationReasonContract::assertValidProductSearchClarification(
            true,
            'critical_slots_missing',
            ['destination' => [], 'date_from' => '2026-08-01', 'date_to' => '2026-08-31']
        );
    },
    AiuClarificationReasonContract::FAILURE_UNSUPPORTED,
    'critical_slots_missing'
);

crc_expect_throw(
    static function (): void {
        AiuClarificationReasonContract::assertValidProductSearchClarification(
            true,
            '',
            ['destination' => ['東京']]
        );
    },
    AiuClarificationReasonContract::FAILURE_MISSING,
    'null/empty reason when required'
);

foreach (['date_required', 'destination_unknown', 'price_required', 'slots_vague'] as $alias) {
    crc_expect_throw(
        static function () use ($alias): void {
            AiuClarificationReasonContract::assertValidProductSearchClarification(
                true,
                $alias,
                ['destination' => ['東京']]
            );
        },
        AiuClarificationReasonContract::FAILURE_UNSUPPORTED,
        "runtime/freeform rejected: {$alias}"
    );
}

// --- Entity inconsistency ---
crc_expect_throw(
    static function (): void {
        AiuClarificationReasonContract::assertValidProductSearchClarification(
            true,
            'missing_destination',
            ['destination' => ['東京']]
        );
    },
    AiuClarificationReasonContract::FAILURE_ENTITY_INCONSISTENCY,
    'missing_destination with destination present'
);

crc_expect_throw(
    static function (): void {
        AiuClarificationReasonContract::assertValidProductSearchClarification(
            true,
            'missing_travel_dates',
            ['destination' => []]
        );
    },
    AiuClarificationReasonContract::FAILURE_ENTITY_INCONSISTENCY,
    'missing_travel_dates with destination missing (Destination First)'
);

crc_expect_throw(
    static function (): void {
        AiuClarificationReasonContract::assertValidProductSearchClarification(
            true,
            'missing_travel_dates',
            [
                'destination' => ['東京'],
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ]
        );
    },
    AiuClarificationReasonContract::FAILURE_ENTITY_INCONSISTENCY,
    'missing_travel_dates with complete dates'
);

// --- Not required: no validation ---
AiuClarificationReasonContract::assertValidProductSearchClarification(
    false,
    '',
    ['destination' => ['東京']]
);
crc_assert(true, 'not required allows empty reason');

// --- Prompt closed enum only ---
$builder = new AiuPromptBuilder();
$req = new AiuPromptRequest(
    'sno',
    'line',
    'utterance',
    [],
    ConversationOwner::AI,
    'active',
    null,
    new DateTimeImmutable('2026-07-14', new DateTimeZone('Asia/Taipei')),
    null,
    null,
    (new AiuProductSetContextResolver())->resolve('5f99b8d665e8444d')
);
$prompt = $builder->build($req);
crc_assert(strpos($prompt, 'missing_destination') !== false, 'prompt contains missing_destination');
crc_assert(strpos($prompt, 'missing_travel_dates') !== false, 'prompt contains missing_travel_dates');
crc_assert(strpos($prompt, 'Destination First') !== false, 'prompt Destination First');
crc_assert(strpos($prompt, 'critical_slots_missing') !== false, 'prompt forbids critical_slots_missing by name');
crc_assert(strpos($prompt, 'e.g. missing_travel_dates') === false, 'prompt no freeform e.g. reason list');
crc_assert(
    preg_match('/Set clarification\.reason to a short machine reason/u', $prompt) !== 1,
    'prompt no freeform short machine reason instruction'
);

// --- Normalizer: preserve valid; reject invalid; Ambiguous unchanged ---
$normalizer = new AiuSemanticJsonNormalizer();

$normDest = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => [
        'destination' => [],
        'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ],
    'confidence' => 0.8,
    'clarification' => ['required' => true, 'reason' => 'missing_destination'],
], '我想找8月從台北出發的行程');
crc_assert($normDest['clarification_reason'] === 'missing_destination', 'normalizer preserves missing_destination');
crc_assert($normDest['clarification_required'] === true, 'normalizer keeps required');

$normDates = $normalizer->normalize([
    'intent' => 'product_search',
    'entities' => ['destination' => ['北海道']],
    'confidence' => 0.5,
    'clarification' => ['required' => true, 'reason' => 'missing_travel_dates'],
], '北海道');
crc_assert($normDates['clarification_reason'] === 'missing_travel_dates', 'normalizer preserves missing_travel_dates');

crc_expect_throw(
    static function () use ($normalizer): void {
        $normalizer->normalize([
            'intent' => 'product_search',
            'entities' => [
                'destination' => [],
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ],
            'confidence' => 0.8,
            'clarification' => ['required' => true, 'reason' => 'critical_slots_missing'],
        ], '我想找8月從台北出發的行程');
    },
    AiuClarificationReasonContract::FAILURE_UNSUPPORTED,
    'normalizer rejects critical_slots_missing'
);

$normAmb = $normalizer->normalize([
    'intent' => 'ambiguous',
    'entities' => [],
    'confidence' => 0.4,
    'clarification' => ['required' => true, 'reason' => ''],
], '想出去玩');
crc_assert($normAmb['clarification_reason'] === 'intent_ambiguous', 'Ambiguous default intent_ambiguous');
crc_assert($normAmb['intent'] === AiIntentCategory::AMBIGUOUS, 'Ambiguous intent unchanged');

$normKnow = $normalizer->normalize([
    'intent' => 'knowledge',
    'entities' => [],
    'confidence' => 0.9,
    'clarification' => ['required' => false, 'reason' => ''],
], '護照');
crc_assert($normKnow['clarification_required'] === false, 'Knowledge clarification unchanged');

// --- Translator: map only; no entity fallback ---
$translator = new AiuProductIntentTranslator();

$aiuMissingDest = (new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH))
    ->setEntities(['destination' => [], 'date_from' => '2026-08-01', 'date_to' => '2026-08-31'])
    ->setClarification(true, 'missing_destination')
    ->setConfidence(0.8);
$batsDest = $translator->translate($aiuMissingDest);
crc_assert(
    $batsDest->getClarificationReason() === ClarificationPolicy::REASON_DESTINATION_UNKNOWN,
    'translator maps missing_destination → destination_unknown'
);

$aiuMissingDates = (new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH))
    ->setEntities(['destination' => ['北海道']])
    ->setClarification(true, 'missing_travel_dates')
    ->setConfidence(0.5);
$batsDates = $translator->translate($aiuMissingDates);
crc_assert(
    $batsDates->getClarificationReason() === ClarificationPolicy::REASON_DATE_REQUIRED,
    'translator maps missing_travel_dates → date_required'
);

crc_expect_throw(
    static function () use ($translator): void {
        $translator->translate(
            (new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH))
                ->setEntities(['destination' => []])
                ->setClarification(true, 'critical_slots_missing')
                ->setConfidence(0.5)
        );
    },
    AiuClarificationReasonContract::FAILURE_UNSUPPORTED,
    'translator no entity fallback for critical_slots_missing'
);

crc_expect_throw(
    static function () use ($translator): void {
        $translator->translate(
            (new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH))
                ->setEntities(['destination' => []])
                ->setClarification(true, '')
                ->setConfidence(0.5)
        );
    },
    AiuClarificationReasonContract::FAILURE_MISSING,
    'translator no residual default for empty reason'
);

crc_expect_throw(
    static function () use ($translator): void {
        $translator->translate(
            (new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH))
                ->setEntities(['destination' => ['東京']])
                ->setClarification(true, 'destination_unknown')
                ->setConfidence(0.5)
        );
    },
    AiuClarificationReasonContract::FAILURE_UNSUPPORTED,
    'translator rejects Runtime alias as AIU input'
);

// Ambiguous (non-Product): Product Search closed vocabulary does not apply.
$batsAmb = $translator->translate(
    (new AiIntentUnderstandingResult(AiIntentCategory::AMBIGUOUS))
        ->setEntities([])
        ->setClarification(true, 'intent_ambiguous')
        ->setConfidence(0.4)
);
crc_assert($batsAmb->isClarificationRequired() === false, 'ambiguous: product clarif fields cleared');
crc_assert($batsAmb->getClarificationReason() === null, 'ambiguous: no Product Search reason applied');

// Prove translator does not parse utterance (destination empty stays empty; reason mapped from AIU only).
$aiuNoUtteranceParse = (new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH))
    ->setEntities(['destination' => []])
    ->setClarification(true, 'missing_destination')
    ->setConfidence(0.7);
$batsNoParse = $translator->translate($aiuNoUtteranceParse);
crc_assert($batsNoParse->getDestination() === [], 'translator does not invent destination from utterance');
crc_assert(
    $batsNoParse->getClarificationReason() === ClarificationPolicy::REASON_DESTINATION_UNKNOWN,
    'translator reason from AIU map only'
);

// --- Fail-closed via Selector: Host B / Search never reached (invalid reason throws → fail_closed) ---
$invalidRuntime = AiIntentUnderstandingRuntime::createForTesting(
    new AiuGeminiUnderstandingClientStub(static function (): array {
        return [
            'intent' => 'product_search',
            'entities' => [
                'destination' => [],
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ],
            'confidence' => 0.8,
            'clarification' => ['required' => true, 'reason' => 'critical_slots_missing'],
        ];
    })
);
$selFail = AiIntentUnderstandingRuntimeSelector::resolve(
    [
        'tenant_sno' => '5f99b8d665e8444d',
        'conversation_id' => '5f99b8d665e8444d:line:U-crc',
        'message' => '我想找8月從台北出發的行程',
        'config' => [
            AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
            AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => ['5f99b8d665e8444d'],
        ],
    ],
    $invalidRuntime
);
crc_assert(
    ($selFail['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_FAIL_CLOSED,
    'invalid reason → selector fail_closed'
);
crc_assert(
    ($selFail['failure_reason'] ?? '') === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_RUNTIME,
    'invalid reason → AIU_RUNTIME_FAILURE path'
);

$validRuntime = AiIntentUnderstandingRuntime::createForTesting(
    new AiuGeminiUnderstandingClientStub(static function (): array {
        return [
            'intent' => 'product_search',
            'entities' => ['destination' => ['北海道']],
            'confidence' => 0.5,
            'clarification' => ['required' => true, 'reason' => 'missing_travel_dates'],
        ];
    })
);
$selOk = AiIntentUnderstandingRuntimeSelector::resolve(
    [
        'tenant_sno' => '5f99b8d665e8444d',
        'conversation_id' => '5f99b8d665e8444d:line:U-crc-ok',
        'message' => '北海道',
        'config' => [
            AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
            AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => ['5f99b8d665e8444d'],
        ],
    ],
    $validRuntime
);
crc_assert(
    ($selOk['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU,
    'valid reason → AIU source'
);
crc_assert(
    ($selOk['aiu_result'] ?? null) instanceof AiIntentUnderstandingResult
    && $selOk['aiu_result']->getClarificationReason() === 'missing_travel_dates',
    'valid reason reaches AIU result for Composer path'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_clarification_reason_contract\n");
    exit(1);
}
echo "ALL PASS test_aiu_clarification_reason_contract\n";
