<?php
declare(strict_types=1);

/**
 * Phase 9-B-22: LineRenderer text MVP tests.
 *
 * ChannelPublishPlan → LineRenderer → LineMessagePayload
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayloadValidator.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function buildLinePlan(array $overrides = []): ChannelPublishPlan
{
    $document = array_merge([
        'channel' => 'line',
        'strategy_name' => 'line_oa_default_v1',
        'payload_schema_version' => 1,
        'items' => [
            [
                'title' => '東京五日',
                'summary' => '精選東京行程',
                'primary_url' => 'https://example.test/tokyo',
            ],
        ],
        'fallback' => null,
        'metadata' => [],
    ], $overrides);

    return (new ChannelPublishPlanValidator())->validate($document);
}

$renderer = new LineRenderer();
$payloadValidator = new LineMessagePayloadValidator();

// Case 1: valid line plan
try {
    $plan = buildLinePlan();
    $payload = $renderer->renderPayload($plan);
    $document = $payload->toArray();

    test_assert(isset($document['messages']) && is_array($document['messages']), 'case1 messages present');
    test_assert(count($document['messages']) === 1, 'case1 one message');
    test_assert($document['messages'][0]['type'] === 'text', 'case1 message type text');
    test_assert(strpos($document['messages'][0]['text'], '🚩 東京五日') === 0, 'case1 title in text');
    test_assert(strpos($document['messages'][0]['text'], 'https://example.test/tokyo') !== false, 'case1 url in text');
    test_assert(true, 'case1 valid line plan PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: 2 items → 2 messages
try {
    $plan = buildLinePlan([
        'items' => [
            [
                'title' => '東京五日',
                'summary' => 'summary 1',
                'primary_url' => 'https://example.test/tokyo',
            ],
            [
                'title' => '大阪三日',
                'summary' => 'summary 2',
                'primary_url' => 'https://example.test/osaka',
            ],
        ],
    ]);
    $payload = $renderer->renderPayload($plan);

    test_assert(count($payload->getMessages()) === 2, 'case2 two messages for two items');
    test_assert(strpos($payload->getMessages()[1]['text'], '🚩 大阪三日') === 0, 'case2 second item rendered');
    test_assert(true, 'case2 two items PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: fallback notice
try {
    $plan = buildLinePlan([
        'items' => [
            [
                'title' => '商品 A',
                'summary' => 'summary',
                'primary_url' => 'https://example.test/a',
            ],
        ],
        'fallback' => [
            'type' => 'truncate_with_notice',
            'message' => '僅顯示前 5 筆',
        ],
    ]);
    $payload = $renderer->renderPayload($plan);
    $messages = $payload->getMessages();

    test_assert(count($messages) === 2, 'case3 item message plus fallback notice');
    test_assert($messages[1]['text'] === '僅顯示前 5 筆', 'case3 fallback notice text');
    test_assert(true, 'case3 fallback notice PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: non-line channel
try {
    $plan = buildLinePlan(['channel' => 'gemini']);
    $renderer->renderPayload($plan);
    test_assert(false, 'case4 should reject non-line channel');
} catch (\InvalidArgumentException $e) {
    test_assert(strpos($e->getMessage(), 'channel=line') !== false, 'case4 non-line channel rejected');
    test_assert(true, 'case4 non-line channel PASS');
}

// Case 5: payload validator blocks flex/template
$flexViolations = $payloadValidator->collectViolations([
    'messages' => [
        [
            'type' => 'flex',
            'altText' => 'test',
            'contents' => ['type' => 'bubble', 'hero' => [], 'body' => [], 'footer' => []],
        ],
    ],
]);
test_assert(
    in_array('messages[0].type must be text', $flexViolations, true),
    'case5 flex type rejected'
);
test_assert(
    in_array('forbidden message type: messages.0.type', $flexViolations, true)
    || in_array('forbidden key: messages.0.contents.hero', $flexViolations, true),
    'case5 forbidden flex keys detected'
);

$templateViolations = $payloadValidator->collectViolations([
    'messages' => [
        [
            'type' => 'template',
            'altText' => 'test',
            'template' => ['type' => 'buttons', 'text' => 'hello'],
        ],
    ],
]);
test_assert(
    in_array('messages[0].type must be text', $templateViolations, true),
    'case5 template type rejected'
);
test_assert(true, 'case5 flex/template blocked PASS');

// Case 6: fromArray / toArray round trip
try {
    $plan = buildLinePlan();
    $payload = $renderer->renderPayload($plan);
    $roundTrip = LineMessagePayload::fromArray($payload->toArray())->toArray();
    test_assert($roundTrip === $payload->toArray(), 'case6 payload round-trip');
    test_assert(true, 'case6 fromArray/toArray PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: output must not contain replyToken / accessToken / endpoint / headers
try {
    $plan = buildLinePlan();
    $document = $renderer->render($plan);
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);

    test_assert($encoded !== false, 'case7 payload json encodable');
    test_assert(!array_key_exists('replyToken', $document), 'case7 no replyToken');
    test_assert(!array_key_exists('accessToken', $document), 'case7 no accessToken');
    test_assert(!array_key_exists('endpoint', $document), 'case7 no endpoint');
    test_assert(!array_key_exists('headers', $document), 'case7 no headers');
    test_assert(stripos((string) $encoded, 'replyToken') === false, 'case7 encoded no replyToken');
    test_assert(stripos((string) $encoded, 'accessToken') === false, 'case7 encoded no accessToken');
    test_assert($payloadValidator->collectViolations($document) === [], 'case7 payload passes validator');
    test_assert(true, 'case7 forbidden transport keys PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_line_renderer (all passed)\n");
exit(0);
