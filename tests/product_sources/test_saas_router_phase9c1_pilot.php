<?php
declare(strict_types=1);

/**
 * Phase 9-C-1d-β1: SaaSRouter structured pilot path tests (no live HTTP / DB).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'Phase9C1FeatureGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'ConversationStatusResolver.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'AcknowledgementReplyComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'StructuredSearchResumeStateStore.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridDateRequiredGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'GroundedResponseComposer.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationContract.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'clarification' . DIRECTORY_SEPARATOR . 'ClarificationGeminiGenerator.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'ClarificationLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'LayoutStrategySelector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'KnowledgeLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'ProductLayoutStrategy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'grounding'
    . DIRECTORY_SEPARATOR . 'GroundingPipelineRuntime.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'response'
    . DIRECTORY_SEPARATOR . 'ReplyType.php';

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

function buildPilotMockSearchClientCouponNameOnly(string $couponName): TourSearchApiClient
{
    return new TourSearchApiClient('https://example.test/tour/search', 5, static function () use ($couponName): array {
        $body = json_encode([
            'success' => true,
            'pagination' => ['total' => 1],
            'items' => [
                ['couponName' => $couponName, 'tourDate' => '2026-07-10', 'price' => 45000],
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

// P1 Product Layout Fix: multi-item Host B payload (title/date/price/origin/schedule)
// so the restored fixed BBC product layout (TourFallbackFormatter) can be asserted.
function buildPilotMockSearchClientRichLayout(): TourSearchApiClient
{
    return new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
        $body = json_encode([
            'success' => true,
            'pagination' => ['total' => 2],
            'items' => [
                [
                    'title' => '東京賞櫻五日',
                    'tourDate' => '2026-07-12',
                    'price' => 38800,
                    'departureStr' => '台北出發',
                    'schLinks' => [['schLinkName' => '行程表', 'schLink' => 'https://example.test/sch/tokyo-a']],
                ],
                [
                    'title' => '東京自由行四日',
                    'tourDate' => '2026-07-20',
                    'price' => 29800,
                    'departureStr' => '高雄出發',
                    'schLinks' => [['schLinkName' => '行程表', 'schLink' => 'https://example.test/sch/tokyo-b']],
                ],
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

/**
 * Deterministic Gemini Semantic Result fixture (Output Contract shape).
 *
 * @param array<string, mixed> $entities
 * @return array{
 *   intent: string,
 *   entities: array<string, mixed>,
 *   confidence: float,
 *   clarification: array{required: bool, reason: string}
 * }
 */
function pilotGeminiSemantic(
    string $intent,
    array $entities = [],
    float $confidence = 0.92,
    bool $clarificationRequired = false,
    string $clarificationReason = ''
): array {
    return [
        'intent' => $intent,
        'entities' => $entities,
        'confidence' => $confidence,
        'clarification' => [
            'required' => $clarificationRequired,
            'reason' => $clarificationReason,
        ],
    ];
}

function pilotAiuRuntimeFromSemantic(array $semantic): AiIntentUnderstandingRuntime
{
    static $storeSeq = 0;
    ++$storeSeq;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbc_pilot_resume_' . getmypid() . '_' . $storeSeq;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return AiIntentUnderstandingRuntime::createForTesting(
        new AiuGeminiUnderstandingClientStub(static function () use ($semantic): array {
            return $semantic;
        }),
        null,
        new StructuredSearchResumeStateStore($dir)
    );
}

/**
 * @return array<string, mixed>
 */
function pilotRawLineEvent(string $userId, string $webhookEventId, string $destination = 'travel-b-channel'): array
{
    return [
        'destination' => $destination,
        'events' => [[
            'type' => 'message',
            'webhookEventId' => $webhookEventId,
            'source' => ['type' => 'user', 'userId' => $userId],
            'message' => ['type' => 'text', 'id' => '1', 'text' => 'x'],
        ]],
    ];
}

