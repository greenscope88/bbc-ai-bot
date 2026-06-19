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

$ackCalls = [];
$mockAckSender = static function (string $url, string $token, string $replyToken, string $text) use (&$ackCalls): array {
    unset($url, $token);
    $ackCalls[] = [
        'reply_token' => $replyToken,
        'text_length' => mb_strlen($text),
        'text' => $text,
    ];
    return [
        'status' => 200,
        'mock' => true,
        'kind' => 'ack',
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

// Case 2: gate true + missing date → clarification reply
$clarifyClient = buildPilotMockSearchClient();
$GLOBALS['pilot_clarify_api_calls'] = 0;
$clarifyClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    ++$GLOBALS['pilot_clarify_api_calls'];
    return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
});

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
    $mockLineSender
);
test_assert(is_array($resultClarify), 'case2: clarification result array');
test_assert(($resultClarify['message'] ?? '') === 'phase_9c1_structured_pilot', 'case2: pilot message');
test_assert(($resultClarify['phase_9c1']['clarification_required'] ?? false) === true, 'case2: clarification_required');
test_assert(($GLOBALS['pilot_clarify_api_calls'] ?? 0) === 0, 'case2: no Host B on clarification');
test_assert(
    (int) ($resultClarify['phase_9c1']['reply_text_length'] ?? 0) > 0,
    'case2: clarification reply text'
);
test_assert(
    ($resultClarify['phase_9c1']['gemini_context_schema_version'] ?? null) === null,
    'case2: no v2 context on clarification'
);

// Case 3: gate true + dated query → structured v2 path with product recommendation
$capturedReplyText = '';
$captureLineSender = static function (string $url, string $token, string $replyToken, string $text) use (&$capturedReplyText): array {
    unset($url, $token);
    $capturedReplyText = $text;
    return [
        'status' => 200,
        'reply_token' => $replyToken,
        'text_length' => mb_strlen($text),
        'mock' => true,
        'kind' => 'final',
    ];
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
    $captureLineSender
);
test_assert(is_array($resultSearch), 'case3: searchable result array');
test_assert(($resultSearch['phase_9c1']['clarification_required'] ?? true) === false, 'case3: not clarification');
test_assert(
    ($resultSearch['phase_9c1']['gemini_context_schema_version'] ?? 0) === GeminiContextDocument::SCHEMA_VERSION_V2,
    'case3: gemini context v2'
);
test_assert(($resultSearch['phase_9c1']['search_result_count'] ?? 0) >= 1, 'case3: search results present');
test_assert(
    (int) ($resultSearch['phase_9c1']['reply_text_length'] ?? 0) > 0,
    'case3: reply text produced'
);
test_assert(mb_strpos($capturedReplyText, '超出') === false, 'case3: no out-of-scope reply');
test_assert(mb_strpos($capturedReplyText, '您可以參考以下完整行程') !== false, 'case3: primary_url section present');
test_assert(preg_match('#https?://#u', $capturedReplyText) === 1, 'case3: primary_url in reply');
test_assert(mb_strpos($capturedReplyText, '北海道') !== false, 'case3: product recommendation content');

// Case 4: travel_b without prefix → null
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
        $mockLineSender
    ) === null,
    'case4: no prefix returns null'
);

// Case 5: HUMAN_ACTIVE blocks AI reply (no final line sender call)
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
    $humanResolver
);
test_assert(is_array($resultHuman), 'case5: human active returns array');
test_assert(($resultHuman['message'] ?? '') === 'phase_9c1_structured_pilot_blocked', 'case5: blocked message');
test_assert(($resultHuman['phase_9c1']['block_reason'] ?? '') === 'human_active', 'case5: human_active reason');
test_assert($finalCallsHuman === 0, 'case5: no final AI reply sent');

// Case 6: AI_ACTIVE searchable path sends ack (mock) then final reply
$ackCalls = [];
$finalCallsAi = 0;
$aiFinalSender = static function (string $url, string $token, string $replyToken, string $text) use (&$finalCallsAi): array {
    unset($url, $token);
    ++$finalCallsAi;
    return [
        'status' => 200,
        'reply_token' => $replyToken,
        'text_length' => mb_strlen($text),
        'mock' => true,
        'kind' => 'final',
    ];
};
$aiStore = [];
$aiResolver = ConversationStatusResolver::createForTesting($aiStore);
$resultAi = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試北海道7月',
    'trace-pilot-6',
    'reply-token-6',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-6',
    'travel-b-channel',
    null,
    buildPilotMockSearchClient('北海道7月團B'),
    $referenceDate,
    $aiFinalSender,
    null,
    null,
    'pilot-ai-active',
    $aiResolver,
    $mockAckSender
);
test_assert(is_array($resultAi), 'case6: AI_ACTIVE result array');
test_assert(($resultAi['message'] ?? '') === 'phase_9c1_structured_pilot', 'case6: pilot message');
test_assert(count($ackCalls) === 1, 'case6: acknowledgement sent once');
test_assert(
    AcknowledgementReplyComposer::isQueryInProgressSemantic((string) ($ackCalls[0]['text'] ?? '')),
    'case6: acknowledgement semantic'
);
test_assert($finalCallsAi === 1, 'case6: final reply sent once');
test_assert((int) ($resultAi['phase_9c1']['ack_text_length'] ?? 0) > 0, 'case6: ack_text_length logged');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_saas_router_phase9c1_pilot (all passed)\n");
exit(0);
