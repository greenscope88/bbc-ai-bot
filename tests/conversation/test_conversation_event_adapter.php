<?php

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'conversation'
    . DIRECTORY_SEPARATOR . 'ConversationEventAdapter.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new \DateTimeZone('Asia/Taipei');
$now = new \DateTimeImmutable('2026-06-28 10:00:00', $tz);
$adapter = new ConversationEventAdapter();
$normalizer = new ConversationEventNormalizer();

// --- ConversationEventType ---------------------------------------------------
test_assert(count(ConversationEventType::all()) === 3, 'type: 3 active types');
test_assert(ConversationEventType::isValid(ConversationEventType::CUSTOMER_MESSAGE), 'type: customer valid');
test_assert(!ConversationEventType::isValid('bogus'), 'type: bogus invalid');
test_assert(
    ConversationEventType::isReserved(ConversationEventType::RESERVED_CONVERSATION_CLOSE),
    'type: close is reserved'
);
$reservedThrew = false;
try {
    ConversationEventType::assertValid(ConversationEventType::RESERVED_CONVERSATION_CLOSE);
} catch (\InvalidArgumentException $e) {
    $reservedThrew = true;
}
test_assert($reservedThrew, 'type: reserved type throws on assertValid');

// --- Normalizer: CustomerMessageEvent ----------------------------------------
$customer = $normalizer->normalize([
    'type' => ConversationEventType::CUSTOMER_MESSAGE,
    'conversation_id' => 'conv-1',
    'occurred_at' => $now->format(\DateTimeInterface::ATOM),
    'payload' => ['text' => '你好'],
]);
test_assert($customer instanceof CustomerMessageEvent, 'normalizer: customer instance');
test_assert($customer->getType() === ConversationEventType::CUSTOMER_MESSAGE, 'normalizer: customer type');
test_assert($customer->getConversationId() === 'conv-1', 'normalizer: customer conv id');
test_assert($customer->getText() === '你好', 'normalizer: customer text');

// --- Normalizer: HumanAgentMessageEvent --------------------------------------
$human = $normalizer->normalize([
    'type' => ConversationEventType::HUMAN_AGENT_MESSAGE,
    'conversation_id' => 'conv-2',
    'occurred_at' => $now,
    'payload' => ['text' => '您好，我是客服', 'agent_id' => 'agent-007'],
]);
test_assert($human instanceof HumanAgentMessageEvent, 'normalizer: human instance');
test_assert($human->getAgentId() === 'agent-007', 'normalizer: human agent_id');
test_assert($human->getText() === '您好，我是客服', 'normalizer: human text');

// --- Normalizer: SystemEvent -------------------------------------------------
$system = $normalizer->normalize([
    'type' => ConversationEventType::SYSTEM,
    'conversation_id' => 'conv-3',
    'payload' => ['action' => 'timeout_check'],
]);
test_assert($system instanceof SystemEvent, 'normalizer: system instance');
test_assert($system->getAction() === 'timeout_check', 'normalizer: system action');

// --- Normalizer: validation errors -------------------------------------------
$missingConvThrew = false;
try {
    $normalizer->normalize([
        'type' => ConversationEventType::CUSTOMER_MESSAGE,
        'conversation_id' => '',
    ]);
} catch (\InvalidArgumentException $e) {
    $missingConvThrew = true;
}
test_assert($missingConvThrew, 'normalizer: empty conversation_id throws');

$badTypeThrew = false;
try {
    $normalizer->normalize([
        'type' => 'line_message',
        'conversation_id' => 'conv-x',
    ]);
} catch (\InvalidArgumentException $e) {
    $badTypeThrew = true;
}
test_assert($badTypeThrew, 'normalizer: external type name rejected (no LINE parsing)');

// --- Adapter: acceptDescriptor architecture-only envelope --------------------
$resCustomer = $adapter->acceptDescriptor([
    'type' => ConversationEventType::CUSTOMER_MESSAGE,
    'conversation_id' => 'conv-a',
    'payload' => ['text' => '查詢行程'],
]);
test_assert($resCustomer['accepted'] === true, 'adapter: customer accepted');
test_assert($resCustomer['dispatched_to_runtime'] === false, 'adapter: never dispatches in 4-D-1');
test_assert($resCustomer['duplicate'] === false, 'adapter: not duplicate');
test_assert(
    $resCustomer['reason'] === 'architecture_only_no_runtime_dispatch',
    'adapter: architecture-only reason'
);
test_assert(
    ($resCustomer['event']['type'] ?? '') === ConversationEventType::CUSTOMER_MESSAGE,
    'adapter: event type in envelope'
);

$resHuman = $adapter->acceptDescriptor([
    'type' => ConversationEventType::HUMAN_AGENT_MESSAGE,
    'conversation_id' => 'conv-b',
    'payload' => ['text' => '真人回覆', 'agent_id' => 'agent-1'],
]);
test_assert($resHuman['accepted'] === true, 'adapter: human accepted');
test_assert($resHuman['dispatched_to_runtime'] === false, 'adapter: human not dispatched');

// --- Adapter: direct accept() ------------------------------------------------
$directEvent = new SystemEvent('conv-c', $now, ['action' => 'policy_tick']);
$resDirect = $adapter->accept($directEvent);
test_assert($resDirect['accepted'] === true, 'adapter: direct accept');
test_assert($resDirect['dispatched_to_runtime'] === false, 'adapter: direct not dispatched');

// --- Adapter: idempotency architecture ---------------------------------------
$adapter->resetIdempotency();
$first = $adapter->acceptDescriptor([
    'type' => ConversationEventType::CUSTOMER_MESSAGE,
    'conversation_id' => 'conv-dup',
    'idempotency_key' => 'evt-key-001',
    'payload' => ['text' => 'once'],
]);
$second = $adapter->acceptDescriptor([
    'type' => ConversationEventType::CUSTOMER_MESSAGE,
    'conversation_id' => 'conv-dup',
    'idempotency_key' => 'evt-key-001',
    'payload' => ['text' => 'once'],
]);
test_assert($first['duplicate'] === false, 'idempotency: first not duplicate');
test_assert($second['duplicate'] === true, 'idempotency: second is duplicate');
test_assert($second['dispatched_to_runtime'] === false, 'idempotency: duplicate still not dispatched');

// --- toArray round-trip ------------------------------------------------------
$arr = $customer->toArray();
test_assert(($arr['conversation_id'] ?? '') === 'conv-1', 'toArray: conversation_id');
test_assert(isset($arr['occurred_at']) && $arr['occurred_at'] !== '', 'toArray: occurred_at');

// --- normalizeMany -----------------------------------------------------------
$many = $normalizer->normalizeMany([
    [
        'type' => ConversationEventType::CUSTOMER_MESSAGE,
        'conversation_id' => 'conv-m1',
        'payload' => ['text' => 'a'],
    ],
    [
        'type' => ConversationEventType::HUMAN_AGENT_MESSAGE,
        'conversation_id' => 'conv-m2',
        'payload' => ['text' => 'b'],
    ],
]);
test_assert(count($many) === 2, 'normalizeMany: count 2');
test_assert($many[0] instanceof CustomerMessageEvent, 'normalizeMany: first customer');
test_assert($many[1] instanceof HumanAgentMessageEvent, 'normalizeMany: second human');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_conversation_event_adapter\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_conversation_event_adapter\n");
exit(1);