$pilotSemanticHokkaidoClarify = pilotGeminiSemantic(
    'Product Search',
    ['destination' => ['北海道']],
    0.5,
    true,
    'missing_travel_dates'
);
$pilotSemanticHokkaidoJuly = pilotGeminiSemantic(
    'Product Search',
    [
        'destination' => ['北海道'],
        'date_range' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
        'date_expression' => '7月',
        'date_from' => '2026-07-01',
        'date_to' => '2026-07-31',
    ]
);
$pilotSemanticKnowledge = pilotGeminiSemantic('Knowledge', [], 0.9, false, '');
$pilotSemanticAmbiguous = pilotGeminiSemantic('Ambiguous', [], 0.4, true, 'intent_ambiguous');
$pilotSemanticRecentTokyo = pilotGeminiSemantic(
    'Product Search',
    [
        'destination' => ['東京'],
        'date_range' => ['from' => '2026-06-06', 'to' => '2026-08-05'],
        'date_expression' => '近期',
        'date_from' => '2026-06-06',
        'date_to' => '2026-08-05',
    ]
);
$pilotSemanticRecentTokyoFiveDays = pilotGeminiSemantic(
    'Product Search',
    [
        'destination' => ['東京'],
        'duration' => '5日',
        'duration_days' => 5,
        'date_range' => ['from' => '2026-06-06', 'to' => '2026-08-05'],
        'date_expression' => '近期',
        'date_from' => '2026-06-06',
        'date_to' => '2026-08-05',
    ]
);

$pilotRuntimeHokkaidoClarify = pilotAiuRuntimeFromSemantic($pilotSemanticHokkaidoClarify);
$pilotRuntimeHokkaidoJuly = pilotAiuRuntimeFromSemantic($pilotSemanticHokkaidoJuly);
$pilotRuntimeKnowledge = pilotAiuRuntimeFromSemantic($pilotSemanticKnowledge);
$pilotRuntimeAmbiguous = pilotAiuRuntimeFromSemantic($pilotSemanticAmbiguous);
$pilotRuntimeRecentTokyo = pilotAiuRuntimeFromSemantic($pilotSemanticRecentTokyo);
$pilotRuntimeRecentTokyoFiveDays = pilotAiuRuntimeFromSemantic($pilotSemanticRecentTokyoFiveDays);

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

// Case 2: clarification → reply only (generative Composer owner; no Date formatter)
$clarifyClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    ++$GLOBALS['pilot_clarify_api_calls'];
    return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
});
$GLOBALS['pilot_clarify_api_calls'] = 0;
$clarifyReplyCalls = 0;
$clarifyPushCalls = 0;
$clarifyReplyText = '';
$clarifyGeminiCalls = 0;
$clarifyReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$clarifyReplyCalls, &$clarifyReplyText): array {
    unset($url, $token, $replyToken);
    ++$clarifyReplyCalls;
    $clarifyReplyText = $text;
    return ['mock' => true, 'kind' => 'reply'];
};
$clarifyPushSender = static function (string $url, string $token, string $userId, string $text) use (&$clarifyPushCalls): array {
    unset($url, $token, $userId, $text);
    ++$clarifyPushCalls;
    return ['mock' => true, 'kind' => 'push'];
};

$clarifyStubComposer = new GroundedResponseComposer(
    null,
    null,
    new LayoutStrategySelector([
        new ClarificationLayoutStrategy(new ClarificationGeminiGenerator(null, static function () use (&$clarifyGeminiCalls): array {
            ++$clarifyGeminiCalls;

            return [
                'ok' => true,
                'text' => json_encode([
                    'schema_version' => 1,
                    'reply_type' => 'clarification',
                    'clarification_reason' => 'date_required',
                    'asked_entity' => 'date',
                    'reply_text' => '想先確認大概哪一段時間出發比較方便呢？',
                    'acknowledged_entities' => [
                        'destination' => ['北海道'],
                    ],
                    'search_claimed' => false,
                    'product_facts_used' => false,
                ], JSON_UNESCAPED_UNICODE),
                'error' => null,
            ];
        })),
        new KnowledgeLayoutStrategy(),
        new ProductLayoutStrategy(),
    ])
);

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
    $pilotUserId,
    $clarifyPushSender,
    null,
    pilotRawLineEvent($pilotUserId, 'WEVT-CASE2'),
    $pilotRuntimeHokkaidoClarify,
    $clarifyStubComposer
);
test_assert(is_array($resultClarify), 'case2: clarification result array');
test_assert(($resultClarify['phase_9c1']['clarification_required'] ?? false) === true, 'case2: clarification_required');
test_assert($clarifyReplyCalls === 1, 'case2: clarification reply once');
test_assert($clarifyPushCalls === 0, 'case2: no push on clarification');
test_assert(($resultClarify['phase_9c1']['waiting_reply_sent'] ?? true) === false, 'case2: no waiting reply');
test_assert(($GLOBALS['pilot_clarify_api_calls'] ?? -1) === 0, 'case2: Host B calls = 0');
test_assert($clarifyGeminiCalls === 1, 'case2: one application generation call');
test_assert(
    ($resultClarify['phase_9c1']['final_route'] ?? '') === GroundingPipelineRuntime::ROUTE_GENERATIVE_CLARIFICATION,
    'case2: generative clarification route'
);
test_assert(
    ($resultClarify['phase_9c1']['final_owner'] ?? '') === 'grounded_response_composer',
    'case2: composer sole owner'
);
test_assert(($resultClarify['phase_9c1']['asked_entity'] ?? '') === 'date', 'case2: asked_entity date');
test_assert(($resultClarify['phase_9c1']['grounded_reply_type'] ?? '') === ReplyType::CLARIFICATION, 'case2: reply_type');
test_assert(
    strpos($clarifyReplyText, '請問您預計什麼時候出發呢？我會依照您的出發時間') === false,
    'case2: no fixed date fallback wording'
);
test_assert($clarifyReplyText !== '', 'case2: composer wording sent');
test_assert(
    $clarifyReplyText !== ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
    'case2: not technical fail-closed'
);

