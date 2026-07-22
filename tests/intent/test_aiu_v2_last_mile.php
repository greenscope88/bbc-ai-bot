<?php
declare(strict_types=1);

/**
 * AIU v2 Last Mile — Contract Translation + Product Execute integration tests.
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'DispatchPlan.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'AiuGoldUtteranceUnderstandingFixtures.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';

$failures = 0;

function lm_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$translator = new AiuProductIntentTranslator();
$runtime = AiIntentUnderstandingRuntime::createForTesting(
    new AiuGeminiUnderstandingClientStub(AiuGoldUtteranceUnderstandingFixtures::resolver())
);
$cid = '5f99b8d665e8444d:line:U-lastmile';
$pilotSno = '5f99b8d665e8444d';

// --- Contract Translation: 北海道 8月 ---
$hokkaido = $runtime->understand('北海道 8月', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
$batsHokkaido = $translator->translate($hokkaido);
lm_assert($batsHokkaido->getDestination() === ['北海道'], 'translate: hokkaido destination');
lm_assert($batsHokkaido->isClarificationRequired() === false, 'translate: hokkaido no clarification');
lm_assert($batsHokkaido->getDateFrom() !== null, 'translate: hokkaido has date_from');

// --- Contract Translation: 火星五日遊 8月 vs 火星五日遊8月 parity ---
$marsA = $runtime->understand('火星五日遊 8月', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
$marsB = $runtime->understand('火星五日遊8月', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
$batsA = $translator->translate($marsA);
$batsB = $translator->translate($marsB);
lm_assert($batsA->getDestination() === ['火星'], 'translate: marsA destination');
lm_assert($batsB->getDestination() === ['火星'], 'translate: marsB destination');
lm_assert($batsA->getDestination() === $batsB->getDestination(), 'translate: mars destination parity');
lm_assert($batsA->isClarificationRequired() === $batsB->isClarificationRequired(), 'translate: mars clarification parity');
lm_assert($batsA->getDateFrom() === $batsB->getDateFrom(), 'translate: mars date parity');

// --- Contract Translation: clarification from AIU top-level (not re-parse) ---
$noDate = $runtime->understand('我想去東京自由行', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
$batsNoDate = $translator->translate($noDate);
lm_assert($noDate->isClarificationRequired() === true, 'translate: noDate AIU clarification true');
lm_assert($batsNoDate->isClarificationRequired() === true, 'translate: noDate bats clarification true');
lm_assert(
    $batsNoDate->getClarificationReason() === ClarificationPolicy::REASON_DATE_REQUIRED,
    'translate: noDate reason date_required'
);

// --- Contract Translation: multi-turn merge query 大阪 八月 ---
$merged = $runtime->understand('大阪 八月', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
$batsMerged = $translator->translate($merged);
lm_assert($merged->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'translate: merged intent product');
lm_assert($batsMerged->getDestination() === ['大阪'], 'translate: merged destination osaka');
lm_assert($batsMerged->isClarificationRequired() === false, 'translate: merged no clarification');

// --- Product Execute: authoritativeIntent bypasses BatsSearchIntentBuilder ---
$mockSearchClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [
            ['title' => '北海道8月團', 'tourDate' => '2026-08-10', 'price' => 42800],
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

$service = new TourPromptContextService();
$authResult = $service->buildTourContextResult([
    'userText' => '北海道 8月',
    'sno' => $pilotSno,
    'featureEnabled' => true,
    'referenceDate' => new DateTimeImmutable('2026-08-01', new DateTimeZone('Asia/Taipei')),
    'searchClient' => $mockSearchClient,
    'authoritativeIntent' => $batsHokkaido,
]);
lm_assert(!$authResult->isClarificationRequired(), 'execute: authoritative hokkaido searchable');
lm_assert($authResult->getIntent()->getDestination() === ['北海道'], 'execute: authoritative destination preserved');
lm_assert(count($authResult->getSearchResults()) > 0, 'execute: authoritative search ran');

// --- Product Execute: authoritative clarification (no search) ---
$authClarify = $service->buildTourContextResult([
    'userText' => '我想去東京自由行',
    'sno' => $pilotSno,
    'featureEnabled' => true,
    'referenceDate' => new DateTimeImmutable('2026-08-01', new DateTimeZone('Asia/Taipei')),
    'searchClient' => $mockSearchClient,
    'authoritativeIntent' => $batsNoDate,
]);
lm_assert($authClarify->isClarificationRequired() === true, 'execute: authoritative clarification required');
lm_assert(count($authClarify->getSearchResults()) === 0, 'execute: authoritative clarification no search');

// --- Router E2E: AIU authoritative path drives Product Search (no legacy builder) ---
$routerSearchCalls = 0;
$routerSearchClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function () use (&$routerSearchCalls): array {
    ++$routerSearchCalls;

    return [
        'ok' => true,
        'http_status' => 200,
        'body' => json_encode([
            'success' => true,
            'pagination' => ['total' => 1],
            'items' => [['title' => '北海道8月團', 'tourDate' => '2026-08-10', 'price' => 42800]],
            'search_url' => 'https://example.test/search',
            'error' => null,
        ], JSON_UNESCAPED_UNICODE),
        'transport_error' => null,
    ];
});
$routerLineCalls = [];
$routerReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$routerLineCalls): array {
    unset($url, $token);
    $routerLineCalls[] = ['text' => $text];
    return ['mock' => true];
};
$routerTenant = [
    'sno' => $pilotSno,
    'company_name' => 'Travel B Pilot',
    'channel_id' => 'travel-b-channel',
    'tenant_key' => 'travel_b',
];
$routerResult = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $routerTenant,
    'BATS測試 北海道 8月',
    'trace-lastmile-router',
    'reply-token-router',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-router',
    'travel-b-channel',
    null,
    $routerSearchClient,
    new DateTimeImmutable('2026-08-01', new DateTimeZone('Asia/Taipei')),
    $routerReplySender,
    null,
    null,
    $cid,
    null,
    null,
    null,
    'U-lastmile-router',
    null,
    null,
    null,
    $runtime
);
lm_assert(($routerResult['message'] ?? '') === 'phase_9c1_structured_pilot', 'router: structured pilot route');
lm_assert(
    ($routerResult['phase_9c1']['runtime_source'] ?? '') === AiIntentUnderstandingRuntimeSelector::SOURCE_AIU,
    'router: runtime_source aiu'
);
lm_assert(($routerResult['phase_9c1']['authoritative_product_path'] ?? false) === true, 'router: authoritative product path');
lm_assert($routerSearchCalls === 1, 'router: product search executed once');
lm_assert(count($routerLineCalls) === 1, 'router: LINE reply once');

if ($failures === 0) {
    echo "ALL PASS test_aiu_v2_last_mile\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_v2_last_mile\n");
exit(1);
