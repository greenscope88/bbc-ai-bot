<?php

$intentDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent';

require_once $intentDir . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// ============================================================================
// 1. Interface + implementation wiring (ABI)
// ============================================================================
test_assert(interface_exists('AiIntentUnderstandingRuntimeInterface'), 'ABI: interface exists');
test_assert(class_exists('AiIntentUnderstandingRuntime'), 'ABI: runtime class exists');

$runtime = new AiIntentUnderstandingRuntime();
test_assert($runtime instanceof AiIntentUnderstandingRuntimeInterface, 'ABI: runtime implements interface');
test_assert(is_string(AiIntentUnderstandingRuntime::PHASE) && AiIntentUnderstandingRuntime::PHASE !== '', 'ABI: phase marker present');

// ============================================================================
// 2. Method signature stability (ABI) via reflection
// ============================================================================
$ref = new ReflectionMethod('AiIntentUnderstandingRuntime', 'understand');
test_assert($ref->getNumberOfParameters() === 2, 'ABI: understand() has 2 params');

$params = $ref->getParameters();
test_assert($params[0]->getName() === 'customerMessage', 'ABI: param 0 customerMessage');
test_assert(
    $params[0]->hasType() && (string) $params[0]->getType() === 'string',
    'ABI: param 0 typed string'
);
test_assert($params[1]->getName() === 'context', 'ABI: param 1 context');
test_assert($params[1]->isOptional(), 'ABI: param 1 optional');

test_assert($ref->hasReturnType(), 'ABI: understand() has return type');
test_assert(
    (string) $ref->getReturnType() === 'AiIntentUnderstandingResult',
    'ABI: return type AiIntentUnderstandingResult'
);

// ============================================================================
// 3. understand() returns a contract object (B0 keys)
// ============================================================================
$runtimeT = AiIntentUnderstandingRuntime::createForTesting();
$out = $runtimeT->understand('hello', ['tenant_sno' => '5f99b8d665e8444d', 'trace_id' => 't1']);
test_assert($out instanceof AiIntentUnderstandingResult, 'logic: understand() returns AiIntentUnderstandingResult');
test_assert(array_keys($out->toArray()) === [
    'intent',
    'entities',
    'context_snapshot',
    'owner_snapshot',
    'conversation_stage',
    'resume_context',
    'clarification',
    'confidence',
], 'logic: result carries B0 contract keys');
test_assert(!array_key_exists('dispatch_plan', $out->toArray()), 'logic: no dispatch_plan');
test_assert(!array_key_exists('execution_hint', $out->toArray()), 'logic: no execution_hint');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_ai_intent_understanding_runtime_skeleton\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_ai_intent_understanding_runtime_skeleton\n");
exit(1);