// Case 2b: invalid AIU reason → fail-closed before Search (Host B = 0; no generative clarif)
$pilotSemanticInvalidReason = pilotGeminiSemantic(
    'Product Search',
    [
        'destination' => [],
        'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ],
    0.8,
    true,
    'critical_slots_missing'
);
$pilotRuntimeInvalidReason = pilotAiuRuntimeFromSemantic($pilotSemanticInvalidReason);
$GLOBALS['pilot_invalid_reason_api_calls'] = 0;
$invalidReasonClient = new TourSearchApiClient(
    'https://example.test/tour/search',
    5,
    static function (): array {
        ++$GLOBALS['pilot_invalid_reason_api_calls'];

        return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
    }
);
$invalidReasonReplyCalls = 0;
$invalidReasonGeminiCalls = 0;
$invalidReasonReplySender = static function (
    string $url,
    string $token,
    string $replyToken,
    string $text
) use (&$invalidReasonReplyCalls): array {
    unset($url, $token, $replyToken, $text);
    ++$invalidReasonReplyCalls;

    return ['mock' => true, 'kind' => 'reply'];
};
$invalidReasonComposer = new GroundedResponseComposer(
    null,
    null,
    new LayoutStrategySelector([
        new ClarificationLayoutStrategy(new ClarificationGeminiGenerator(null, static function () use (&$invalidReasonGeminiCalls): array {
            ++$invalidReasonGeminiCalls;

            return [
                'ok' => true,
                'text' => json_encode([
                    'schema_version' => 1,
                    'reply_type' => 'clarification',
                    'clarification_reason' => 'destination_unknown',
                    'asked_entity' => 'destination',
                    'reply_text' => 'should-not-run',
                    'acknowledged_entities' => [],
                    'search_claimed' => false,
                    'product_facts_used' => false,
                ], JSON_UNESCAPED_UNICODE),
                'error' => null,
            ];
        })),
        new KnowledgeLayoutStrategy(),
        new ProductLayoutStrategy(),
    ])
);
$resultInvalidReason = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試我想找8月從台北出發的行程',
    'trace-pilot-2b',
    'reply-token-2b',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-2b',
    'travel-b-channel',
    null,
    $invalidReasonClient,
    $referenceDate,
    $invalidReasonReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    null,
    null,
    null,
    $pilotRuntimeInvalidReason,
    $invalidReasonComposer
);
test_assert(is_array($resultInvalidReason), 'case2b: invalid reason result array');
test_assert(
    ($resultInvalidReason['message'] ?? '') === 'phase_9c1_aiu_fail_closed',
    'case2b: aiu fail-closed message'
);
test_assert(
    ($resultInvalidReason['phase_9c1']['final_route'] ?? '') === 'phase_9c1_aiu_fail_closed',
    'case2b: fail-closed route'
);
test_assert(($GLOBALS['pilot_invalid_reason_api_calls'] ?? -1) === 0, 'case2b: Host B calls = 0');
test_assert($invalidReasonReplyCalls === 0, 'case2b: no generative clarif LINE reply');
test_assert($invalidReasonGeminiCalls === 0, 'case2b: no Clarification Composer generation');

