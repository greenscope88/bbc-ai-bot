<?php
declare(strict_types=1);

/**
 * 0703 LINE OA fix — SaaSRouter structured pilot regression tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'Phase9C1FeatureGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ConversationStatusResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ConversationQueryMerger.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeFallbackResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'IndustrySharedKnowledgeRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalIndustrySharedKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'HumanServiceResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';

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
$referenceDate = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$pilotUserId = 'U-0703-test-user';
$waitingText = AcknowledgementReplyComposer::composeProductWaitingReply();
$conversationId = '0703-line-oa-fix';

$fixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$knowledgeProvider = new LocalTenantPrivateKnowledgeProvider($travelBSno, $fixtureRoot);
$sharedFixtureRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'shared';
$composer = new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$humanComposer = new HumanServiceResponseComposer(static function (string $poolKey, int $count): int {
    return 0;
});
$sharedRuntime = new IndustrySharedKnowledgeRuntime(null, $composer, static function (string $industryCode) use ($sharedFixtureRoot): LocalIndustrySharedKnowledgeProvider {
    return new LocalIndustrySharedKnowledgeProvider($industryCode, $sharedFixtureRoot . DIRECTORY_SEPARATOR . $industryCode);
});
$knowledgeFallbackResolver = new KnowledgeFallbackResolver($humanComposer, $sharedRuntime);
$aiuTestRuntime = AiIntentUnderstandingRuntime::createForTesting();

function build0703MockSearchClient(string $title = '東京8月雙人團'): TourSearchApiClient
{
    return new TourSearchApiClient('https://example.test/tour/search', 5, static function () use ($title): array {
        $body = json_encode([
            'success' => true,
            'pagination' => ['total' => 1],
            'items' => [
                ['title' => $title, 'tourDate' => '2026-08-10', 'price' => 42800],
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

function build0703ClarifyClient(): TourSearchApiClient
{
    return new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
        return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
    });
}

// Test 01: product search with waiting reply
$waitingCalls = [];
$pushCalls = [];
$waitingReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$waitingCalls): array {
    unset($url, $token);
    $waitingCalls[] = ['reply_token' => $replyToken, 'text' => $text];
    return ['mock' => true, 'kind' => 'waiting'];
};
$productPushSender = static function (string $url, string $token, string $userId, string $text) use (&$pushCalls): array {
    unset($url, $token);
    $pushCalls[] = ['user_id' => $userId, 'text' => $text];
    return ['mock' => true, 'kind' => 'push'];
};

$result01 = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試 東京 8月 兩個人',
    'trace-0703-01',
    'reply-token-01',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-01',
    'travel-b-channel',
    null,
    build0703MockSearchClient(),
    $referenceDate,
    $waitingReplySender,
    null,
    null,
    $conversationId,
    null,
    null,
    null,
    $pilotUserId,
    $productPushSender,
    null,
    null,
    $aiuTestRuntime
);
test_assert(($result01['message'] ?? '') === 'phase_9c1_structured_pilot', 'test01: structured pilot route');
test_assert(count($waitingCalls) === 1, 'test01: waiting reply once');
test_assert(($waitingCalls[0]['text'] ?? '') === $waitingText, 'test01: fixed waiting text');
test_assert(count($pushCalls) === 1, 'test01: final push once');
test_assert(($result01['phase_9c1']['final_transport'] ?? '') === 'push', 'test01: final transport push');

// Test 02: clarification for destination without date
$clarifyReplyCalls = 0;
$clarifyReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$clarifyReplyCalls): array {
    unset($url, $token, $replyToken, $text);
    ++$clarifyReplyCalls;
    return ['mock' => true, 'kind' => 'clarify'];
};

$result02 = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試 北海道',
    'trace-0703-02',
    'reply-token-02',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-02',
    'travel-b-channel',
    null,
    build0703ClarifyClient(),
    $referenceDate,
    $clarifyReplySender,
    null,
    null,
    $conversationId . '-clarify',
    null,
    null,
    null,
    $pilotUserId,
    null,
    null,
    null,
    $aiuTestRuntime
);
test_assert(($result02['phase_9c1']['clarification_required'] ?? false) === true, 'test02: clarification_required');
test_assert($clarifyReplyCalls === 1, 'test02: clarification reply once');
test_assert(($result02['phase_9c1']['waiting_reply_sent'] ?? true) === false, 'test02: no waiting reply');

// Test 03: multi-turn 大阪 then 八月 — lexicon merge retired; AIU receives each turn verbatim
$mergeStore = [];
$mergeResolver = ConversationStatusResolver::createForTesting($mergeStore);
$mergeConversationId = '0703-merge-osaka-august';
$mergeClarifyCalls = 0;
$mergeWaitingCalls = [];
$mergePushCalls = [];
$mergeClarifySender = static function (string $url, string $token, string $replyToken, string $text) use (&$mergeClarifyCalls): array {
    unset($url, $token, $replyToken, $text);
    ++$mergeClarifyCalls;
    return ['mock' => true, 'kind' => 'clarify'];
};
$mergeWaitingSender = static function (string $url, string $token, string $replyToken, string $text) use (&$mergeWaitingCalls): array {
    unset($url, $token);
    $mergeWaitingCalls[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'waiting'];
};
$mergePushSender = static function (string $url, string $token, string $userId, string $text) use (&$mergePushCalls): array {
    unset($url, $token);
    $mergePushCalls[] = ['user_id' => $userId, 'text' => $text];
    return ['mock' => true, 'kind' => 'push'];
};

$result03a = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試大阪',
    'trace-0703-03a',
    'reply-token-03a',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-03a',
    'travel-b-channel',
    null,
    build0703ClarifyClient(),
    $referenceDate,
    $mergeClarifySender,
    null,
    null,
    $mergeConversationId,
    $mergeResolver,
    null,
    null,
    $pilotUserId,
    null,
    null,
    null,
    $aiuTestRuntime
);
test_assert(($result03a['phase_9c1']['clarification_required'] ?? false) === true, 'test03a: first turn clarification');
test_assert($mergeClarifyCalls === 1, 'test03a: clarification reply once');

$result03b = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    '八月',
    'trace-0703-03b',
    'reply-token-03b',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-03b',
    'travel-b-channel',
    null,
    build0703MockSearchClient('大阪8月團'),
    $referenceDate,
    $mergeWaitingSender,
    null,
    null,
    $mergeConversationId,
    $mergeResolver,
    null,
    null,
    $pilotUserId,
    $mergePushSender,
    null,
    null,
    $aiuTestRuntime
);
test_assert(
    ConversationQueryMerger::mergeDateClarificationFollowUp($mergeResolver, $mergeConversationId, '八月') === '八月',
    'test03b: ConversationQueryMerger passthrough only'
);
test_assert(($result03b['message'] ?? '') !== 'phase_9c1_structured_pilot', 'test03b: no lexicon-merged product search');
test_assert(count($mergePushCalls) === 0, 'test03b: no product push without Gemini multi-turn context');
test_assert(
    ($result03b['phase_9c1']['intent_type'] ?? '') !== AiRuntimeIntent::PRODUCT_SEARCH,
    'test03b: date-only follow-up is not product_search'
);

// Test 05: shared baggage weight FAQ via industry fallback
$baggageLineCalls = [];
$baggageReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$baggageLineCalls): array {
    unset($url, $token);
    $baggageLineCalls[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'knowledge'];
};

$result05 = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試行李可以帶幾公斤',
    'trace-0703-05',
    'reply-token-05',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-05',
    'travel-b-channel',
    null,
    build0703MockSearchClient(),
    $referenceDate,
    $baggageReplySender,
    null,
    null,
    $conversationId . '-baggage',
    null,
    null,
    $knowledgeProvider,
    $pilotUserId,
    null,
    $knowledgeFallbackResolver,
    null,
    $aiuTestRuntime
);
test_assert(($result05['phase_9c1']['intent_type'] ?? '') === AiRuntimeIntent::KNOWLEDGE_QUERY, 'test05: knowledge intent');
test_assert(count($baggageLineCalls) === 1, 'test05: LINE reply once');
test_assert(strpos((string) ($baggageLineCalls[0]['text'] ?? ''), '托運與手提行李重量') !== false, 'test05: shared baggage fact');
test_assert(strpos((string) ($baggageLineCalls[0]['text'] ?? ''), '行李額度') !== false, 'test05: baggage allowance guidance');

// Test 06: company address via tenant private knowledge
$addressLineCalls = [];
$addressReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$addressLineCalls): array {
    unset($url, $token);
    $addressLineCalls[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'knowledge'];
};

$result06 = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試你們公司地址在哪裡',
    'trace-0703-06',
    'reply-token-06',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-06',
    'travel-b-channel',
    null,
    build0703MockSearchClient(),
    $referenceDate,
    $addressReplySender,
    null,
    null,
    $conversationId . '-address',
    null,
    null,
    $knowledgeProvider,
    $pilotUserId,
    null,
    null,
    null,
    $aiuTestRuntime
);
test_assert(($result06['phase_9c1']['intent_type'] ?? '') === AiRuntimeIntent::KNOWLEDGE_QUERY, 'test06: knowledge intent');
test_assert(count($addressLineCalls) === 1, 'test06: LINE reply once');
test_assert(strpos((string) ($addressLineCalls[0]['text'] ?? ''), '高雄市三民區綏遠二街101號') !== false, 'test06: company address');

// Test 07: human service request → LINE reply + human_active
$humanStore = [];
$humanResolver = ConversationStatusResolver::createForTesting($humanStore);
$humanConversationId = '0703-human-service';
$humanLineCalls = [];
$humanReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$humanLineCalls): array {
    unset($url, $token);
    $humanLineCalls[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'human_service'];
};

$result07 = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試我要找真人客服',
    'trace-0703-07',
    'reply-token-07',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-07',
    'travel-b-channel',
    null,
    build0703MockSearchClient(),
    $referenceDate,
    $humanReplySender,
    null,
    null,
    $humanConversationId,
    $humanResolver,
    null,
    null,
    $pilotUserId,
    null,
    null,
    null,
    $aiuTestRuntime
);
test_assert(($result07['message'] ?? '') === 'phase_9c2b3_knowledge_human_service_request', 'test07: human service route');
test_assert(count($humanLineCalls) === 1, 'test07: LINE reply once');
test_assert(strpos((string) ($humanLineCalls[0]['text'] ?? ''), '人工客服') !== false, 'test07: human service copy');
test_assert(
    $humanResolver->resolveStatus($humanConversationId, $referenceDate) === ConversationStatusResolver::STATUS_HUMAN_ACTIVE,
    'test07: human_active status'
);

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_saas_router_line_oa_0703_fix (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
