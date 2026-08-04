<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-3-3 — AI Intent Understanding Runtime Selector tests (B0).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiRuntimeIntentTranslator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentContextLoader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiuPromptRequest.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
    . 'support' . DIRECTORY_SEPARATOR . 'AiuDestinationSemanticsTestFixtures.php';

$failures = 0;
$productionRegistry = new ConfigTenantRegistry();

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new \DateTimeZone('Asia/Taipei');
$now = new \DateTimeImmutable('2026-06-30 10:00:00', $tz);
$pilotSno = '5f99b8d665e8444d';
$travelDSno = '5fecdf66e9224bee';
$cid = $pilotSno . ':line:Uaiu-auth';
$dCid = $travelDSno . ':line:Uaiu-auth-d';

$flagOff = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => false,
    'tenant_registry' => $productionRegistry,
];
$flagOn = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    'tenant_registry' => $productionRegistry,
];
// Production membership is Registry status+features (travel_b + travel_d aiu_authoritative).
$flagOnWithTravelD = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    'tenant_registry' => $productionRegistry,
];

// Deterministic product fixture for selector mapping assertions (stub has no product default).
$selectorGeminiStub = new AiuGeminiUnderstandingClientStub(static function (AiuPromptRequest $request): array {
    $text = trim($request->getCustomerUtterance());
    if (mb_strpos($text, '東京', 0, 'UTF-8') !== false || mb_strpos($text, '自由行', 0, 'UTF-8') !== false) {
        return [
            'intent' => 'product_search',
            'entities' => AiuDestinationSemanticsTestFixtures::mergeEntities([
                'date_expression' => '3月',
                'search_keyword_tokens' => ['東京', '自由行'],
            ], ['東京']),
            'confidence' => 0.91,
            'clarification' => [
                'required' => false,
                'reason' => '',
            ],
        ];
    }

    return AiuGeminiUnderstandingClientStub::defaultResolver($request);
});

$runtimeFactory = static function () use ($selectorGeminiStub) {
    return AiIntentUnderstandingRuntime::createForTesting(
        $selectorGeminiStub,
        AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
    );
};

$params = static function (string $message, array $config) use ($pilotSno, $cid, $now): array {
    return [
        'tenant_sno' => $pilotSno,
        'conversation_id' => $cid,
        'message' => $message,
        'now' => $now,
        'reference_date' => $now,
        'config' => $config,
    ];
};

$resOff = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我想3月去東京自由行', $flagOff),
    $runtimeFactory()
);
test_assert($resOff['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_FAIL_CLOSED, 'flag off: fail_closed');
test_assert(
    ($resOff['failure_reason'] ?? '') === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_AUTHORITY_DISABLED,
    'flag off: authority disabled'
);
test_assert(count($resOff) === 2, 'flag off: only 2 fields');
test_assert(!array_key_exists('intent_type', $resOff), 'flag off: no intent_type');
test_assert(!array_key_exists('fallback_reason', $resOff), 'flag off: no fallback_reason');

$flagOnUnknownTenant = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    'tenant_registry' => $productionRegistry,
];
$resTenantMismatch = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => 'other-tenant-sno',
    'conversation_id' => 'other-tenant-sno:line:Uaiu-other',
    'message' => '我想3月去東京自由行',
    'now' => $now,
    'reference_date' => $now,
    'config' => $flagOnUnknownTenant,
], $runtimeFactory());
test_assert($resTenantMismatch['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_FAIL_CLOSED, 'unknown tenant: fail_closed');
test_assert(
    ($resTenantMismatch['failure_reason'] ?? '') === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_AUTHORITY_DISABLED,
    'unknown tenant: authority disabled'
);
test_assert(count($resTenantMismatch) === 2, 'unknown tenant: only 2 fields');
test_assert(!array_key_exists('intent_type', $resTenantMismatch), 'unknown tenant: no intent_type');
test_assert(!array_key_exists('legacy_intent_type', $resTenantMismatch), 'unknown tenant: no legacy_intent_type');
test_assert(!array_key_exists('fallback_reason', $resTenantMismatch), 'unknown tenant: no fallback_reason');
test_assert(!array_key_exists('aiu_result', $resTenantMismatch), 'unknown tenant: no aiu_result');

$resOnProduct = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我想3月去東京自由行', $flagOn),
    $runtimeFactory()
);
test_assert($resOnProduct['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU, 'flag on product: source aiu');
test_assert($resOnProduct['intent_type'] === AiRuntimeIntent::PRODUCT_SEARCH, 'flag on product: mapped product_search');
test_assert(!array_key_exists('dispatch_plan', $resOnProduct), 'flag on product: no dispatch_plan');
test_assert(!array_key_exists('execution_hint', $resOnProduct), 'flag on product: no execution_hint');
test_assert(
    ($resOnProduct['aiu_result'] ?? null) instanceof AiIntentUnderstandingResult,
    'flag on product: aiu_result present'
);
$productPresence = $resOnProduct['aiu_result']->getDatePipelineRawPresence();
test_assert(is_array($productPresence), 'flag on product: raw date presence attached');
test_assert(array_key_exists('raw_has_date_range', $productPresence), 'flag on product: raw_has_date_range key');
test_assert(array_key_exists('raw_has_date_from', $productPresence), 'flag on product: raw_has_date_from key');
test_assert(array_key_exists('raw_has_date_to', $productPresence), 'flag on product: raw_has_date_to key');
test_assert(array_key_exists('raw_has_date_expression', $productPresence), 'flag on product: raw_has_date_expression key');
test_assert(
    is_bool($productPresence['raw_has_date_range'])
        && is_bool($productPresence['raw_has_date_from'])
        && is_bool($productPresence['raw_has_date_to'])
        && is_bool($productPresence['raw_has_date_expression']),
    'flag on product: raw presence values are booleans'
);
test_assert(
    !array_key_exists('customer_message', $productPresence)
        && !array_key_exists('semantic_raw', $productPresence),
    'flag on product: presence bag has no utterance or raw payload'
);
test_assert(
    is_bool($resOnProduct['aiu_result']->isClarificationRequired())
        && is_string($resOnProduct['aiu_result']->getClarificationReason()),
    'flag on product: clarification fields readable without behavior change'
);

$resOnKnowledge = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('請問客服電話', $flagOn),
    $runtimeFactory()
);
test_assert($resOnKnowledge['intent_type'] === AiRuntimeIntent::KNOWLEDGE_QUERY, 'flag on knowledge: mapped knowledge_query');

$resOnAmbiguous = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我想出去玩', $flagOn),
    $runtimeFactory()
);
test_assert($resOnAmbiguous['intent_type'] === AiRuntimeIntent::AMBIGUOUS, 'flag on ambiguous: mapped ambiguous');

$humanFacade = ConversationRuntimeFacade::createForTesting();
$humanCid = $pilotSno . ':line:Uaiu-human';
$humanFacade->state()->recordHumanAgentMessage($humanCid, $now);
$humanRuntime = AiIntentUnderstandingRuntime::createForTesting(
    null,
    AiIntentContextLoader::createForTesting($humanFacade)
);
$resHuman = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => $pilotSno,
    'conversation_id' => $humanCid,
    'message' => '我想3月去東京自由行',
    'now' => $now,
    'reference_date' => $now,
    'config' => $flagOn,
], $humanRuntime);
test_assert($resHuman['human_blocked'] === true, 'human owner: human_blocked true');
test_assert(!array_key_exists('dispatch_plan', $resHuman), 'human owner: no dispatch_plan');