// Case 2c: valid missing_destination → generative clarification (destination ask)
$pilotSemanticMissingDest = pilotGeminiSemantic(
    'Product Search',
    [
        'destination' => [],
        'date_range' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ],
    0.8,
    true,
    'missing_destination'
);
$pilotRuntimeMissingDest = pilotAiuRuntimeFromSemantic($pilotSemanticMissingDest);
$GLOBALS['pilot_missing_dest_api_calls'] = 0;
$missingDestClient = new TourSearchApiClient(
    'https://example.test/tour/search',
    5,
    static function (): array {
        ++$GLOBALS['pilot_missing_dest_api_calls'];

        return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
    }
);
$missingDestReplyCalls = 0;
$missingDestGeminiCalls = 0;
$missingDestReplyText = '';
$missingDestReplySender = static function (
    string $url,
    string $token,
    string $replyToken,
    string $text
) use (&$missingDestReplyCalls, &$missingDestReplyText): array {
    unset($url, $token, $replyToken);
    ++$missingDestReplyCalls;
    $missingDestReplyText = $text;

    return ['mock' => true, 'kind' => 'reply'];
};
$missingDestComposer = new GroundedResponseComposer(
    null,
    null,
    new LayoutStrategySelector([
        new ClarificationLayoutStrategy(new ClarificationGeminiGenerator(null, static function () use (&$missingDestGeminiCalls): array {
            ++$missingDestGeminiCalls;

            return [
                'ok' => true,
                'text' => json_encode([
                    'schema_version' => 1,
                    'reply_type' => 'clarification',
                    'clarification_reason' => 'destination_unknown',
                    'asked_entity' => 'destination',
                    'reply_text' => '想先確認這次主要想去哪個目的地呢？',
                    'acknowledged_entities' => [
                        'date_from' => '2026-08-01',
                        'date_to' => '2026-08-31',
                    ],
                    'search_claimed' => false,
                    'product_facts_used' => false,
                ], JSON_UNESCAPED_UNICODE),
                'error' => null,
            ];
        })),
        new KnowledgeLayoutStrategy(),
        new ProductLayoutStrategy(),
    ])
);
$resultMissingDest = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試我想找8月從台北出發的行程',
    'trace-pilot-2c',
    'reply-token-2c',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-2c',
    'travel-b-channel',
    null,
    $missingDestClient,
    $referenceDate,
    $missingDestReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    null,
    null,
    pilotRawLineEvent($pilotUserId, 'WEVT-CASE2C'),
    $pilotRuntimeMissingDest,
    $missingDestComposer
);
test_assert(is_array($resultMissingDest), 'case2c: missing_destination result array');
test_assert(($resultMissingDest['phase_9c1']['clarification_required'] ?? false) === true, 'case2c: clarification_required');
test_assert(($GLOBALS['pilot_missing_dest_api_calls'] ?? -1) === 0, 'case2c: Host B calls = 0');
test_assert($missingDestReplyCalls === 1, 'case2c: generative clarif reply once');
test_assert($missingDestGeminiCalls === 1, 'case2c: Clarification Composer once');
test_assert(
    ($resultMissingDest['phase_9c1']['final_route'] ?? '') === GroundingPipelineRuntime::ROUTE_GENERATIVE_CLARIFICATION,
    'case2c: generative clarification route'
);
test_assert(($resultMissingDest['phase_9c1']['asked_entity'] ?? '') === 'destination', 'case2c: asked_entity destination');
test_assert($missingDestReplyText !== '', 'case2c: composer wording sent');
test_assert(
    $missingDestReplyText !== ClarificationContract::TECHNICAL_FAIL_CLOSED_TEXT,
    'case2c: not technical fail-closed'
);

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
    $pilotUserId,
    $productPushSender,
    null,
    null,
    $pilotRuntimeHokkaidoJuly
);
test_assert(count($waitingCalls) === 1, 'case3: waiting reply once');
test_assert(($waitingCalls[0]['text'] ?? '') === $waitingText, 'case3: fixed waiting text');
test_assert(count($finalPushCalls) === 1, 'case3: final push once');
test_assert($finalReplyCalls === 0, 'case3: final not via reply');
test_assert(($resultSearch['phase_9c1']['final_transport'] ?? '') === 'push', 'case3: final transport push');
test_assert(
    ($resultSearch['phase_9c1']['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU,
    'case3: runtime_source aiu'
);
test_assert(
    ($resultSearch['phase_9c1']['intent_type'] ?? '') === AiRuntimeIntent::PRODUCT_SEARCH,
    'case3: product_search intent'
);
test_assert(
    !array_key_exists('legacy_understanding_used', $resultSearch['phase_9c1'] ?? []),
    'case3: legacy_understanding_used absent'
);

// Case 4: BATS-enabled tenant without prefix → enters pilot path
$resultNoPrefix = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    '北海道7月',
    'trace-pilot-4',
    'reply-token-4',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-4',
    'travel-b-channel',
    null,
    buildPilotMockSearchClient('北海道7月團D'),
    $referenceDate,
    $waitingReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    $productPushSender,
    null,
    null,
    $pilotRuntimeHokkaidoJuly
);
test_assert(is_array($resultNoPrefix), 'case4: enabled tenant without prefix enters pilot');
test_assert(($resultNoPrefix['message'] ?? '') === 'phase_9c1_structured_pilot', 'case4: pilot message without prefix');

// Case 4b: non-enabled tenant → null
test_assert(
    SaaSRouter::attemptPhase9C1StructuredPilotPath(
        $otherTenant,
        '北海道7月',
        'trace-pilot-4b',
        'reply-token-4b',
        'https://api.line.me/v2/bot/message/reply',
        'channel-token-4b',
        'staging-channel',
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
        $pilotUserId
    ) === null,
    'case4b: non-enabled tenant returns null'
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
    $pilotUserId,
    $knowledgePushSender,
    null,
    null,
    $pilotRuntimeKnowledge
);
test_assert(($resultKnowledge['phase_9c1']['intent_type'] ?? '') === AiRuntimeIntent::KNOWLEDGE_QUERY, 'case6: knowledge intent');
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
    $pilotUserId,
    $ambiguousPushSender,
    null,
    null,
    $pilotRuntimeAmbiguous
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
    '',
    $noUserPushSender,
    null,
    null,
    $pilotRuntimeHokkaidoJuly
);
test_assert($noUserWaitingCalls === 0, 'case8: missing userId no waiting');
test_assert($noUserPushCalls === 0, 'case8: missing userId no push');
test_assert($noUserFinalReplyCalls === 1, 'case8: missing userId final reply fallback');
test_assert(($resultNoUser['phase_9c1']['final_transport'] ?? '') === 'reply', 'case8: final transport reply');

