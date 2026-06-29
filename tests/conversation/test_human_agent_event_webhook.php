<?php

$convDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'conversation';

require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationEventIngestProbe.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationEventAdapter.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once $convDir . DIRECTORY_SEPARATOR . 'HumanAgentEventIngressHandler.php';

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
$userId = 'Uhuman_web01';
$cid = $sno . ':line:' . $userId;
$token = 'unit-test-secret-token';

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

function human_body(string $sno, string $userId, string $text, string $idem): string
{
    return (string) json_encode([
        'tenant_sno' => $sno,
        'line_user_id' => $userId,
        'agent_id' => 'agent_001',
        'text' => $text,
        'sent_at' => '2026-06-28T15:00:00+08:00',
        'idempotency_key' => $idem,
    ]);
}

/**
 * Build a request array with safe defaults (valid POST + JSON + auth).
 */
function req(array $overrides, string $token): array
{
    return array_merge([
        'method' => 'POST',
        'content_type' => 'application/json; charset=utf-8',
        'raw_body' => '{}',
        'provided_token' => $token,
        'expected_token' => $token,
        'trace_id' => 'test',
    ], $overrides);
}

// Ingest closure factory: route through runHuman with an injected test facade
// (and a shared adapter so idempotency persists across calls for dedup tests).
function ingest_with(ConversationRuntimeFacade $facade, ConversationEventAdapter $adapter): callable
{
    return static function (array $params) use ($facade, $adapter): array {
        return ConversationEventIngestProbe::runHuman($params, null, $adapter, $facade);
    };
}

// ============================================================================
// 1. Non-POST rejected
// ============================================================================
$r = HumanAgentEventIngressHandler::handle(req(['method' => 'GET'], $token));
test_assert($r['status'] === 405, 'non-POST: status 405');
test_assert($r['body']['ok'] === false && $r['body']['reason'] === 'method_not_allowed', 'non-POST: ok:false method_not_allowed');

// ============================================================================
// 2. Authentication
// ============================================================================
$r = HumanAgentEventIngressHandler::handle(req(['expected_token' => ''], $token));
test_assert($r['status'] === 503 && $r['body']['reason'] === 'auth_not_configured', 'auth: token not configured -> reject');

$r = HumanAgentEventIngressHandler::handle(req(['provided_token' => 'wrong'], $token));
test_assert($r['status'] === 401 && $r['body']['reason'] === 'unauthorized', 'auth: wrong token -> 401');

$r = HumanAgentEventIngressHandler::handle(req(['provided_token' => ''], $token));
test_assert($r['status'] === 401, 'auth: empty token -> 401');

// ============================================================================
// 3. Content-Type must be JSON
// ============================================================================
$r = HumanAgentEventIngressHandler::handle(req(['content_type' => 'text/plain'], $token));
test_assert($r['status'] === 415 && $r['body']['reason'] === 'unsupported_media_type', 'content-type: non-json -> 415');

// ============================================================================
// 4. Invalid / empty JSON
// ============================================================================
$r = HumanAgentEventIngressHandler::handle(req(['raw_body' => 'not-json'], $token));
test_assert($r['status'] === 400 && $r['body']['reason'] === 'invalid_json', 'json: invalid -> 400');

$r = HumanAgentEventIngressHandler::handle(req(['raw_body' => '   '], $token));
test_assert($r['status'] === 400 && $r['body']['reason'] === 'empty_body', 'json: empty -> 400');

// ============================================================================
// 5. Missing required fields
// ============================================================================
// Envelope-level: no tenant_sno -> validation failure (ok:false)
$r = HumanAgentEventIngressHandler::handle(req([
    'raw_body' => (string) json_encode(['line_user_id' => $userId, 'text' => 'hi']),
], $token));
test_assert($r['status'] === 400 && $r['body']['reason'] === 'missing_tenant_sno', 'fields: missing tenant_sno -> 400');

// Event-level: tenant present but text missing -> conservatively unparsable (accepted:false)
$facadeMF = ConversationRuntimeFacade::createForTesting();
$adapterMF = new ConversationEventAdapter();
$r = HumanAgentEventIngressHandler::handle(
    req([
        'raw_body' => (string) json_encode(['tenant_sno' => $sno, 'line_user_id' => $userId, 'agent_id' => 'a', 'idempotency_key' => 'mf1']),
        'config' => $onConfig,
    ], $token),
    ingest_with($facadeMF, $adapterMF)
);
test_assert($r['status'] === 200 && $r['body']['ok'] === true, 'fields: missing text -> ok:true');
test_assert($r['body']['accepted'] === false && $r['body']['reason'] === 'no_parsable_events', 'fields: missing text -> accepted:false no_parsable_events');

