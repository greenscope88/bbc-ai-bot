<?php

$convDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';
$eventDir = $convDir . DIRECTORY_SEPARATOR . 'event';

require_once $eventDir . DIRECTORY_SEPARATOR . 'ConversationIdentityBuilder.php';
require_once $eventDir . DIRECTORY_SEPARATOR . 'ConversationChannelParserInterface.php';
require_once $eventDir . DIRECTORY_SEPARATOR . 'BackendHumanConversationChannelParser.php';
require_once $eventDir . DIRECTORY_SEPARATOR . 'ConversationEventNormalizer.php';
require_once $eventDir . DIRECTORY_SEPARATOR . 'LineConversationChannelParser.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationEventAdapter.php';

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
$userId = 'Ucustomer01';
$parser = new BackendHumanConversationChannelParser();

// ============================================================================
// Interface contract
// ============================================================================
test_assert($parser instanceof ConversationChannelParserInterface, 'contract: implements interface');
test_assert($parser->channel() === 'backend_human', 'contract: channel() = backend_human');

function human_event(array $overrides = []): array
{
    return array_merge([
        'tenant_sno' => '5f99b8d665e8444d',
        'line_user_id' => 'Ucustomer01',
        'agent_id' => 'agent_001',
        'text' => '您好，我來為您說明',
        'sent_at' => '2026-06-28T15:00:00+08:00',
        'idempotency_key' => 'crm:msg:12345',
    ], $overrides);
}

// ============================================================================
// Valid case (tenant_sno from context)
// ============================================================================
$descs = $parser->parse(human_event(['tenant_sno' => null]), ['tenant_sno' => $sno, 'trace_id' => 'tr-1']);
test_assert(count($descs) === 1, 'valid: one descriptor');
$d = $descs[0];
test_assert($d['type'] === 'human_agent_message', 'valid: type human_agent_message');
test_assert($d['conversation_id'] === $sno . ':line:' . $userId, 'valid: conversation_id via builder');
test_assert($d['payload']['text'] === '您好，我來為您說明', 'valid: text');
test_assert($d['payload']['agent_id'] === 'agent_001', 'valid: agent_id');
test_assert($d['channel'] === 'backend_human', 'valid: channel');
test_assert($d['idempotency_key'] === 'crm:msg:12345', 'valid: idempotency_key from contract');
test_assert($d['channel_event_id'] === 'crm:msg:12345', 'valid: channel_event_id');
test_assert($d['trace_id'] === 'tr-1', 'valid: trace_id');
test_assert(strpos((string) $d['occurred_at'], '2026-06-28T15:00:00') === 0, 'valid: occurred_at from sent_at');

// tenant_sno from envelope (context absent)
$descsEnv = $parser->parse(human_event(), []);
test_assert(count($descsEnv) === 1, 'valid: tenant_sno from envelope');
test_assert($descsEnv[0]['conversation_id'] === $sno . ':line:' . $userId, 'valid: envelope tenant identity');

// ============================================================================
// CEP compatibility: descriptor -> HumanAgentMessageEvent
// ============================================================================
$normalizer = new ConversationEventNormalizer();
$event = $normalizer->normalize($d);
test_assert($event instanceof HumanAgentMessageEvent, 'cep: normalizes to HumanAgentMessageEvent');
test_assert($event->getConversationId() === $sno . ':line:' . $userId, 'cep: conversation_id preserved');
test_assert($event->getText() === '您好，我來為您說明', 'cep: text preserved');
test_assert($event->getAgentId() === 'agent_001', 'cep: agent_id preserved');

// ============================================================================
// Identity parity: human event shares the SAME conversation_id as customer event
// ============================================================================
$lineParser = new LineConversationChannelParser();
$customerDescs = $lineParser->parse([
    'destination' => 'Udest',
    'events' => [[
        'type' => 'message', 'mode' => 'active', 'timestamp' => 1782000000000,
        'webhookEventId' => 'WV1',
        'source' => ['userId' => $userId],
        'message' => ['type' => 'text', 'id' => 'M1', 'text' => 'hi'],
    ]],
], ['tenant_sno' => $sno]);
test_assert(
    $customerDescs[0]['conversation_id'] === $d['conversation_id'],
    'identity: human + customer share one conversation_id (no second identity)'
);

// ============================================================================
// Missing field cases -> conservative skip (no throw)
// ============================================================================
test_assert($parser->parse(human_event(['tenant_sno' => null]), []) === [], 'missing: no tenant_sno -> skip');
test_assert($parser->parse(human_event(['line_user_id' => '']), []) === [], 'missing: line_user_id -> skip');
test_assert($parser->parse(human_event(['text' => '']), []) === [], 'missing: text -> skip');
test_assert($parser->parse(human_event(['text' => '   ']), []) === [], 'missing: blank text -> skip');

// agent_id missing is tolerated (not required for takeover trigger)
$noAgent = $parser->parse(human_event(['agent_id' => null]), []);
test_assert(count($noAgent) === 1, 'tolerant: missing agent_id still parses');
test_assert($noAgent[0]['payload']['agent_id'] === '', 'tolerant: agent_id defaults to empty');

// ============================================================================
// Invalid case -> conservative skip, never throw
// ============================================================================
$threw = false;
try {
    test_assert($parser->parse([], []) === [], 'invalid: empty envelope -> skip');
    test_assert($parser->parse(['events' => ['not-an-array', 123]], ['tenant_sno' => $sno]) === [], 'invalid: non-array events -> skip');
} catch (\Throwable $e) {
    $threw = true;
}
test_assert(!$threw, 'invalid: never throws');

// Missing idempotency_key -> derived stable key
$derived1 = $parser->parse(human_event(['idempotency_key' => null]), []);
$derived2 = $parser->parse(human_event(['idempotency_key' => '']), []);
test_assert(count($derived1) === 1, 'derive: produces descriptor without idempotency_key');
test_assert(strpos($derived1[0]['idempotency_key'], 'backend_human:') === 0, 'derive: key prefix');
test_assert(
    $derived1[0]['idempotency_key'] === $derived2[0]['idempotency_key'],
    'derive: same inputs -> same idempotency_key (stable)'
);

// ============================================================================
// Duplicate event -> adapter dedups by idempotency_key
// ============================================================================
$batch = ['events' => [human_event(), human_event()]]; // same idempotency_key twice
$dupDescs = $parser->parse($batch, []);
test_assert(count($dupDescs) === 2, 'duplicate: parser emits both (dedup is adapter job)');
test_assert(
    $dupDescs[0]['idempotency_key'] === $dupDescs[1]['idempotency_key'],
    'duplicate: identical idempotency_key'
);

$adapter = new ConversationEventAdapter();
$first = $adapter->acceptDescriptor($dupDescs[0]);
$second = $adapter->acceptDescriptor($dupDescs[1]);
test_assert($first['duplicate'] === false, 'duplicate: first accepted');
test_assert($second['duplicate'] === true, 'duplicate: second flagged duplicate by adapter');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_backend_human_conversation_channel_parser\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_backend_human_conversation_channel_parser\n");
exit(1);