// Case 9: Host B row with couponName only (no title) → product recommendation, not no-results
$couponNameTitle = '東京近期五日精選';
$couponNamePushCalls = [];
$couponNamePushSender = static function (string $url, string $token, string $userId, string $text) use (&$couponNamePushCalls, $couponNameTitle): array {
    unset($url, $token, $userId);
    $couponNamePushCalls[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'push'];
};
$resultCouponName = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    '近期東京',
    'trace-pilot-9',
    'reply-token-9',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-9',
    'travel-b-channel',
    null,
    buildPilotMockSearchClientCouponNameOnly($couponNameTitle),
    $referenceDate,
    $waitingReplySender,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    $couponNamePushSender,
    null,
    null,
    $pilotRuntimeRecentTokyo
);
$pushText = (string) ($couponNamePushCalls[0]['text'] ?? '');
test_assert(is_array($resultCouponName), 'case9: couponName-only result array');
test_assert(($resultCouponName['phase_9c1']['search_result_count'] ?? 0) >= 1, 'case9: search_result_count >= 1');
test_assert(count($couponNamePushCalls) === 1, 'case9: final push once');
test_assert(mb_strpos($pushText, $couponNameTitle) !== false, 'case9: push includes couponName title');
test_assert(mb_strpos($pushText, '目前尚未找到符合條件的商品') === false, 'case9: push not no-results message');

