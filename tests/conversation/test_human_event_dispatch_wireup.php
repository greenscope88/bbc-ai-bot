<?php

$convDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';

require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationEventIngestProbe.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationEventAdapter.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';

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
$userId = 'Uhuman01';
$cid = $sno . ':line:' . $userId;

function human_envelope(string $sno, string $userId, string $text, string $idem): array
{
    return [
        'tenant_sno' => $sno,
        'line_user_id' => $userId,
        'agent_id' => 'agent_001',
        'text' => $text,
        'sent_at' => '2026-06-28T15:00:00+08:00',
        'idempotency_key' => $idem,
    ];
}

$onConfig = [
    'conversation_human_event_parse_enabled' => true,
    'conversation_human_event_parse_tenant_snos' => [$sno],
    'conversation_human_event_dispatch_enabled' => true,
    'conversation_human_event_dispatch_tenant_snos' => [$sno],
];
$parseOnlyConfig = [
    'conversation_human_event_parse_enabled' => true,
    'conversation_human_event_parse_tenant_snos' => [$sno],
    'conversation_human_event_dispatch_enabled' => false,
];
$offConfig = [
    'conversation_human_event_parse_enabled' => false,
    'conversation_human_event_dispatch_enabled' => false,
];

// ============================================================================
// 1. Adapter: human_agent_message now dispatches to handleHumanAgentMessage
// ============================================================================
$facadeA = ConversationRuntimeFacade::createForTesting();
$adapterA = new ConversationEventAdapter();
$desc = [
    'type' => 'human_agent_message',
    'conversation_id' => $cid,
    'occurred_at' => '2026-06-28T15:00:00+08:00',
    'payload' => ['text' => '您好我來協助', 'agent_id' => 'agent_001'],
    'idempotency_key' => 'crm:msg:1',
];
$env = $adapterA->dispatchDescriptor($desc, $facadeA);
test_assert($env['dispatched_to_runtime'] === true, 'adapter: human dispatched_to_runtime true');
test_assert($env['reason'] === 'dispatched_human_agent_message', 'adapter: human dispatch reason');
test_assert(($env['runtime_result']['owner'] ?? '') === 'HUMAN', 'adapter: Owner=HUMAN after human dispatch');
test_assert($facadeA->mayAiReply($cid) === false, 'adapter: AI may not reply during human hold');

// ============================================================================
// 2. Adapter: customer_message route UNCHANGED
// ============================================================================
$facadeC = ConversationRuntimeFacade::createForTesting();
$adapterC = new ConversationEventAdapter();
$custDesc = [
    'type' => 'customer_message',
    'conversation_id' => $sno . ':line:Ucust',
    'occurred_at' => '2026-06-28T15:00:00+08:00',
    'payload' => ['text' => 'hi'],
    'idempotency_key' => 'line:dest:1',
];
$custEnv = $adapterC->dispatchDescriptor($custDesc, $facadeC);
test_assert($custEnv['reason'] === 'dispatched_customer_message', 'adapter: customer reason unchanged');
test_assert($custEnv['dispatched_to_runtime'] === true, 'adapter: customer still dispatched');
test_assert($facadeC->mayAiReply($sno . ':line:Ucust') === true, 'adapter: customer Owner stays AI');

// ============================================================================
// 3. runHuman: flags OFF -> not executed
// ============================================================================
$r = ConversationEventIngestProbe::runHuman([
    'tenant_sno' => $sno,
    'raw_envelope' => human_envelope($sno, $userId, 'hi', 'k1'),
    'config' => $offConfig,
]);
test_assert($r['executed'] === false, 'runHuman: disabled not executed');
test_assert($r['reason'] === 'human_parse_disabled_or_tenant_unmatched', 'runHuman: disabled reason');

// tenant mismatch
$r = ConversationEventIngestProbe::runHuman([
    'tenant_sno' => 'other',
    'raw_envelope' => human_envelope($sno, $userId, 'hi', 'k1'),
    'config' => $onConfig,
]);
test_assert($r['executed'] === false, 'runHuman: tenant mismatch not executed');

