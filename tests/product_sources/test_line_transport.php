<?php
declare(strict_types=1);

/**
 * Phase 9-B-26B-3: LineTransport skeleton tests.
 *
 * LineMessagePayload → LineTransport → TransportResult (no HTTP).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . 'LineTransport.php';

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

function buildPayloadFromPlan(ChannelPublishPlan $plan): LineMessagePayload
{
    return (new LineRenderer())->renderPayload($plan);
}

$transport = new LineTransport();

// Case 1: reply payload
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $result = $transport->prepareReply($payload, 'reply-token-case-1', [
        'trace_id' => 'trace-case-1',
    ]);

    test_assert($result->isSuccess() === true, 'case1 success');
    test_assert($result->getTransportMode() === 'reply', 'case1 transport_mode reply');
    test_assert($result->getMessageCount() === 1, 'case1 one message');
    test_assert(true, 'case1 reply payload PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: push payload
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $result = $transport->preparePush($payload, [
        'trace_id' => 'trace-case-2',
    ]);

    test_assert($result->getTransportMode() === 'push', 'case2 transport_mode push');
    test_assert($result->getTraceId() === 'trace-case-2', 'case2 trace_id');
    test_assert(true, 'case2 push payload PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: multiple messages
try {
    $payload = buildPayloadFromPlan(buildLinePlan([
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
    ]));
    $result = $transport->prepareReply($payload, 'reply-token-case-3', [
        'trace_id' => 'trace-case-3',
    ]);

    test_assert($result->getMessageCount() === 2, 'case3 two messages');
    test_assert(true, 'case3 multiple messages PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case3 should pass: ' . $e->getMessage());
}

// Case 4: payload size
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $expectedSize = strlen(json_encode($payload->toArray(), JSON_UNESCAPED_UNICODE) ?: '');
    $result = $transport->prepareReply($payload, 'reply-token-case-4', [
        'trace_id' => 'trace-case-4',
    ]);

    test_assert($result->getPayloadSize() === $expectedSize, 'case4 payload_size matches json length');
    test_assert($result->getPayloadSize() > 0, 'case4 payload_size positive');
    test_assert(true, 'case4 payload size PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: trace id
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $result = $transport->preparePush($payload, [
        'trace_id' => 'custom-trace-5',
    ]);

    test_assert($result->getTraceId() === 'custom-trace-5', 'case5 custom trace_id');
    test_assert(true, 'case5 trace id PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case5 should pass: ' . $e->getMessage());
}

// Case 6: simulate send
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $replyResult = $transport->simulateSend($payload, [
        'transport_mode' => 'reply',
        'replyToken' => 'reply-token-case-6',
        'trace_id' => 'trace-case-6',
    ]);
    $pushResult = $transport->simulateSend($payload, [
        'transport_mode' => 'push',
        'trace_id' => 'trace-case-6-push',
    ]);

    test_assert($replyResult->getTransportMode() === 'reply', 'case6 simulate reply');
    test_assert($pushResult->getTransportMode() === 'push', 'case6 simulate push');
    test_assert(true, 'case6 simulate send PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: round trip
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $result = $transport->prepareReply($payload, 'reply-token-case-7', [
        'trace_id' => 'trace-case-7',
    ]);
    $roundTrip = TransportResult::fromArray($result->toArray())->toArray();
    $encoded = json_encode($roundTrip, JSON_UNESCAPED_UNICODE);

    test_assert($roundTrip === $result->toArray(), 'case7 round-trip');
    test_assert($encoded !== false, 'case7 json encodable');
    test_assert(!array_key_exists('replyToken', $roundTrip), 'case7 no replyToken in result');
    test_assert(!array_key_exists('curl', $roundTrip), 'case7 no curl key');
    test_assert(stripos((string) $encoded, 'api.line.me') === false, 'case7 no LINE API url');
    test_assert(true, 'case7 round trip PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_line_transport (all passed)\n");
exit(0);
