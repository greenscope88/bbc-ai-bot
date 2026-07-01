<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-3-3 — AI Intent Understanding Runtime Selector tests.
 *
 * Verifies Authoritative Switch capability: flag OFF → Legacy, flag ON → AIU mapping,
 * human_blocked, tenant scoping, config rollback, never-throw, and dispatch contract mapping.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'intent' . DIRECTORY_SEPARATOR . 'AiIntentContextLoader.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
    . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';

$failures = 0;

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
$cid = $pilotSno . ':line:Uaiu-auth';

$flagOff = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => false,
    AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => [$pilotSno],
];
$flagOn = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => [$pilotSno],
];

$runtimeFactory = static function () {
    return AiIntentUnderstandingRuntime::createForTesting(
        null,
        null,
        AiIntentContextLoader::createForTesting(ConversationRuntimeFacade::createForTesting())
    );
};

$params = static function (string $message, string $legacyIntent, array $config) use ($pilotSno, $cid, $now): array {
    return [
        'tenant_sno' => $pilotSno,
        'conversation_id' => $cid,
        'message' => $message,
        'legacy_intent_type' => $legacyIntent,
        'now' => $now,
        'reference_date' => $now,
        'config' => $config,
    ];
};

// --- isAuthoritativeEnabled ------------------------------------------------
test_assert(
    AiIntentUnderstandingRuntimeSelector::isAuthoritativeEnabled($flagOff, $pilotSno) === false,
    'isEnabled: flag off => false'
);
test_assert(
    AiIntentUnderstandingRuntimeSelector::isAuthoritativeEnabled($flagOn, $pilotSno) === true,
    'isEnabled: flag on + tenant match => true'
);
test_assert(
    AiIntentUnderstandingRuntimeSelector::isAuthoritativeEnabled($flagOn, 'other-sno') === false,
    'isEnabled: tenant mismatch => false'
);

// --- flag OFF → Legacy (byte-identical selection) --------------------------
$legacyProduct = KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH;
$resOff = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我想3月去東京自由行', $legacyProduct, $flagOff),
    $runtimeFactory()
);
test_assert($resOff['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_LEGACY, 'flag off: source legacy');
test_assert($resOff['intent_type'] === $legacyProduct, 'flag off: intent unchanged');
test_assert($resOff['human_blocked'] === false, 'flag off: not human blocked');
test_assert($resOff['legacy_intent_type'] === $legacyProduct, 'flag off: legacy preserved');

// --- flag ON → AIU drives routing (product) ----------------------------------
$resOnProduct = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我想3月去東京自由行', $legacyProduct, $flagOn),
    $runtimeFactory()
);
test_assert($resOnProduct['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU, 'flag on product: source aiu');
test_assert($resOnProduct['intent_type'] === KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH, 'flag on product: mapped product_search');
test_assert(isset($resOnProduct['dispatch_plan']), 'flag on product: dispatch_plan present');
test_assert($resOnProduct['human_blocked'] === false, 'flag on product: not blocked');

// --- flag ON → knowledge ---------------------------------------------------
$resOnKnowledge = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('請問客服電話', KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, $flagOn),
    $runtimeFactory()
);
test_assert($resOnKnowledge['intent_type'] === KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'flag on knowledge: mapped knowledge_query');
test_assert(($resOnKnowledge['dispatch_plan'] ?? '') === DispatchPlan::KNOWLEDGE, 'flag on knowledge: dispatch knowledge');

// --- flag ON → ambiguous → clarification -----------------------------------
$resOnAmbiguous = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我想出去玩', KnowledgeIntentDetector::INTENT_AMBIGUOUS, $flagOn),
    $runtimeFactory()
);
test_assert($resOnAmbiguous['intent_type'] === KnowledgeIntentDetector::INTENT_AMBIGUOUS, 'flag on ambiguous: mapped ambiguous');
test_assert(($resOnAmbiguous['dispatch_plan'] ?? '') === DispatchPlan::CLARIFICATION, 'flag on ambiguous: dispatch clarification');

// --- flag ON + Owner HUMAN → human_blocked ---------------------------------
$humanFacade = ConversationRuntimeFacade::createForTesting();
$humanCid = $pilotSno . ':line:Uaiu-human';
$humanFacade->state()->recordHumanAgentMessage($humanCid, $now);
$humanRuntime = AiIntentUnderstandingRuntime::createForTesting(
    null,
    null,
    AiIntentContextLoader::createForTesting($humanFacade)
);
$resHuman = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => $pilotSno,
    'conversation_id' => $humanCid,
    'message' => '我想3月去東京自由行',
    'legacy_intent_type' => $legacyProduct,
    'now' => $now,
    'reference_date' => $now,
    'config' => $flagOn,
], $humanRuntime);
test_assert($resHuman['human_blocked'] === true, 'human owner: human_blocked true');
test_assert($resHuman['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU, 'human owner: source aiu');
test_assert(($resHuman['dispatch_plan'] ?? '') === DispatchPlan::HUMAN, 'human owner: dispatch human');

// --- Config Rollback: ON then OFF → Legacy ---------------------------------
$resRollback = AiIntentUnderstandingRuntimeSelector::resolve(
    $params('我想3月去東京自由行', $legacyProduct, $flagOff),
    $runtimeFactory()
);
test_assert($resRollback['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_LEGACY, 'rollback: back to legacy');

// --- mapToLegacyIntentType contract ----------------------------------------
$productResult = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::PRODUCT);
test_assert(
    AiIntentUnderstandingRuntimeSelector::mapToLegacyIntentType($productResult)
        === KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH,
    'map: product → product_search'
);
$clarProduct = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH, DispatchPlan::CLARIFICATION);
test_assert(
    AiIntentUnderstandingRuntimeSelector::mapToLegacyIntentType($clarProduct)
        === KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH,
    'map: product clarification → product_search'
);
$clarAmbiguous = AiIntentUnderstandingResult::create(AiIntentCategory::AMBIGUOUS, DispatchPlan::CLARIFICATION);
test_assert(
    AiIntentUnderstandingRuntimeSelector::mapToLegacyIntentType($clarAmbiguous)
        === KnowledgeIntentDetector::INTENT_AMBIGUOUS,
    'map: ambiguous clarification → ambiguous'
);

// --- never-throw on garbage config -----------------------------------------
$garbage = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => $pilotSno,
    'conversation_id' => $cid,
    'message' => 'test',
    'legacy_intent_type' => $legacyProduct,
    'config' => ['not' => 'valid'],
]);
test_assert($garbage['runtime_source'] === AiIntentUnderstandingRuntimeSelector::SOURCE_LEGACY, 'garbage config: fallback legacy');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_ai_intent_understanding_runtime_selector (all passed)\n");
exit(0);