// Case 10: P1 Product Layout Fix — 近期東京 → Waiting Reply first, then a fixed
// BBC product layout (TourFallbackFormatter) on push (🚩 / 📅 / 💰 / 🛫 / 🗓️ + separator).
$layoutWaitingCalls10 = [];
$layoutPushCalls10 = [];
$layoutWaitingSender10 = static function (string $url, string $token, string $replyToken, string $text) use (&$layoutWaitingCalls10): array {
    unset($url, $token, $replyToken);
    $layoutWaitingCalls10[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'waiting'];
};
$layoutPushSender10 = static function (string $url, string $token, string $userId, string $text) use (&$layoutPushCalls10): array {
    unset($url, $token, $userId);
    $layoutPushCalls10[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'push'];
};
$resultLayout10 = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    '近期東京',
    'trace-pilot-10',
    'reply-token-10',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-10',
    'travel-b-channel',
    null,
    buildPilotMockSearchClientRichLayout(),
    $referenceDate,
    $layoutWaitingSender10,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    $layoutPushSender10,
    null,
    null,
    $pilotRuntimeRecentTokyo
);
$layoutPushText10 = (string) ($layoutPushCalls10[0]['text'] ?? '');
test_assert(is_array($resultLayout10), 'case10: layout result array');
test_assert(count($layoutWaitingCalls10) === 1, 'case10: waiting reply once');
test_assert(($layoutWaitingCalls10[0]['text'] ?? '') === $waitingText, 'case10: fixed waiting text');
test_assert(count($layoutPushCalls10) === 1, 'case10: final push once');
test_assert(($resultLayout10['phase_9c1']['final_transport'] ?? '') === 'push', 'case10: final transport push');
test_assert(mb_strpos($layoutPushText10, '🚩') !== false, 'case10: push has 🚩 title flag');
test_assert(mb_strpos($layoutPushText10, '📅 最近出團：') !== false, 'case10: push has 📅 departure');
test_assert(mb_strpos($layoutPushText10, '💰 售價：') !== false, 'case10: push has 💰 price');
test_assert(mb_strpos($layoutPushText10, '🛫 出發地：') !== false, 'case10: push has 🛫 origin');
test_assert(mb_strpos($layoutPushText10, '🗓️ 行程表：') !== false, 'case10: push has 🗓️ schedule');
test_assert(mb_strpos($layoutPushText10, '──────────────') !== false, 'case10: push has item separator');
test_assert(mb_strpos($layoutPushText10, '東京賞櫻五日') !== false, 'case10: push has product title');

// Case 11: P1 Product Layout Fix — 最近東京五天 → Waiting Reply first, fixed product layout.
$layoutWaitingCalls11 = [];
$layoutPushCalls11 = [];
$layoutWaitingSender11 = static function (string $url, string $token, string $replyToken, string $text) use (&$layoutWaitingCalls11): array {
    unset($url, $token, $replyToken);
    $layoutWaitingCalls11[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'waiting'];
};
$layoutPushSender11 = static function (string $url, string $token, string $userId, string $text) use (&$layoutPushCalls11): array {
    unset($url, $token, $userId);
    $layoutPushCalls11[] = ['text' => $text];
    return ['mock' => true, 'kind' => 'push'];
};
$resultLayout11 = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    '最近東京五天',
    'trace-pilot-11',
    'reply-token-11',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-11',
    'travel-b-channel',
    null,
    buildPilotMockSearchClientRichLayout(),
    $referenceDate,
    $layoutWaitingSender11,
    null,
    null,
    null,
    null,
    null,
    null,
    $pilotUserId,
    $layoutPushSender11,
    null,
    null,
    $pilotRuntimeRecentTokyoFiveDays
);
$layoutPushText11 = (string) ($layoutPushCalls11[0]['text'] ?? '');
test_assert(is_array($resultLayout11), 'case11: layout result array');
test_assert(count($layoutWaitingCalls11) === 1, 'case11: waiting reply once');
test_assert(count($layoutPushCalls11) === 1, 'case11: final push once');
test_assert(mb_strpos($layoutPushText11, '🚩') !== false, 'case11: push has 🚩 title flag');
test_assert(mb_strpos($layoutPushText11, '📅 最近出團：') !== false, 'case11: push has 📅 departure');
test_assert(mb_strpos($layoutPushText11, '💰 售價：') !== false, 'case11: push has 💰 price');
test_assert(mb_strpos($layoutPushText11, '🛫 出發地：') !== false, 'case11: push has 🛫 origin');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_saas_router_phase9c1_pilot (all passed)\n");
exit(0);
