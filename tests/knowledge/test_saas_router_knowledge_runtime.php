<?php
declare(strict_types=1);

/**
 * Phase 9-C-2B-2A: SaaSRouter company profile knowledge integration tests.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'Phase9C1FeatureGate.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'LocalTenantPrivateKnowledgeProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'knowledge'
    . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';

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
$fixtureRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'travel_b';
$knowledgeProvider = new LocalTenantPrivateKnowledgeProvider($travelBSno, $fixtureRoot);
$referenceDate = new DateTimeImmutable('2026-06-20', new DateTimeZone('Asia/Taipei'));

$lineCalls = [];
$mockLineSender = static function (string $url, string $token, string $replyToken, string $text) use (&$lineCalls): array {
    unset($url, $token);
    $lineCalls[] = [
        'reply_token' => $replyToken,
        'text' => $text,
        'text_length' => mb_strlen($text),
    ];
    return [
        'status' => 200,
        'mock' => true,
        'kind' => 'final',
    ];
};

function buildPilotMockSearchClient(): TourSearchApiClient
{
    return new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
        $body = json_encode([
            'success' => true,
            'pagination' => ['total' => 1],
            'items' => [
                ['title' => '北海道夏季團', 'tourDate' => '2026-07-10', 'price' => 39900],
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

// Knowledge query routes to company_profile runtime
$lineCalls = [];
$resultKnowledge = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試請問客服電話',
    'trace-knowledge-phone',
    'reply-token-knowledge',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-knowledge',
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
    $knowledgeProvider
);
test_assert(($resultKnowledge['message'] ?? '') === 'phase_9c2b2a_knowledge_runtime', 'router: knowledge phone route');
test_assert(($resultKnowledge['phase_9c1']['knowledge_grounded'] ?? false) === true, 'router: knowledge grounded');
test_assert(strpos((string) ($lineCalls[0]['text'] ?? ''), '07-5224856') !== false, 'router: phone in LINE text');
test_assert(strpos((string) ($lineCalls[0]['text'] ?? ''), '旅行蜜優惠客服電話為：') !== false, 'router: company profile label');

// Product search still uses structured pilot path
$lineCalls = [];
$resultSearch = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試北海道7月',
    'trace-knowledge-search',
    'reply-token-search',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-search',
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
    $knowledgeProvider
);
test_assert(($resultSearch['message'] ?? '') === 'phase_9c1_structured_pilot', 'router: product search unchanged');
test_assert(($resultSearch['phase_9c1']['search_result_count'] ?? 0) >= 1, 'router: search results present');

// Summary via router
$lineCalls = [];
$resultSummary = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試你們是做什麼的',
    'trace-knowledge-summary',
    'reply-token-summary',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-summary',
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
    $knowledgeProvider
);
test_assert(($resultSummary['message'] ?? '') === 'phase_9c2b2a_knowledge_runtime', 'router: summary knowledge route');
test_assert(strpos((string) ($lineCalls[0]['text'] ?? ''), '旅行蜜優惠主要提供：') !== false, 'router: summary label');

// service_qa via router
$lineCalls = [];
$resultQa = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試搭機前多久到機場？',
    'trace-knowledge-qa-checkin',
    'reply-token-qa',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-qa',
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
    $knowledgeProvider
);
test_assert(($resultQa['message'] ?? '') === 'phase_9c2b3_qa_knowledge_runtime', 'router: service_qa route');
test_assert(($resultQa['phase_9c1']['knowledge_grounded'] ?? false) === true, 'router: service_qa grounded');
test_assert(strpos((string) ($lineCalls[0]['text'] ?? ''), '飛機起飛前二個小時前') !== false, 'router: service_qa grounded fact');
test_assert(strpos((string) ($lineCalls[0]['text'] ?? ''), '搭機須知') !== false, 'router: service_qa persona label');

// Phase 9-C-1Z: no legacy prefix required for BATS-enabled tenant
$lineCalls = [];
$resultKnowledgeNoPrefix = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    '請問客服電話',
    'trace-knowledge-phone-no-prefix',
    'reply-token-knowledge-no-prefix',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-knowledge-no-prefix',
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
    $knowledgeProvider
);
test_assert(($resultKnowledgeNoPrefix['message'] ?? '') === 'phase_9c2b2a_knowledge_runtime', 'router: knowledge without prefix');
test_assert(strpos((string) ($lineCalls[0]['text'] ?? ''), '07-5224856') !== false, 'router: phone without prefix');

$lineCalls = [];
$resultSearchNoPrefix = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    '北海道7月',
    'trace-knowledge-search-no-prefix',
    'reply-token-search-no-prefix',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-search-no-prefix',
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
    $knowledgeProvider
);
test_assert(($resultSearchNoPrefix['message'] ?? '') === 'phase_9c1_structured_pilot', 'router: product search without prefix');

if ($failures === 0) {
    fwrite(STDOUT, "OK: test_saas_router_knowledge_runtime (all passed)\n");
    exit(0);
}

fwrite(STDERR, "FAILED: {$failures} assertion(s)\n");
exit(1);
