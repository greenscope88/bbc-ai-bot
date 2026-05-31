<?php
declare(strict_types=1);

/**
 * Phase 9-B-20: Channel publish plan contract validation.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$validator = new ChannelPublishPlanValidator();

$sampleItem = [
    'title' => '東京五日遊',
    'summary' => '精選東京行程',
    'primary_url' => 'https://example.test/tokyo',
    'secondary_urls' => [],
    'actions' => [
        ['type' => 'open_url', 'label' => '查看', 'url' => 'https://example.test/tokyo'],
    ],
    'metadata' => ['price_from' => 28888],
];

// Case 1: valid line plan
try {
    $plan = $validator->validate([
        'channel' => 'line',
        'strategy_name' => 'line_oa_default_v1',
        'payload_schema_version' => 1,
        'items' => [$sampleItem],
        'fallback' => null,
        'metadata' => [],
    ]);
    test_assert($plan->getChannel() === 'line', 'case1 channel line');
    test_assert(count($plan->getItems()) === 1, 'case1 one item');
    test_assert(true, 'case1 valid line plan PASS');
} catch (\InvalidArgumentException $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: valid gemini plan
try {
    $plan = $validator->validate([
        'channel' => 'gemini',
        'strategy_name' => 'gemini_context_default_v1',
        'payload_schema_version' => 1,
        'items' => [$sampleItem],
        'fallback' => null,
        'metadata' => ['context_mode' => 'structured'],
    ]);
    test_assert($plan->getChannel() === 'gemini', 'case2 channel gemini');
    test_assert(true, 'case2 valid gemini plan PASS');
} catch (\InvalidArgumentException $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: valid telegram plan
try {
    $plan = $validator->validate([
        'channel' => 'telegram',
        'strategy_name' => 'telegram_markdown_default_v1',
        'payload_schema_version' => 1,
        'items' => [$sampleItem],
        'fallback' => null,
        'metadata' => [],
    ]);
    test_assert($plan->getChannel() === 'telegram', 'case3 channel telegram');
    test_assert(true, 'case3 valid telegram plan PASS');
} catch (\InvalidArgumentException $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: missing title
$violations = $validator->collectViolations([
    'channel' => 'line',
    'strategy_name' => 'line_oa_default_v1',
    'payload_schema_version' => 1,
    'items' => [
        ['primary_url' => 'https://example.test/missing-title'],
    ],
]);
test_assert(in_array('items[0].title is required', $violations, true), 'case4 missing title');

// Case 5: missing primary_url
$violations = $validator->collectViolations([
    'channel' => 'line',
    'strategy_name' => 'line_oa_default_v1',
    'payload_schema_version' => 1,
    'items' => [
        ['title' => '東京五日遊'],
    ],
]);
test_assert(in_array('items[0].primary_url is required', $violations, true), 'case5 missing primary_url');

// Case 6: invalid channel
$violations = $validator->collectViolations([
    'channel' => 'unknown_channel_xyz',
    'strategy_name' => 'test',
    'payload_schema_version' => 1,
    'items' => [$sampleItem],
]);
test_assert(in_array('invalid channel', $violations, true), 'case6 invalid channel');

// Case 7: fromArray / toArray round-trip
try {
    $document = [
        'channel' => 'web_chat',
        'strategy_name' => 'web_chat_card_default_v1',
        'payload_schema_version' => 1,
        'items' => [$sampleItem],
        'fallback' => ['type' => 'truncate_with_notice', 'message' => '前 10 筆'],
        'metadata' => ['layout' => 'card_list'],
    ];
    $plan = ChannelPublishPlan::fromArray($document);
    $roundTrip = $plan->toArray();
    test_assert($roundTrip['channel'] === 'web_chat', 'case7 round-trip channel');
    test_assert($roundTrip['items'][0]['title'] === '東京五日遊', 'case7 round-trip item title');
    $rebuilt = ChannelPublishPlan::fromArray($roundTrip);
    test_assert($rebuilt->toArray() === $roundTrip, 'case7 double round-trip');
    test_assert(true, 'case7 fromArray/toArray round-trip PASS');
} catch (\InvalidArgumentException $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_channel_publish_plan (all passed)\n");
exit(0);
