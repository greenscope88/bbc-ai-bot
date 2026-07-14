<?php
declare(strict_types=1);

/**
 * Phase 9-C-1b: GeminiContextDocument schema_version=2 + bats_search_intent tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'gemini' . DIRECTORY_SEPARATOR . 'GeminiContextDocumentValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function baseVoiceProfile(): array
{
    return [
        'persona' => 'young_female',
        'tone' => ['warm', 'professional'],
        'constraints' => ['不誇大'],
        'emoji_policy' => ['allowed_emojis' => ['😊']],
    ];
}

function basePolicies(): array
{
    return [
        'guard_policy' => [
            'grounding_required' => true,
            'allow_hallucination' => false,
            'strict_data_mode' => true,
        ],
        'fallback_policy' => [
            'mode' => 'human_agent',
            'fallback_message' => '請稍候，專人客服將協助您。',
        ],
    ];
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function baseContextDocument(array $overrides = []): array
{
    return array_merge([
        'schema_version' => GeminiContextDocument::SCHEMA_VERSION,
        'customer_query' => '請推薦東京五日團',
        'search_results' => [],
        'tenant_name' => 'BBC Travel',
        'voice_profile' => baseVoiceProfile(),
        'tenant_service_scope' => ['tour'],
    ], basePolicies(), $overrides);
}

function buildIntentFromQuery(string $query): array
{
    if ($query === '北海道7月') {
        return (new BatsSearchIntent(
            $query,
            BatsSearchIntent::INTENT_TOUR_SEARCH,
            ['北海道'],
            [],
            null,
            '2026-07-01',
            '2026-07-31'
        ))->toArray();
    }

    if ($query === '北海道') {
        return (new BatsSearchIntent(
            $query,
            BatsSearchIntent::INTENT_TOUR_SEARCH,
            ['北海道'],
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
            [],
            true,
            ClarificationPolicy::REASON_DATE_REQUIRED
        ))->toArray();
    }

    if ($query === '東京7月') {
        return (new BatsSearchIntent(
            $query,
            BatsSearchIntent::INTENT_TOUR_SEARCH,
            ['東京'],
            [],
            null,
            '2026-07-01',
            '2026-07-31'
        ))->toArray();
    }

    throw new InvalidArgumentException('fixture intent only: ' . $query);
}

$validator = new GeminiContextDocumentValidator();

// Case A: schema_version=2 with bats_search_intent — PASS
try {
    $intent = buildIntentFromQuery('北海道7月');
    $document = baseContextDocument([
        'schema_version' => GeminiContextDocument::SCHEMA_VERSION_V2,
        'bats_search_intent' => $intent,
        'search_results' => [
            [
                'title' => '北海道夏季團',
                'summary' => '7月出發',
                'primary_url' => 'https://example.test/hokkaido',
            ],
        ],
    ]);

    $violations = $validator->collectViolations($document);
    test_assert($violations === [], 'caseA validator passes: ' . implode('; ', $violations));

    $context = $validator->validate($document);
    test_assert($context->getSchemaVersion() === GeminiContextDocument::SCHEMA_VERSION_V2, 'caseA schema_version=2');
    test_assert($context->getBatsSearchIntent() !== null, 'caseA bats_search_intent present');
    test_assert($context->getBatsSearchIntent()['destination'] === ['北海道'], 'caseA destination');
    test_assert(array_key_exists('bats_search_intent', $context->toArray()), 'caseA toArray includes bats_search_intent');
    test_assert(true, 'caseA schema_version=2 with bats_search_intent PASS');
} catch (\Throwable $e) {
    test_assert(false, 'caseA should pass: ' . $e->getMessage());
}

// Case B: schema_version=2 without bats_search_intent — FAIL
$missingIntentDoc = baseContextDocument([
    'schema_version' => GeminiContextDocument::SCHEMA_VERSION_V2,
]);
$missingIntentViolations = $validator->collectViolations($missingIntentDoc);
test_assert(
    in_array('bats_search_intent is required for schema_version=2', $missingIntentViolations, true),
    'caseB bats_search_intent required'
);
try {
    $validator->validate($missingIntentDoc);
    test_assert(false, 'caseB should throw');
} catch (\InvalidArgumentException $e) {
    test_assert(true, 'caseB schema_version=2 missing bats_search_intent FAIL');
}

// Case C: schema_version=1 backward compatibility — PASS
try {
    $v1Document = baseContextDocument([
        'schema_version' => GeminiContextDocument::SCHEMA_VERSION,
    ]);
    $v1Violations = $validator->collectViolations($v1Document);
    test_assert($v1Violations === [], 'caseC v1 validator passes: ' . implode('; ', $v1Violations));

    $v1Context = $validator->validate($v1Document);
    test_assert($v1Context->getSchemaVersion() === GeminiContextDocument::SCHEMA_VERSION, 'caseC schema_version=1');
    test_assert($v1Context->getBatsSearchIntent() === null, 'caseC no bats_search_intent');
    test_assert(!array_key_exists('bats_search_intent', $v1Context->toArray()), 'caseC toArray omits bats_search_intent');
    test_assert(true, 'caseC schema_version=1 backward compatibility PASS');
} catch (\Throwable $e) {
    test_assert(false, 'caseC should pass: ' . $e->getMessage());
}

// Case D: clarification_required=true + empty search_results — PASS under schema_version=2
try {
    $clarifyIntent = buildIntentFromQuery('北海道');
    test_assert($clarifyIntent['clarification_required'] === true, 'caseD intent requires clarification');

    $clarifyDocument = baseContextDocument([
        'schema_version' => GeminiContextDocument::SCHEMA_VERSION_V2,
        'bats_search_intent' => $clarifyIntent,
        'search_results' => [],
    ]);
    $clarifyViolations = $validator->collectViolations($clarifyDocument);
    test_assert($clarifyViolations === [], 'caseD validator accepts empty search_results: ' . implode('; ', $clarifyViolations));

    $clarifyContext = $validator->validate($clarifyDocument);
    test_assert($clarifyContext->getSearchResults() === [], 'caseD empty search_results');
    test_assert($clarifyContext->getBatsSearchIntent()['clarification_required'] === true, 'caseD clarification_required');
    test_assert(true, 'caseD clarification_required with empty search_results PASS');
} catch (\Throwable $e) {
    test_assert(false, 'caseD should pass: ' . $e->getMessage());
}

// Case E: GeminiRenderer can build schema_version=2 document
try {
    $plan = (new ChannelPublishPlanValidator())->validate([
        'channel' => 'gemini',
        'strategy_name' => 'gemini_context_default_v1',
        'payload_schema_version' => 1,
        'items' => [],
        'fallback' => null,
        'metadata' => [],
    ]);
    $intent = buildIntentFromQuery('東京7月');
    $renderer = new GeminiRenderer();
    $rendered = $renderer->renderDocument($plan, [
        'schema_version' => GeminiContextDocument::SCHEMA_VERSION_V2,
        'customer_query' => '東京7月',
        'tenant_name' => 'BBC Travel',
        'bats_search_intent' => $intent,
    ]);

    test_assert($rendered->getSchemaVersion() === GeminiContextDocument::SCHEMA_VERSION_V2, 'caseE renderer schema_version=2');
    test_assert($rendered->getBatsSearchIntent() !== null, 'caseE renderer bats_search_intent');
    test_assert($rendered->getBatsSearchIntent()['destination'] === ['東京'], 'caseE renderer destination');
    test_assert($validator->collectViolations($rendered->toArray()) === [], 'caseE rendered document validates');
    test_assert(true, 'caseE renderer builds schema_version=2 PASS');
} catch (\Throwable $e) {
    test_assert(false, 'caseE should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_gemini_context_document_v2 (all passed)\n");
exit(0);