// ============================================================================
// 6. Flag OFF
// ============================================================================
$facadeOff = ConversationRuntimeFacade::createForTesting();
$adapterOff = new ConversationEventAdapter();
$r = HumanAgentEventIngressHandler::handle(
    req(['raw_body' => human_body($sno, $userId, '您好', 'off1'), 'config' => $offConfig], $token),
    ingest_with($facadeOff, $adapterOff)
);
test_assert($r['status'] === 200 && $r['body']['accepted'] === false, 'flag off: accepted:false');
test_assert($r['body']['reason'] === 'human_parse_disabled_or_tenant_unmatched', 'flag off: reason');
test_assert($facadeOff->state()->get($cid) === null, 'flag off: runtime untouched');

// ============================================================================
// 7. Parse-only (dispatch flag off)
// ============================================================================
$facadeP = ConversationRuntimeFacade::createForTesting();
$adapterP = new ConversationEventAdapter();
$r = HumanAgentEventIngressHandler::handle(
    req(['raw_body' => human_body($sno, $userId, '您好', 'po1'), 'config' => $parseOnlyConfig], $token),
    ingest_with($facadeP, $adapterP)
);
test_assert($r['status'] === 200 && $r['body']['accepted'] === false, 'parse-only: accepted:false');
test_assert($r['body']['reason'] === 'dispatch_disabled', 'parse-only: reason dispatch_disabled');
test_assert($facadeP->state()->get($cid) === null, 'parse-only: runtime untouched');

// ============================================================================
// 8. Flag ON -> POST JSON valid -> Human Takeover dispatched
// ============================================================================
$facadeOn = ConversationRuntimeFacade::createForTesting();
$adapterOn = new ConversationEventAdapter();
$ingestOn = ingest_with($facadeOn, $adapterOn);
$r = HumanAgentEventIngressHandler::handle(
    req(['raw_body' => human_body($sno, $userId, '您好我來協助', 'on1'), 'config' => $onConfig], $token),
    $ingestOn
);
test_assert($r['status'] === 200 && $r['body']['ok'] === true && $r['body']['accepted'] === true, 'flag on: accepted:true');
test_assert(!isset($r['body']['duplicate']), 'flag on: first call not marked duplicate');
$state = $facadeOn->state()->get($cid);
test_assert($state !== null && $state->getOwner() === 'HUMAN', 'flag on: Owner=HUMAN (takeover)');
test_assert($facadeOn->mayAiReply($cid) === false, 'flag on: AI blocked during human hold');

// ============================================================================
// 9. Duplicate event (same idempotency_key, same adapter)
// ============================================================================
$r = HumanAgentEventIngressHandler::handle(
    req(['raw_body' => human_body($sno, $userId, '您好我來協助', 'on1'), 'config' => $onConfig], $token),
    $ingestOn
);
test_assert($r['status'] === 200 && $r['body']['accepted'] === true, 'duplicate: accepted:true');
test_assert(($r['body']['duplicate'] ?? false) === true, 'duplicate: duplicate:true');

// ============================================================================
// 10. Customer route unaffected (adapter customer_message still AI owner)
// ============================================================================
$facadeC = ConversationRuntimeFacade::createForTesting();
$adapterC = new ConversationEventAdapter();
$custEnv = $adapterC->dispatchDescriptor([
    'type' => 'customer_message',
    'conversation_id' => $sno . ':line:Ucust_web',
    'occurred_at' => '2026-06-28T15:00:00+08:00',
    'payload' => ['text' => 'hi'],
    'idempotency_key' => 'cust:web:1',
], $facadeC);
test_assert($custEnv['reason'] === 'dispatched_customer_message', 'customer route: reason unchanged');
test_assert($facadeC->mayAiReply($sno . ':line:Ucust_web') === true, 'customer route: Owner stays AI');

// Human flags ON must not enable the customer event route.
test_assert(ConversationEventIngestProbe::isParseEnabled($onConfig, $sno) === false, 'customer route: human-on config does not enable customer parse');

// ============================================================================
// 11. never-throw: ingest closure throwing still returns structured 500
// ============================================================================
$threw = false;
try {
    $r = HumanAgentEventIngressHandler::handle(
        req(['raw_body' => human_body($sno, $userId, 'x', 'nt1'), 'config' => $onConfig], $token),
        static function (array $p): array { throw new \RuntimeException('boom'); }
    );
} catch (\Throwable $e) {
    $threw = true;
}
test_assert(!$threw, 'never-throw: handler did not throw');
test_assert($r['status'] === 500 && $r['body']['ok'] === false && $r['body']['reason'] === 'exception', 'never-throw: structured 500');

// ----------------------------------------------------------------------------
if ($failures === 0) {
    echo "ALL PASS test_human_agent_event_webhook\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_human_agent_event_webhook\n");
exit(1);
