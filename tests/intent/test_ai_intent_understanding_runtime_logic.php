<?php

$root = dirname(__DIR__, 2);
$intentDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';
$convDir = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$sno = '5f99b8d665e8444d';
$cid = $sno . ':line:Uaiu01';

function runtime_with(ConversationRuntimeFacade $facade): AiIntentUnderstandingRuntime
{
    return AiIntentUnderstandingRuntime::createForTesting(
        null,
        AiIntentContextLoader::createForTesting($facade)
    );
}

$facade = ConversationRuntimeFacade::createForTesting();
$runtime = runtime_with($facade);
$r = $runtime->understand('我想3月去東京自由行', ['conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'product: intent category');
test_assert($r->isClarificationRequired() === false, 'product: no clarification');
test_assert(is_array($r->getEntities()['destination'] ?? null), 'product: entities.destination array');
test_assert(!array_key_exists('dispatch_plan', $r->toArray()), 'product: contract has no dispatch_plan');

$r = $runtime->understand('我想去東京自由行', ['conversation_id' => $cid]);
test_assert($r->isClarificationRequired() === true, 'product-clar: clarification required');

$r = $runtime->understand('請問你們的客服電話是多少', ['conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::KNOWLEDGE, 'knowledge: intent category');

$r = $runtime->understand('我想出去玩', ['conversation_id' => $cid]);
test_assert($r->getIntent() === AiIntentCategory::AMBIGUOUS, 'ambiguous: intent category');

$humanFacade = ConversationRuntimeFacade::createForTesting();
$now = new DateTimeImmutable('2026-06-30 13:00:00', $tz);
$humanFacade->state()->recordHumanAgentMessage($cid, $now);
$r = runtime_with($humanFacade)->understand('我想3月去東京自由行', [
    'conversation_id' => $cid,
    'now' => $now->modify('+1 minute'),
]);
test_assert($r->getOwnerSnapshot() === 'HUMAN', 'human: owner snapshot HUMAN');
test_assert($r->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'human: intent still understood');

$arr = $r->toArray();
test_assert(isset($arr['entities']) && !isset($arr['entity']), 'stability: entities only in contract');
test_assert(!array_key_exists('dispatch_plan', $arr), 'stability: no dispatch_plan in contract');
$round = AiIntentUnderstandingResult::fromArray($arr);
test_assert($round->toArray() === $arr, 'stability: round-trip equals');

if ($failures === 0) {
    echo "ALL PASS test_ai_intent_understanding_runtime_logic\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_ai_intent_understanding_runtime_logic\n");
exit(1);
