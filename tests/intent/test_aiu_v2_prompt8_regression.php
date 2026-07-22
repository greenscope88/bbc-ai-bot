<?php
declare(strict_types=1);

/**
 * Prompt 8 Gold — AIU v2 regression (Understanding Core parity + routing).
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiRuntimeIntent.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeSelector.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuGeminiUnderstandingClientStub.php';
require_once $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'AiuGoldUtteranceUnderstandingFixtures.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'saas_router.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'Phase9C1FeatureGate.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'ProductRecommendationBuilder.php';

$failures = 0;

function p8_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$now = new DateTimeImmutable('2026-08-01', $tz);
$pilotSno = Phase9C1FeatureGate::TRAVEL_B_SNO;
$cid = $pilotSno . ':line:U-prompt8';
$runtime = AiIntentUnderstandingRuntime::createForTesting(
    new AiuGeminiUnderstandingClientStub(AiuGoldUtteranceUnderstandingFixtures::resolver())
);

// 1. 火星五日遊 8月 vs 火星五日遊8月 — understanding parity
$marsA = $runtime->understand('火星五日遊 8月', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
$marsB = $runtime->understand('火星五日遊8月', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
p8_assert($marsA->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'marsA intent product');
p8_assert($marsB->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'marsB intent product');
p8_assert(($marsA->getEntities()['destination'][0] ?? '') === '火星', 'marsA destination');
p8_assert(($marsB->getEntities()['destination'][0] ?? '') === '火星', 'marsB destination');
p8_assert($marsA->getIntent() === $marsB->getIntent(), 'mars intent parity');
p8_assert($marsA->isClarificationRequired() === $marsB->isClarificationRequired(), 'mars clarification parity');
p8_assert(!array_key_exists('dispatch_plan', $marsA->toArray()), 'mars no dispatch_plan in contract');

$hokkaido = $runtime->understand('北海道 8月', ['conversation_id' => $cid, 'tenant_sno' => $pilotSno]);
p8_assert($hokkaido->getIntent() === AiIntentCategory::PRODUCT_SEARCH, 'hokkaido intent');
p8_assert(!array_key_exists('dispatch_plan', $hokkaido->toArray()), 'hokkaido no dispatch_plan');
p8_assert(($hokkaido->getEntities()['destination'][0] ?? '') === '北海道', 'hokkaido destination');

// 3. 我要找真人客服 — human service legacy mapping
$flagOn = [
    AiIntentUnderstandingRuntimeSelector::FLAG_ENABLED => true,
    AiIntentUnderstandingRuntimeSelector::FLAG_TENANTS => [$pilotSno],
];
$humanSel = AiIntentUnderstandingRuntimeSelector::resolve([
    'tenant_sno' => $pilotSno,
    'conversation_id' => $cid,
    'message' => '我要找真人客服',
    'now' => $now,
    'config' => $flagOn,
], $runtime);
p8_assert(
    ($humanSel['intent_type'] ?? '') === AiRuntimeIntent::HUMAN_SERVICE_REQUEST,
    'human service authoritative mapping'
);

// 4. 查無商品 — recommendation must not invent other destinations
$builder = new ProductRecommendationBuilder();
$intentMars = [
    'destination' => '火星',
    'free_text' => '火星五日遊8月',
];
$summaryEmpty = $builder->build([], $intentMars, '火星五日遊8月');
$reason = (string) ($summaryEmpty['recommendation_reason'] ?? '');
p8_assert((int) ($summaryEmpty['result_count'] ?? -1) === 0, 'mars empty result_count');
p8_assert(mb_strpos($reason, '北海道', 0, 'UTF-8') === false, 'mars empty: no 北海道 in reason');
p8_assert(mb_strpos($reason, '東京', 0, 'UTF-8') === false, 'mars empty: no 東京 in reason');

// 5. SaaSRouter human service route (authoritative path)
$humanStore = [];
$humanResolver = ConversationStatusResolver::createForTesting($humanStore);
$humanLineCalls = [];
$humanReplySender = static function (string $url, string $token, string $replyToken, string $text) use (&$humanLineCalls): array {
    unset($url, $token);
    $humanLineCalls[] = ['text' => $text];
    return ['mock' => true];
};
$tenantTravelB = [
    'sno' => $pilotSno,
    'company_name' => 'Travel B Pilot',
    'channel_id' => 'travel-b-channel',
    'tenant_key' => 'travel_b',
];
$humanRoute = SaaSRouter::attemptPhase9C1StructuredPilotPath(
    $tenantTravelB,
    'BATS測試我要找真人客服',
    'trace-p8-human',
    'reply-token-p8',
    'https://api.line.me/v2/bot/message/reply',
    'channel-token-p8',
    'travel-b-channel',
    null,
    new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
        return ['ok' => true, 'http_status' => 200, 'body' => '{}', 'transport_error' => null];
    }),
    $now,
    $humanReplySender,
    null,
    null,
    'p8-human-conv',
    $humanResolver,
    null,
    null,
    'U-p8-human',
    null,
    null,
    null,
    $runtime
);
p8_assert(($humanRoute['message'] ?? '') === 'phase_9c2b3_knowledge_human_service_request', 'router human service route');
p8_assert(count($humanLineCalls) === 1, 'router human service reply once');

if ($failures === 0) {
    echo "ALL PASS test_aiu_v2_prompt8_regression\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_v2_prompt8_regression\n");
exit(1);
