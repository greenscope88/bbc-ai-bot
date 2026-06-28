<?php

$eventDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'event';

require_once $eventDir . DIRECTORY_SEPARATOR . 'ConversationIdentityBuilder.php';
require_once $eventDir . DIRECTORY_SEPARATOR . 'ConversationChannelParserInterface.php';
require_once $eventDir . DIRECTORY_SEPARATOR . 'LineConversationChannelParser.php';
require_once $eventDir . DIRECTORY_SEPARATOR . 'ConversationEventNormalizer.php';

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

// ============================================================================
// ConversationIdentityBuilder
// ============================================================================
test_assert(
    ConversationIdentityBuilder::forLine($sno, 'U123') === $sno . ':line:U123',
    'identity: LINE format'
);
test_assert(
    ConversationIdentityBuilder::forWebChat($sno, 'sess-1') === $sno . ':web_chat:sess-1',
    'identity: web_chat format'
);
test_assert(
    ConversationIdentityBuilder::forCrm($sno, 'TKT-9') === $sno . ':crm:TKT-9',
    'identity: crm format'
);
test_assert(ConversationIdentityBuilder::isKnownChannel('line'), 'identity: line known');
test_assert(!ConversationIdentityBuilder::isKnownChannel('telegram'), 'identity: telegram not known (foundation)');

$idThrew = false;
try {
    ConversationIdentityBuilder::forLine($sno, '');
} catch (\InvalidArgumentException $e) {
    $idThrew = true;
}
test_assert($idThrew, 'identity: empty identifier throws');

$idThrew2 = false;
try {
    ConversationIdentityBuilder::build('', 'line', 'U1');
} catch (\InvalidArgumentException $e) {
    $idThrew2 = true;
}
test_assert($idThrew2, 'identity: empty tenant_sno throws');

// ============================================================================
// Interface contract
// ============================================================================
$parser = new LineConversationChannelParser();
test_assert($parser instanceof ConversationChannelParserInterface, 'contract: implements interface');
test_assert($parser->channel() === 'line', 'contract: channel() = line');

// ============================================================================
// LINE active customer message -> customer_message descriptor
// ============================================================================
$activeEnvelope = [
    'destination' => 'Udestination01',
    'events' => [
        [
            'type' => 'message',
            'mode' => 'active',
            'timestamp' => 1782000000000,
            'webhookEventId' => 'WEVT-1',
            'source' => ['type' => 'user', 'userId' => 'Ucustomer01'],
            'message' => ['type' => 'text', 'id' => 'MSG-1', 'text' => '東京七月行程'],
        ],
    ],
];
$activeDescs = $parser->parse($activeEnvelope, ['tenant_sno' => $sno, 'trace_id' => 'trace-1']);
test_assert(count($activeDescs) === 1, 'active: one descriptor');
$d = $activeDescs[0];
test_assert($d['type'] === 'customer_message', 'active: type customer_message');
test_assert($d['conversation_id'] === $sno . ':line:Ucustomer01', 'active: conversation_id via builder');
test_assert($d['payload']['text'] === '東京七月行程', 'active: text');
test_assert($d['payload']['line_mode'] === 'active', 'active: line_mode active');
test_assert($d['channel'] === 'line', 'active: channel line');
test_assert($d['channel_event_id'] === 'WEVT-1', 'active: channel_event_id');
test_assert($d['idempotency_key'] === 'line:Udestination01:WEVT-1', 'active: idempotency_key');
test_assert($d['trace_id'] === 'trace-1', 'active: trace_id');
test_assert(strpos((string) $d['occurred_at'], '2026') === 0, 'active: occurred_at from timestamp');

// CEP compatibility: descriptor normalizes into a Domain Event
$normalizer = new ConversationEventNormalizer();
$event = $normalizer->normalize($d);
test_assert($event instanceof CustomerMessageEvent, 'cep: normalizes to CustomerMessageEvent');
test_assert($event->getConversationId() === $sno . ':line:Ucustomer01', 'cep: conversation_id preserved');
test_assert($event->getText() === '東京七月行程', 'cep: text preserved');

// ============================================================================
// LINE standby message -> still customer_message (NEVER human_agent_message)
// ============================================================================
$standbyEnvelope = [
    'destination' => 'Udestination01',
    'events' => [
        [
            'type' => 'message',
            'mode' => 'standby',
            'timestamp' => 1782000100000,
            'webhookEventId' => 'WEVT-2',
            'source' => ['type' => 'user', 'userId' => 'Ucustomer01'],
            'message' => ['type' => 'text', 'id' => 'MSG-2', 'text' => '請問還有名額嗎'],
        ],
    ],
];
$standbyDescs = $parser->parse($standbyEnvelope, ['tenant_sno' => $sno]);
test_assert(count($standbyDescs) === 1, 'standby: one descriptor');
test_assert($standbyDescs[0]['type'] === 'customer_message', 'standby: conservative customer_message');
test_assert($standbyDescs[0]['type'] !== 'human_agent_message', 'standby: never human_agent_message');
test_assert($standbyDescs[0]['payload']['line_mode'] === 'standby', 'standby: line_mode hint captured');

// ============================================================================
// Conservative skips: unsupported / invalid events do not break or misclassify
// ============================================================================
$mixedEnvelope = [
    'destination' => 'Udestination01',
    'events' => [
        ['type' => 'follow', 'source' => ['userId' => 'Ux'], 'timestamp' => 1782000200000],
        ['type' => 'message', 'mode' => 'active', 'source' => ['userId' => 'Uy'],
            'message' => ['type' => 'sticker', 'id' => 'S1']],
        ['type' => 'message', 'mode' => 'active', 'timestamp' => 1782000300000,
            'source' => ['type' => 'user'], // missing userId
            'message' => ['type' => 'text', 'id' => 'M3', 'text' => 'no user id']],
        'not-an-array',
        ['type' => 'message', 'mode' => 'active', 'timestamp' => 1782000400000,
            'webhookEventId' => 'WEVT-9',
            'source' => ['userId' => 'Uok'],
            'message' => ['type' => 'text', 'id' => 'M4', 'text' => 'ok']],
    ],
];
$mixedDescs = $parser->parse($mixedEnvelope, ['tenant_sno' => $sno]);
test_assert(count($mixedDescs) === 1, 'mixed: only the valid text message produces a descriptor');
test_assert($mixedDescs[0]['payload']['text'] === 'ok', 'mixed: correct surviving descriptor');

// Empty / missing tenant_sno -> empty (no throw)
test_assert($parser->parse($activeEnvelope, []) === [], 'guard: missing tenant_sno -> empty');
test_assert($parser->parse(['events' => []], ['tenant_sno' => $sno]) === [], 'guard: no events -> empty');
test_assert($parser->parse([], ['tenant_sno' => $sno]) === [], 'guard: empty envelope -> empty');

// Fallback idempotency key uses message id when webhookEventId absent
$noWevt = [
    'destination' => 'Udestination01',
    'events' => [[
        'type' => 'message', 'mode' => 'active', 'timestamp' => 1782000500000,
        'source' => ['userId' => 'Uz'],
        'message' => ['type' => 'text', 'id' => 'MSG-FALLBACK', 'text' => 'hi'],
    ]],
];
$noWevtDescs = $parser->parse($noWevt, ['tenant_sno' => $sno]);
test_assert(
    $noWevtDescs[0]['idempotency_key'] === 'line:Udestination01:MSG-FALLBACK',
    'fallback: idempotency uses message id'
);

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_line_conversation_channel_parser\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_line_conversation_channel_parser\n");
exit(1);
