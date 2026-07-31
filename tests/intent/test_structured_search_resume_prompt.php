<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/intent/AiuPromptRequest.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuPromptBuilder.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuProductSetContext.php';
require_once dirname(__DIR__, 2) . '/core/intent/AiuProductSetContextResolver.php';
require_once dirname(__DIR__, 2) . '/core/conversation/ConversationOwner.php';

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$ref = new DateTimeImmutable('2026-07-16 10:00:00', new DateTimeZone('Asia/Taipei'));
$pending = [
    'schema_version' => 2,
    'conversation_id' => 't:line:oa:u',
    'state_version' => 1,
    'intent_scope' => 'product_search',
    'status' => 'WAITING_CLARIFICATION',
    'resume_reason' => 'aiu_product_clarification',
    'resume_trigger_source' => 'aiu_clarification',
    'asked_entity' => 'date',
    'response_route' => 'aiu_product_clarification',
    'known_entities' => [
        'destination' => ['日本'],
        'destination_relation' => null,
        'destination_semantics' => [],
        'departure' => null,
        'date_from' => null,
        'date_to' => null,
        'date_expression' => null,
        'duration_days' => null,
        'budget_amount' => null,
        'people_count' => null,
        'product_type' => null,
        'must_have' => [],
        'avoid' => [],
    ],
    'clarification_required' => true,
    'aiu_clarification_reason' => 'missing_travel_dates',
    'relation_capability_version' => '',
    'provenance_reference_timezone' => 'Asia/Taipei',
    'provenance_reference_calendar_date' => '2026-07-15',
];

$productSetContext = (new AiuProductSetContextResolver())->resolve('5f99b8d665e8444d');
$builder = new AiuPromptBuilder();
$with = new AiuPromptRequest(
    't',
    'line',
    '8月',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $ref,
    null,
    $pending,
    $productSetContext
);
$prompt = $builder->build($with);
assert_true(strpos($prompt, '[B-08b Structured Search Resume State') !== false, 'resume block present');
assert_true(strpos($prompt, '"destination":["日本"]') !== false || strpos($prompt, '日本') !== false, 'typed state injected');
assert_true(strpos($prompt, 'CURRENT REQUEST ANCHOR') !== false, 'current reference sole anchor label');
assert_true(strpos($prompt, 'provenance_reference_calendar_date') !== false, 'prior provenance present');
assert_true(strpos($prompt, 'resume_disposition') !== false, 'disposition in output schema');
assert_true(strpos($prompt, 'continue_pending') !== false, 'continue_pending instructed');
assert_true(strpos($prompt, 'You are the sole merge authority') !== false, 'gemini sole merge');
assert_true(strpos($prompt, 'asked_entity') !== false, 'asked_entity in prompt');

$without = new AiuPromptRequest(
    't',
    'line',
    '東京八月',
    [],
    ConversationOwner::AI,
    'active',
    null,
    $ref,
    null,
    null,
    $productSetContext
);
$cold = $builder->build($without);
assert_true(strpos($cold, 'No prior pending Product Search clarification state') !== false, 'cold null resume');
assert_true(strpos($cold, 'reference_calendar_date: 2026-07-16') !== false, 'current date in prompt');

if ($failures === 0) {
    echo "ALL PASS test_structured_search_resume_prompt\n";
    exit(0);
}
echo "FAILED {$failures}\n";
exit(1);
