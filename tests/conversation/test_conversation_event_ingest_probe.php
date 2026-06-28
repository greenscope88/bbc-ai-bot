<?php

$convDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';

require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationEventIngestProbe.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationEventAdapter.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'event' . DIRECTORY_SEPARATOR . 'ConversationChannelParserInterface.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$sno = '5f99b8d665e8444d';

function build_line_envelope(string $userId, string $text, string $wevt, string $mode = 'active'): array
{
    return [
        'destination' => 'Udestination01',
        'events' => [[
            'type' => 'message',
            'mode' => $mode,
            'timestamp' => 1782000000000,
            'webhookEventId' => $wevt,
            'source' => ['type' => 'user', 'userId' => $userId],
            'message' => ['type' => 'text', 'id' => 'M-' . $wevt, 'text' => $text],
        ]],
    ];
}

$onConfig = [
    'conversation_event_parse_enabled' => true,
    'conversation_event_parse_tenant_snos' => [$sno],
    'conversation_event_dispatch_enabled' => true,
    'conversation_event_dispatch_tenant_snos' => [$sno],
];
$parseOnlyConfig = [
    'conversation_event_parse_enabled' => true,
    'conversation_event_parse_tenant_snos' => [$sno],
    'conversation_event_dispatch_enabled' => false,
    'conversation_event_dispatch_tenant_snos' => [$sno],
];
$offConfig = [
    'conversation_event_parse_enabled' => false,
    'conversation_event_dispatch_enabled' => false,
];

// ============================================================================
// flag helpers
// ============================================================================
test_assert(ConversationEventIngestProbe::isParseEnabled($onConfig, $sno), 'flag: parse on for tenant');
test_assert(!ConversationEventIngestProbe::isParseEnabled($onConfig, 'other'), 'flag: parse off for other tenant');
test_assert(!ConversationEventIngestProbe::isParseEnabled($offConfig, $sno), 'flag: parse off when disabled');
test_assert(ConversationEventIngestProbe::isDispatchEnabled($onConfig, $sno), 'flag: dispatch on for tenant');
test_assert(!ConversationEventIngestProbe::isDispatchEnabled($parseOnlyConfig, $sno), 'flag: dispatch off in parse-only');

// ============================================================================
// parse disabled -> not executed (behavior unchanged)
// ============================================================================
$r = ConversationEventIngestProbe::run([
    'tenant_sno' => $sno,
    'raw_envelope' => build_line_envelope('Uc1', 'hi', 'W1'),
    'config' => $offConfig,
]);
test_assert($r['executed'] === false, 'disabled: not executed');
test_assert($r['reason'] === 'parse_disabled_or_tenant_unmatched', 'disabled: reason');

// tenant mismatch -> not executed
$r = ConversationEventIngestProbe::run([
    'tenant_sno' => 'nope',
    'raw_envelope' => build_line_envelope('Uc1', 'hi', 'W1'),
    'config' => $onConfig,
]);
test_assert($r['executed'] === false, 'tenant mismatch: not executed');

// ============================================================================
// parse-only: parsed but never dispatched, runtime untouched
// ============================================================================
$facadeParseOnly = ConversationRuntimeFacade::createForTesting();
$logs = [];
$logger = function (string $step, array $ctx) use (&$logs): void {
    $logs[] = [$step, $ctx];
};
$r = ConversationEventIngestProbe::run(
    [
        'tenant_sno' => $sno,
        'raw_envelope' => build_line_envelope('Uc1', '東京五天', 'W1'),
        'config' => $parseOnlyConfig,
    ],
    null,
    new ConversationEventAdapter(),
    $facadeParseOnly,
    $logger
);
test_assert($r['executed'] === true, 'parse-only: executed');
test_assert($r['dispatch_enabled'] === false, 'parse-only: dispatch disabled');
test_assert($r['parsed'] === 1, 'parse-only: one parsed');
test_assert($r['dispatched'] === 0, 'parse-only: nothing dispatched');
test_assert($facadeParseOnly->memory()->get($sno . ':line:Uc1') === null, 'parse-only: runtime memory untouched');
test_assert(count($logs) === 1 && $logs[0][0] === 'conversation_event_ingest', 'parse-only: emitted ingest log');

