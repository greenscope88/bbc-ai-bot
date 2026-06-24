<?php
declare(strict_types=1);

/**
 * Phase 9-C-1d-β1: SaaSRouter structured pilot path tests (no live HTTP / DB).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'Phase9C1FeatureGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ConversationStatusResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'AcknowledgementReplyComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';

$failures = 0;

function test_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$travelBSno = Phase9C1FeatureGate::TRAVEL_B_SNO;
$tenantTravelB = [
    'sno' => $travelBSno,
    'company_name' => 'Travel B Pilot',
    'channel_id' => 'travel-b-channel',
    'tenant_key' => 'travel_b',
];
$otherTenant = [
    'sno' => 'e1fd133c7e8e45a1',
    'company_name' => 'Staging Tenant',
    'channel_id' => 'staging-channel',
];
$referenceDate = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$pilotUserId = 'U-pilot-test-user';
$waitingText = AcknowledgementReplyComposer::composeProductWaitingReply();

$mockLineSender = static function (string $url, string $token, string $replyToken, string $text): array {
    unset($url, $token);
    return [
        'status' => 200,
        'reply_token' => $replyToken,
        'text_length' => mb_strlen($text),
        'mock' => true,
        'kind' => 'final',
    ];
};

function buildPilotMockSearchClient(string $title = '北海道夏季團'): TourSearchApiClient
{
    return new TourSearchApiClient('https://example.test/tour/search', 5, static function () use ($title): array {
        $body = json_encode([
            'success' => true,
            'pagination' => ['total' => 1],
            'items' => [
                ['title' => $title, 'tourDate' => '2026-07-10', 'price' => 39900],
            ],
            'search_url' => 'https://example.test/search',
            'error' => null,
        ], JSON_UNESCAPED_UNICODE);

        return [
            'ok' => true,
            'http_status' => 200,
            'body' => $body !== false ? $body : '',
            'transport_error' => null,
        ];
    });
}

// Case 1: gate false → path returns null (legacy unchanged)
$resultOff = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $otherTenant,
    'BATS測試北海道',
    'trace-pilot-1',
    'reply-token-1',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-1',
    'staging-channel',
    null,
    buildPilotMockSearchClient(),
    $referenceDate,
    $mockLineSender
);
test_assert($resultOff === null, 'case1: gate false returns null');

$decisionOff = Phase9C1FeatureGate::evaluate([
    'sno' => $otherTenant['sno'],
    'userMessage' => 'BATS測試北海道',
]);
test_assert(($decisionOff['enabled'] ?? false) === false, 'case1: gate evaluate false');

// Case 2: clarification → reply only
$clarifyClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    ++$GLOBALS['pilot_clarify_api_calls'];
    return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
});
$GLOBALS['pilot_clarify_api_calls'] = 0;
$clarifyReplyCalls = 0;
$clarifyPushCalls = 0;
$clarifyReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$clarifyReplyCalls): array {
    unset($url, $token, $replyToken, $text);
    ++$clarifyReplyCalls;
    return ['mock' => true, 'kind' => 'reply'];
};
$clarifyPushSender = static function (string $url, string $token, string $userId, string $text) use (&$clarifyPushCalls): array {
    unset($url, $token, $userId, $text);
    ++$clarifyPushCalls;
    return ['mock' => true, 'kind' => 'push'];
};

$resultClarify = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試北海道',
    'trace-pilot-2',
    'reply-token-2',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-2',
    'travel-b-channel',
    null,
    $clarifyClient,
    $referenceDate,
    $clarifyReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    $clarifyPushSender
);
test_assert(is_array($resultClarify), 'case2: clarification result array');
test_assert(($resultClarify['phase_9c1']['clarification_required'] ?? false) === true, 'case2: clarification_required');
test_assert($clarifyReplyCalls === 1, 'case2: clarification reply once');
test_assert($clarifyPushCalls === 0, 'case2: no push on clarification');
test_assert(($resultClarify['phase_9c1']['waiting_reply_sent'] ?? true) === false, 'case2: no waiting reply');

// Case 3: product_search → waiting Reply + final Push
$waitingCalls = [];
$finalPushCalls = [];
$finalReplyCalls = 0;
$waitingReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$waitingCalls): array {
    unset($url, $token);
    $waitingCalls[] = ['reply_token' => $replyToken, 'text' => $text];
    return ['mock' => true, 'kind' => 'waiting'];
};
$productPushSender = static function (string $url, string $token, string $userId, string $text) use (&$finalPushCalls): array {
    unset($url, $token);
    $finalPushCalls[] = ['user_id' => $userId, 'text' => $text];
    return ['mock' => true, 'kind' => 'push'];
};
$productReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$finalReplyCalls): array {
    unset($url, $token, $replyToken, $text);
    ++$finalReplyCalls;
    return ['mock' => true, 'kind' => 'final_reply'];
};

$resultSearch = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試北海道7月',
    'trace-pilot-3',
    'reply-token-3',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-3',
    'travel-b-channel',
    null,
    buildPilotMockSearchClient('北海道7月團'),
    $referenceDate,
    $waitingReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    $productPushSender
);
test_assert(count($waitingCalls) === 1, 'case3: waiting reply once');
test_assert(($waitingCalls[0]['text'] ?? '') === $waitingText, 'case3: fixed waiting text');
test_assert(count($finalPushCalls) === 1, 'case3: final push once');
test_assert($finalReplyCalls === 0, 'case3: final not via reply');
test_assert(($resultSearch['phase_9c1']['final_transport'] ?? '') === 'push', 'case3: final transport push');

// Case 4: no prefix → null
test_assert(
    SaaSRouter::attemptPhase9C1StructuredPilotPath(
        $tenantTravelB,
        '北海道7月',
        'trace-pilot-4',
        'reply-token-4',
        'https://api.line.me/v2/bot/message/reply',
        'channel-token-4',
        'travel-b-channel',
        null,
        buildPilotMockSearchClient(),
        $referenceDate,
        $mockLineSender,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        $pilotUserId
    ) === null,
    'case4: no prefix returns null'
);

// Case 5: HUMAN_ACTIVE blocks before waiting/final
$humanStore = [];
$humanResolver = ConversationStatusResolver::createForTesting($humanStore);
$humanConversationId = 'pilot-human-active';
$humanResolver->markHumanActive($humanConversationId, $referenceDate);
$finalCallsHuman = 0;
$humanFinalSender = static function (string $url, string $token, string $replyToken, string $text) use (&$finalCallsHuman): array {
    unset($url, $token, $replyToken, $text);
    ++$finalCallsHuman;
    return ['mock' => true];
};
$resultHuman = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試北海道7月',
    'trace-pilot-5',
    'reply-token-5',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-5',
    'travel-b-channel',
    null,
    buildPilotMockSearchClient(),
    $referenceDate,
    $humanFinalSender,
    null,
    null,
    $humanConversationId,
    $humanResolver,
    null,
    null,
    null,
    $pilotUserId
);
test_assert(($resultHuman['message'] ?? '') === 'phase_9c1_structured_pilot_blocked', 'case5: blocked message');
test_assert($finalCallsHuman === 0, 'case5: no final AI reply sent');

// Case 6: knowledge_query → reply only
$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$knowledgeProvider = new LocalTenantPrivateKnowledgeProvider($travelBSno, $fixtureRoot);
$knowledgeReplyCalls = 0;
$knowledgePushCalls = 0;
$knowledgeReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$knowledgeReplyCalls): array {
    unset($url, $token, $replyToken, $text);
    ++$knowledgeReplyCalls;
    return ['mock' => true, 'kind' => 'knowledge_reply'];
};
$knowledgePushSender = static function (string $url, string $token, string $userId, string $text) use (&$knowledgePushCalls): array {
    unset($url, $token, $userId, $text);
    ++$knowledgePushCalls;
    return ['mock' => true, 'kind' => 'knowledge_push'];
};
$resultKnowledge = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試請問客服電話',
    'trace-pilot-6',
    'reply-token-6',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-6',
    'travel-b-channel',
    null,
    buildPilotMockSearchClient(),
    $referenceDate,
    $knowledgeReplySender,
    null,
    null,
    null,
    null,
    null,
    $knowledgeProvider,
    null,
    $pilotUserId,
    $knowledgePushSender
);
test_assert(($resultKnowledge['phase_9c1']['intent_type'] ?? '') === KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY, 'case6: knowledge intent');
test_assert($knowledgeReplyCalls === 1, 'case6: knowledge reply once');
test_assert($knowledgePushCalls === 0, 'case6: no push for knowledge');

// Case 7: ambiguous → no waiting, final reply
$ambiguousWaitingCalls = 0;
$ambiguousFinalReplyCalls = 0;
$ambiguousPushCalls = 0;
$ambiguousReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$ambiguousWaitingCalls, &$ambiguousFinalReplyCalls, $waitingText): array {
    unset($url, $token, $replyToken);
    if ($text === $waitingText) {
        ++$ambiguousWaitingCalls;
        return ['mock' => true, 'kind' => 'waiting'];
    }
    ++$ambiguousFinalReplyCalls;
    return ['mock' => true, 'kind' => 'ambiguous_final_reply'];
};
$ambiguousPushSender = static function (string $url, string $token, string $userId, string $text) use (&$ambiguousPushCalls): array {
    unset($url, $token, $userId, $text);
    ++$ambiguousPushCalls;
    return ['mock' => true];
};
$resultAmbiguous = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試想出去玩',
    'trace-pilot-7',
    'reply-token-7',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-7',
    'travel-b-channel',
    null,
    buildPilotMockSearchClient(),
    $referenceDate,
    $ambiguousReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    $ambiguousPushSender
);
test_assert($ambiguousWaitingCalls === 0, 'case7: ambiguous no waiting reply');
test_assert($ambiguousPushCalls === 0, 'case7: ambiguous no push');
test_assert($ambiguousFinalReplyCalls === 1, 'case7: ambiguous final via reply');

// Case 8: missing userId → no waiting, final reply fallback
$noUserWaitingCalls = 0;
$noUserFinalReplyCalls = 0;
$noUserPushCalls = 0;
$noUserReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$noUserWaitingCalls, &$noUserFinalReplyCalls, $waitingText): array {
    unset($url, $token, $replyToken);
    if ($text === $waitingText) {
        ++$noUserWaitingCalls;
        return ['mock' => true, 'kind' => 'waiting'];
    }
    ++$noUserFinalReplyCalls;
    return ['mock' => true, 'kind' => 'no_user_final_reply'];
};
$noUserPushSender = static function (string $url, string $token, string $userId, string $text) use (&$noUserPushCalls): array {
    unset($url, $token, $userId, $text);
    ++$noUserPushCalls;
    return ['mock' => true];
};
$resultNoUser = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試北海道7月',
    'trace-pilot-8',
    'reply-token-8',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-8',
    'travel-b-channel',
    null,
    buildPilotMockSearchClient('北海道7月團C'),
    $referenceDate,
    $noUserReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    null,
    '',
    $noUserPushSender
);
test_assert($noUserWaitingCalls === 0, 'case8: missing userId no waiting');
test_assert($noUserPushCalls === 0, 'case8: missing userId no push');
test_assert($noUserFinalReplyCalls === 1, 'case8: missing userId final reply fallback');
test_assert(($resultNoUser['phase_9c1']['final_transport'] ?? '') === 'reply', 'case8: final transport reply');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_saas_router_phase9c1_pilot (all passed)\n");
exit(0);