$productResult = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH);
test_assert(
    AiRuntimeIntentTranslator::fromUnderstandingResult($productResult)
        === AiRuntimeIntent::PRODUCT_SEARCH,
    'translator: product → product_search'
);

$clarProduct = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH)
    ->setClarification(true, 'missing_travel_dates');
test_assert(
    AiRuntimeIntentTranslator::fromUnderstandingResult($clarProduct)
        === AiRuntimeIntent::PRODUCT_SEARCH,
    'translator: product clarification → product_search'
);

$clarAmbiguous = AiIntentUnderstandingResult::create(AiIntentCategory::AMBIGUOUS)
    ->setClarification(true, 'intent_ambiguous');
test_assert(
    AiRuntimeIntentTranslator::fromUnderstandingResult($clarAmbiguous)
        === AiRuntimeIntent::AMBIGUOUS,
    'translator: ambiguous → ambiguous'
);

$humanServiceResult = AiIntentUnderstandingResult::create(AiIntentCategory::HUMAN_SERVICE);
test_assert(
    AiRuntimeIntentTranslator::fromUnderstandingResult($humanServiceResult)
        === AiRuntimeIntent::HUMAN_SERVICE_REQUEST,
    'translator: human_service → human_service_request'
);

$resHumanService = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我要找真人客服', $flagOn),
    $runtimeFactory()
);
test_assert(
    $resHumanService['intent_type'] === AiRuntimeIntent::HUMAN_SERVICE_REQUEST,
    'flag on human service: mapped human_service_request'
);

// Registry-driven AIU membership (status+features.aiu_authoritative).
test_assert(
    AiIntentUnderstandingRuntimeSelector::isAuthoritativeEnabled($flagOnWithTravelD, $travelDSno) === true,
    'travel_d registry feature: isAuthoritativeEnabled true'
);
test_assert(
    AiIntentUnderstandingRuntimeSelector::isAuthoritativeEnabled($flagOnWithTravelD, $pilotSno) === true,
    'travel_b registry feature: isAuthoritativeEnabled true'
);
test_assert(
    AiIntentUnderstandingRuntimeSelector::isAuthoritativeEnabled($flagOnWithTravelD, 'other-tenant-sno') === false,
    'unknown sno: isAuthoritativeEnabled false'
);

// resolve() for travel_d must pass the Authority gate (never AIU_AUTHORITY_DISABLED),
// even if the downstream runtime pipeline outcome depends on out-of-scope per-tenant
// product-source wiring not covered by this task.
$resTravelD = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => $travelDSno,
    'conversation_id' => $dCid,
    'message' => '我想3月去東京自由行',
    'now' => $now,
    'reference_date' => $now,
    'config' => $flagOnWithTravelD,
], $runtimeFactory());
test_assert(
    ($resTravelD['failure_reason'] ?? null) !== AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_AUTHORITY_DISABLED,
    'travel_d registry feature: resolve() not authority disabled'
);

$resOtherWithTravelD = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => 'other-tenant-sno',
    'conversation_id' => 'other-tenant-sno:line:Uaiu-other',
    'message' => '我想3月去東京自由行',
    'now' => $now,
    'reference_date' => $now,
    'config' => $flagOnWithTravelD,
], $runtimeFactory());
test_assert($resOtherWithTravelD['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_FAIL_CLOSED, 'unknown sno: fail_closed');
test_assert(
    ($resOtherWithTravelD['failure_reason'] ?? '') === AiIntentUnderstandingRuntimeSelector::FAILURE_REASON_AUTHORITY_DISABLED,
    'unknown sno: authority disabled'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_ai_intent_understanding_runtime_selector (all passed)\n");
exit(0);
