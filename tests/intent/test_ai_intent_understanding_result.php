<?php

$intentDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function expect_throws(callable $fn, string $message): void
{
    $threw = false;
    try {
        $fn();
    } catch (\Throwable $e) {
        $threw = true;
    }
    test_assert($threw, $message);
}

test_assert(
    AiIntentCategory::all() === ['Product Search', 'Knowledge', 'Ambiguous', 'human_service'],
    'AiIntentCategory: frozen set includes human_service'
);

$r = new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH);
test_assert($r->getEntities() === [], 'default: entities empty array');

$r->setEntities(['destination' => ['Tokyo']])
    ->setOwnerSnapshot('HUMAN')
    ->setClarification(true, 'date_missing');

test_assert($r->getEntities() === ['destination' => ['Tokyo']], 'setter: entities');

expect_throws(static function () {
    new AiIntentUnderstandingResult('NotAnIntent');
}, 'guard: invalid intent throws');

$arr = $r->toArray();
$expectedKeys = [
    'intent',
    'entities',
    'context_snapshot',
    'owner_snapshot',
    'conversation_stage',
    'resume_context',
    'clarification',
    'confidence',
];
test_assert(array_keys($arr) === $expectedKeys, 'contract: toArray B0 keys in order');
test_assert(!array_key_exists('entity', $arr), 'contract: no entity alias in output');
test_assert(!array_key_exists('dispatch_plan', $arr), 'contract: no dispatch_plan');
test_assert(!array_key_exists('execution_hint', $arr), 'contract: no execution_hint');

$round = AiIntentUnderstandingResult::fromArray($arr);
test_assert($round->toArray() === $arr, 'round-trip: fromArray(toArray) equals');

if ($failures === 0) {
    echo "ALL PASS test_ai_intent_understanding_result\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_ai_intent_understanding_result\n");
exit(1);