// ============================================================================
// 4. runHuman: parse-only -> parsed but not dispatched, runtime untouched
// ============================================================================
$facadeP = ConversationRuntimeFacade::createForTesting();
$r = ConversationEventIngestProbe::runHuman(
    [
        'tenant_sno' => $sno,
        'raw_envelope' => human_envelope($sno, $userId, '您好', 'kp'),
        'config' => $parseOnlyConfig,
    ],
    null,
    new ConversationEventAdapter(),
    $facadeP
);
test_assert($r['executed'] === true, 'runHuman parse-only: executed');
test_assert($r['dispatch_enabled'] === false, 'runHuman parse-only: dispatch off');
test_assert($r['parsed'] === 1 && $r['dispatched'] === 0, 'runHuman parse-only: parsed not dispatched');
test_assert($facadeP->state()->get($cid) === null, 'runHuman parse-only: state untouched');

// ============================================================================
// 5. runHuman: dispatch ON -> Human Takeover writes state
// ============================================================================
$facadeD = ConversationRuntimeFacade::createForTesting();
$r = ConversationEventIngestProbe::runHuman(
    [
        'tenant_sno' => $sno,
        'raw_envelope' => human_envelope($sno, $userId, '您好我來協助', 'kd'),
        'config' => $onConfig,
    ],
    null,
    new ConversationEventAdapter(),
    $facadeD
);
test_assert($r['executed'] === true && $r['dispatched'] === 1, 'runHuman dispatch: one dispatched');
test_assert($r['results'][0]['type'] === 'human_agent_message', 'runHuman dispatch: human type');
$state = $facadeD->state()->get($cid);
test_assert($state !== null && $state->getOwner() === 'HUMAN', 'runHuman dispatch: Owner=HUMAN');
test_assert($facadeD->mayAiReply($cid) === false, 'runHuman dispatch: AI blocked during hold');

// ============================================================================
// 6. runHuman: missing envelope -> not executed, never throws
// ============================================================================
$r = ConversationEventIngestProbe::runHuman(['tenant_sno' => $sno, 'config' => $onConfig]);
test_assert($r['executed'] === false && $r['reason'] === 'missing_raw_envelope', 'runHuman: missing envelope');

// never-throw with a throwing parser
$throwing = new class implements ConversationChannelParserInterface {
    public function channel(): string { return 'backend_human'; }
    public function parse(array $rawEnvelope, array $context): array { throw new \RuntimeException('boom'); }
};
$threw = false;
try {
    $r = ConversationEventIngestProbe::runHuman(
        ['tenant_sno' => $sno, 'raw_envelope' => human_envelope($sno, $userId, 'x', 'kx'), 'config' => $onConfig],
        $throwing
    );
} catch (\Throwable $e) {
    $threw = true;
}
test_assert(!$threw && $r['executed'] === false && $r['reason'] === 'exception', 'runHuman: never-throw');

// ============================================================================
// 7. Flag helpers independent of customer flags
// ============================================================================
$customerOnly = [
    'conversation_event_parse_enabled' => true,
    'conversation_event_parse_tenant_snos' => [$sno],
    'conversation_event_dispatch_enabled' => true,
    'conversation_event_dispatch_tenant_snos' => [$sno],
];
test_assert(ConversationEventIngestProbe::isParseEnabled($customerOnly, $sno) === true, 'flags: customer parse on');
test_assert(ConversationEventIngestProbe::isHumanParseEnabled($customerOnly, $sno) === false, 'flags: human parse independent (off)');
test_assert(ConversationEventIngestProbe::isHumanDispatchEnabled($customerOnly, $sno) === false, 'flags: human dispatch independent (off)');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_human_event_dispatch_wireup\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_human_event_dispatch_wireup\n");
exit(1);