// ============================================================================
// dispatch on: customer_message reaches the Facade (Owner stays AI)
// ============================================================================
$facade = ConversationRuntimeFacade::createForTesting();
$adapter = new ConversationEventAdapter();
$r = ConversationEventIngestProbe::run(
    [
        'tenant_sno' => $sno,
        'raw_envelope' => build_line_envelope('Uc2', '請問報名方式', 'W2'),
        'config' => $onConfig,
    ],
    null,
    $adapter,
    $facade
);
$cid = $sno . ':line:Uc2';
test_assert($r['executed'] === true, 'dispatch: executed');
test_assert($r['dispatch_enabled'] === true, 'dispatch: enabled');
test_assert($r['parsed'] === 1, 'dispatch: one parsed');
test_assert($r['dispatched'] === 1, 'dispatch: one dispatched');
test_assert($r['results'][0]['type'] === 'customer_message', 'dispatch: customer_message type');
test_assert($r['results'][0]['dispatched_to_runtime'] === true, 'dispatch: descriptor dispatched');
test_assert($facade->memory()->get($cid) !== null, 'dispatch: runtime memory written');
test_assert($facade->mayAiReply($cid) === true, 'dispatch: Owner stays AI (out of scope: human takeover)');

// ============================================================================
// idempotency: same adapter + same idempotency key -> not dispatched twice
// ============================================================================
$dupEnvelope = build_line_envelope('Uc3', 'first', 'WDUP');
// Two events sharing the same webhookEventId -> identical idempotency key.
$dupEnvelope['events'][] = $dupEnvelope['events'][0];
$facadeDup = ConversationRuntimeFacade::createForTesting();
$adapterDup = new ConversationEventAdapter();
$r = ConversationEventIngestProbe::run(
    [
        'tenant_sno' => $sno,
        'raw_envelope' => $dupEnvelope,
        'config' => $onConfig,
    ],
    null,
    $adapterDup,
    $facadeDup
);
test_assert($r['parsed'] === 2, 'idempotency: two parsed');
test_assert($r['dispatched'] === 1, 'idempotency: only first dispatched');
test_assert($r['results'][1]['duplicate'] === true, 'idempotency: second flagged duplicate');

// ============================================================================
// standby: parsed as customer_message (NEVER human_agent_message)
// ============================================================================
$facadeStandby = ConversationRuntimeFacade::createForTesting();
$r = ConversationEventIngestProbe::run(
    [
        'tenant_sno' => $sno,
        'raw_envelope' => build_line_envelope('Uc4', '還有位子嗎', 'W4', 'standby'),
        'config' => $onConfig,
    ],
    null,
    new ConversationEventAdapter(),
    $facadeStandby
);
test_assert($r['results'][0]['type'] === 'customer_message', 'standby: customer_message');
test_assert($r['results'][0]['type'] !== 'human_agent_message', 'standby: never human_agent_message');
test_assert($facadeStandby->mayAiReply($sno . ':line:Uc4') === true, 'standby: Owner stays AI');

// ============================================================================
// missing / empty raw envelope -> not executed, no throw
// ============================================================================
$r = ConversationEventIngestProbe::run(['tenant_sno' => $sno, 'config' => $onConfig]);
test_assert($r['executed'] === false && $r['reason'] === 'missing_raw_envelope', 'guard: missing envelope');

// unsupported channel -> not executed
$r = ConversationEventIngestProbe::run([
    'tenant_sno' => $sno,
    'channel' => 'telegram',
    'raw_envelope' => ['events' => []],
    'config' => $onConfig,
]);
test_assert($r['executed'] === false && $r['reason'] === 'unsupported_channel', 'guard: unsupported channel');

// ============================================================================
// never-throw: a throwing parser is swallowed
// ============================================================================
$throwingParser = new class implements ConversationChannelParserInterface {
    public function channel(): string
    {
        return 'line';
    }

    public function parse(array $rawEnvelope, array $context): array
    {
        throw new \RuntimeException('boom');
    }
};
$threw = false;
try {
    $r = ConversationEventIngestProbe::run(
        [
            'tenant_sno' => $sno,
            'raw_envelope' => build_line_envelope('Uc5', 'x', 'W5'),
            'config' => $onConfig,
        ],
        $throwingParser
    );
} catch (\Throwable $e) {
    $threw = true;
}
test_assert(!$threw, 'never-throw: parser exception swallowed');
test_assert($r['executed'] === false && $r['reason'] === 'exception', 'never-throw: exception reason');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_conversation_event_ingest_probe\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_conversation_event_ingest_probe\n");
exit(1);
