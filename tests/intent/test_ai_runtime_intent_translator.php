<?php
declare(strict_types=1);

/**
 * AiRuntimeIntent + AiRuntimeIntentTranslator — Contract tests (§12.7).
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntentTranslator.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';

$failures = 0;

function rt_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

// T-2: all() returns exactly four values
$all = AiRuntimeIntent::all();
rt_assert(count($all) === 4, 'all: four runtime intents');
rt_assert(in_array(AiRuntimeIntent::PRODUCT_SEARCH, $all, true), 'all: product_search');
rt_assert(in_array(AiRuntimeIntent::KNOWLEDGE_QUERY, $all, true), 'all: knowledge_query');
rt_assert(in_array(AiRuntimeIntent::AMBIGUOUS, $all, true), 'all: ambiguous');
rt_assert(in_array(AiRuntimeIntent::HUMAN_SERVICE_REQUEST, $all, true), 'all: human_service_request');

// T-3: isValid / assertValid
rt_assert(AiRuntimeIntent::isValid(AiRuntimeIntent::PRODUCT_SEARCH), 'isValid: product_search');
rt_assert(!AiRuntimeIntent::isValid('legacy_intent_type'), 'isValid: rejects legacy');
rt_assert(!AiRuntimeIntent::isValid('tour_query'), 'isValid: rejects tour_query');

try {
    AiRuntimeIntent::assertValid('invalid_runtime_intent');
    rt_assert(false, 'assertValid: must throw on invalid');
} catch (\InvalidArgumentException $e) {
    rt_assert(strpos($e->getMessage(), 'invalid ai runtime intent') !== false, 'assertValid: exception message');
}

// T-1: Frozen Matrix mappings
$matrix = [
    [AiIntentCategory::PRODUCT_SEARCH, AiRuntimeIntent::PRODUCT_SEARCH],
    [AiIntentCategory::KNOWLEDGE, AiRuntimeIntent::KNOWLEDGE_QUERY],
    [AiIntentCategory::AMBIGUOUS, AiRuntimeIntent::AMBIGUOUS],
    [AiIntentCategory::HUMAN_SERVICE, AiRuntimeIntent::HUMAN_SERVICE_REQUEST],
];
foreach ($matrix as [$category, $expected]) {
    $result = AiIntentUnderstandingResult::create($category);
    $mapped = AiRuntimeIntentTranslator::fromUnderstandingResult($result);
    rt_assert($mapped === $expected, "map: {$category} → {$expected}");
}

// T-4: clarification does not change Runtime Intent
$clarProduct = AiIntentUnderstandingResult::create(AiIntentCategory::PRODUCT_SEARCH)
    ->setClarification(true, 'missing_travel_dates');
rt_assert(
    AiRuntimeIntentTranslator::fromUnderstandingResult($clarProduct) === AiRuntimeIntent::PRODUCT_SEARCH,
    'clarification: product_search unchanged'
);

// T-6: invalid category → exception (defensive path via reflection-free invalid string in Result)
$invalidResult = new AiIntentUnderstandingResult(AiIntentCategory::PRODUCT_SEARCH);
$ref = new ReflectionClass($invalidResult);
$prop = $ref->getProperty('intent');
$prop->setAccessible(true);
$prop->setValue($invalidResult, 'UnsupportedCategory');
try {
    AiRuntimeIntentTranslator::fromUnderstandingResult($invalidResult);
    rt_assert(false, 'invalid category: must throw');
} catch (\InvalidArgumentException $e) {
    rt_assert(strpos($e->getMessage(), 'unsupported ai intent category') !== false, 'invalid category: exception');
}

// T-5: Translator has no utterance parameter (signature check)
$refTranslator = new ReflectionMethod(AiRuntimeIntentTranslator::class, 'fromUnderstandingResult');
rt_assert($refTranslator->getNumberOfParameters() === 1, 'translator: single parameter only');

// T-7: mapToLegacyIntentType must not exist
rt_assert(
    !method_exists(AiIntentUnderstandingRuntimeSelector::class, 'mapToLegacyIntentType'),
    'no mapToLegacyIntentType on Selector'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_ai_runtime_intent_translator (all passed)\n");
exit(0);
