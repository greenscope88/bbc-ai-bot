<?php
declare(strict_types=1);

/**
 * Phase 9-B-25: LineSender contract tests.
 *
 * LineMessagePayload → LineSender → LineSenderResult (no HTTP).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlanValidator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineRenderer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'sender' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineSender.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'sender' . DIRECTORY_SEPARATOR . 'line' . DIRECTORY_SEPARATOR . 'LineSenderResultValidator.php';

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

$sender = new LineSender();
$resultValidator = new LineSenderResultValidator();

// Case 1: valid payload
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $result = $sender->prepare($payload, [
        'sender_type' => 'reply',
        'trace_id' => 'trace-case-1',
    ]);

    test_assert($result->isSuccess() === true, 'case1 success true');
    test_assert($result->toArray()['sender_type'] === 'reply', 'case1 sender_type reply');
    test_assert($result->getMessageCount() === 1, 'case1 one message');
    test_assert($result->toArray()['trace_id'] === 'trace-case-1', 'case1 trace_id');
    test_assert(true, 'case1 valid payload PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case1 should pass: ' . $e->getMessage());
}

// Case 2: multiple messages
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
    $result = $sender->prepare($payload, [
        'sender_type' => 'push',
        'trace_id' => 'trace-case-2',
    ]);

    test_assert($result->getMessageCount() === 2, 'case2 two messages');
    test_assert($result->toArray()['sender_type'] === 'push', 'case2 sender_type push');
    test_assert(true, 'case2 multiple messages PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case2 should pass: ' . $e->getMessage());
}

// Case 3: empty messages
try {
    $sender->prepareFromArray(['messages' => []], ['trace_id' => 'trace-case-3']);
    test_assert(false, 'case3 should reject empty messages');
} catch (\InvalidArgumentException $e) {
    test_assert(
        strpos($e->getMessage(), 'messages must not be empty') !== false
        || strpos($e->getMessage(), 'Line message payload invalid') !== false,
        'case3 empty messages rejected'
    );
    test_assert(true, 'case3 empty messages PASS');
}

// Case 4: payload size
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $payloadDocument = $payload->toArray();
    $expectedSize = strlen(json_encode($payloadDocument, JSON_UNESCAPED_UNICODE) ?: '');
    $result = $sender->prepare($payload, [
        'sender_type' => 'reply',
        'trace_id' => 'trace-case-4',
    ]);

    test_assert($result->getPayloadSize() === $expectedSize, 'case4 payload_size matches json length');
    test_assert($result->getPayloadSize() > 0, 'case4 payload_size positive');
    test_assert(true, 'case4 payload size PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case4 should pass: ' . $e->getMessage());
}

// Case 5: validator
$violations = $resultValidator->collectViolations([
    'success' => true,
    'sender_type' => 'invalid',
    'message_count' => -1,
    'payload_size' => -1,
    'trace_id' => '',
]);
test_assert(in_array('invalid sender_type', $violations, true), 'case5 invalid sender_type');
test_assert(in_array('message_count must be zero or positive', $violations, true), 'case5 message_count');
test_assert(in_array('trace_id is required', $violations, true), 'case5 trace_id required');

$forbiddenViolations = $resultValidator->collectViolations([
    'success' => true,
    'sender_type' => 'reply',
    'message_count' => 1,
    'payload_size' => 100,
    'trace_id' => 'trace-5',
    'http' => true,
    'curl' => 'enabled',
]);
test_assert(in_array('forbidden top-level key: http', $forbiddenViolations, true), 'case5 no http key');
test_assert(in_array('forbidden top-level key: curl', $forbiddenViolations, true), 'case5 no curl key');
test_assert(true, 'case5 validator PASS');

// Case 6: round trip
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $result = $sender->prepare($payload, [
        'sender_type' => 'reply',
        'trace_id' => 'trace-case-6',
    ]);
    $roundTrip = LineSenderResult::fromArray($result->toArray())->toArray();
    test_assert($roundTrip === $result->toArray(), 'case6 round-trip');
    test_assert(true, 'case6 round trip PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case6 should pass: ' . $e->getMessage());
}

// Case 7: no HTTP / curl / LINE SDK artifacts
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $result = $sender->prepare($payload, [
        'sender_type' => 'reply',
        'trace_id' => 'trace-case-7',
    ]);
    $document = $result->toArray();
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);

    test_assert($encoded !== false, 'case7 json encodable');
    test_assert(!array_key_exists('http', $document), 'case7 no http');
    test_assert(!array_key_exists('curl', $document), 'case7 no curl');
    test_assert(!array_key_exists('endpoint', $document), 'case7 no endpoint');
    test_assert(!array_key_exists('status_code', $document), 'case7 no status_code');
    test_assert(stripos((string) $encoded, 'curl_init') === false, 'case7 no curl_init string');
    test_assert(stripos((string) $encoded, 'api.line.me') === false, 'case7 no LINE API url');
    test_assert($resultValidator->collectViolations($document) === [], 'case7 passes validator');
    test_assert(true, 'case7 no transport artifacts PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case7 should pass: ' . $e->getMessage());
}

// Case 8: dry-run preview
try {
    $payload = buildPayloadFromPlan(buildLinePlan());
    $preview = $sender->prepareDryRun($payload, [
        'trace_id' => 'trace-case-8',
    ]);
    $encoded = json_encode($preview, JSON_UNESCAPED_UNICODE);

    test_assert(($preview['dry_run'] ?? false) === true, 'case8 dry_run true');
    test_assert(($preview['message_count'] ?? 0) === 1, 'case8 message_count');
    test_assert(($preview['payload_size'] ?? 0) > 0, 'case8 payload_size positive');
    test_assert(($preview['sender_type'] ?? '') === 'line_dry_run', 'case8 sender_type line_dry_run');
    test_assert(($preview['trace_id'] ?? '') === 'trace-case-8', 'case8 trace_id');
    test_assert(isset($preview['line_payload_preview']) && is_array($preview['line_payload_preview']), 'case8 line_payload_preview exists');
    test_assert(stripos((string) $encoded, 'accessToken') === false, 'case8 no accessToken');
    test_assert(stripos((string) $encoded, 'replyToken') === false, 'case8 no replyToken');
    test_assert(stripos((string) $encoded, 'endpoint') === false, 'case8 no endpoint');
    test_assert(stripos((string) $encoded, 'headers') === false, 'case8 no headers');
    test_assert(stripos((string) $encoded, 'curl') === false, 'case8 no curl');
    test_assert(true, 'case8 dry-run preview PASS');
} catch (\Throwable $e) {
    test_assert(false, 'case8 should pass: ' . $e->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_line_sender (all passed)\n");
exit(0);
