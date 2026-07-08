<?php
declare(strict_types=1);

/**
 * AIU v2 Date Clarification — regression (Prompt 8 Gold).
 */

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntime.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'intent'
    . DIRECTORY_SEPARATOR . 'AiuProductIntentTranslator.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'BatsSearchIntentMapper.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'SearchUrlBuilder.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search'
    . DIRECTORY_SEPARATOR . 'ClarificationPolicy.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source'
    . DIRECTORY_SEPARATOR . 'recommendation' . DIRECTORY_SEPARATOR . 'ProductRecommendationBuilder.php';

$failures = 0;

function dc_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$tz = new DateTimeZone('Asia/Taipei');
$ref = new DateTimeImmutable('2026-07-08', $tz);
$pilotSno = '5f99b8d665e8444d';
$cid = $pilotSno . ':line:U-date-clarify';
$runtime = AiIntentUnderstandingRuntime::createForTesting();
$translator = new AiuProductIntentTranslator();
$mapper = new BatsSearchIntentMapper();
$urlBuilder = new SearchUrlBuilder(false);

$ctx = [
    'conversation_id' => $cid,
    'tenant_sno' => $pilotSno,
    'now' => $ref,
    'reference_date' => $ref,
];

function runDateCase(
    AiIntentUnderstandingRuntime $runtime,
    AiuProductIntentTranslator $translator,
    BatsSearchIntentMapper $mapper,
    SearchUrlBuilder $urlBuilder,
    array $ctx,
    string $sno,
    string $utterance
): array {
    $aiu = $runtime->understand($utterance, $ctx);
    $bats = $translator->translate($aiu);
    $cond = $mapper->toSearchCondition($bats);
    $url = $cond !== null ? $urlBuilder->build($sno, $cond) : '';

    return [
        'aiu' => $aiu,
        'bats' => $bats,
        'cond' => $cond,
        'url' => $url,
    ];
}

// 1. 北海道 9月出發
$c1 = runDateCase($runtime, $translator, $mapper, $urlBuilder, $ctx, $pilotSno, '北海道 9月出發');
dc_assert($c1['aiu']->isClarificationRequired() === false, 'case1: no clarification');
dc_assert($c1['bats']->getDateFrom() === '2026-09-01', 'case1: date_from');
dc_assert($c1['bats']->getDateTo() === '2026-09-30', 'case1: date_to');
dc_assert(strpos($c1['url'], 'dateFrom=2026-09-01') !== false, 'case1: url dateFrom');
dc_assert(strpos($c1['url'], 'dateTo=2026-09-30') !== false, 'case1: url dateTo');

// 2. 北海道 2026年9月出發
$c2 = runDateCase($runtime, $translator, $mapper, $urlBuilder, $ctx, $pilotSno, '北海道 2026年9月出發');
dc_assert($c2['aiu']->isClarificationRequired() === false, 'case2: no clarification');
dc_assert($c2['bats']->getDateFrom() === '2026-09-01', 'case2: date_from');
dc_assert($c2['bats']->getDateTo() === '2026-09-30', 'case2: date_to');

// 3-5. 月初 / 月中 / 月底
$c3 = runDateCase($runtime, $translator, $mapper, $urlBuilder, $ctx, $pilotSno, '北海道 9月初出發');
dc_assert($c3['aiu']->isClarificationRequired() === false, 'case3: no clarification');
dc_assert($c3['bats']->getDateFrom() === '2026-09-01', 'case3: date_from');
dc_assert($c3['bats']->getDateTo() === '2026-09-10', 'case3: date_to');

$c4 = runDateCase($runtime, $translator, $mapper, $urlBuilder, $ctx, $pilotSno, '北海道 9月中出發');
dc_assert($c4['aiu']->isClarificationRequired() === false, 'case4: no clarification');
dc_assert($c4['bats']->getDateFrom() === '2026-09-11', 'case4: date_from');
dc_assert($c4['bats']->getDateTo() === '2026-09-20', 'case4: date_to');

$c5 = runDateCase($runtime, $translator, $mapper, $urlBuilder, $ctx, $pilotSno, '北海道 9月底出發');
dc_assert($c5['aiu']->isClarificationRequired() === false, 'case5: no clarification');
dc_assert($c5['bats']->getDateFrom() === '2026-09-21', 'case5: date_from');
dc_assert($c5['bats']->getDateTo() === '2026-09-30', 'case5: date_to');

// 6. 北海道 — must trigger date clarification
$c6 = runDateCase($runtime, $translator, $mapper, $urlBuilder, $ctx, $pilotSno, '北海道');
dc_assert($c6['aiu']->isClarificationRequired() === true, 'case6: clarification required');
dc_assert(
    $c6['bats']->getClarificationReason() === ClarificationPolicy::REASON_DATE_REQUIRED,
    'case6: date_required reason'
);

// Golden utterance
$gold = runDateCase(
    $runtime,
    $translator,
    $mapper,
    $urlBuilder,
    $ctx,
    $pilotSno,
    'BATS測試 我想去北海道玩5天，9月出發'
);
dc_assert($gold['aiu']->isClarificationRequired() === false, 'gold: no clarification');
dc_assert($gold['bats']->getDateFrom() === '2026-09-01', 'gold: date_from');
dc_assert($gold['bats']->getDateTo() === '2026-09-30', 'gold: date_to');
dc_assert(($gold['aiu']->getEntity()['destination'] ?? '') === '北海道', 'gold: destination');

// 7. Product Search 0 results — must not ask for date
$mockSearchClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 0],
        'items' => [],
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
$zeroResult = $service->buildTourContextResult([
    'userText' => '北海道 9月出發',
    'sno' => $pilotSno,
    'featureEnabled' => true,
    'referenceDate' => $ref,
    'searchClient' => $mockSearchClient,
    'authoritativeIntent' => $c1['bats'],
]);
dc_assert($zeroResult->isClarificationRequired() === false, 'case7: zero results no date clarification');
dc_assert(count($zeroResult->getSearchResults()) === 0, 'case7: zero search results');

$builder = new ProductRecommendationBuilder();
$summary = $builder->build([], $c1['bats']->toArray(), '北海道 9月出發');
$reason = (string) ($summary['recommendation_reason'] ?? '');
dc_assert(mb_strpos($reason, '出發', 0, 'UTF-8') === false || mb_strpos($reason, '日期', 0, 'UTF-8') === false, 'case7: recommendation does not ask for date');

// 8. BonusMee URL dates
dc_assert(strpos($c1['url'], 'dateFrom=2026-09-01') !== false, 'case8: bonusmee dateFrom');
dc_assert(strpos($c1['url'], 'dateTo=2026-09-30') !== false, 'case8: bonusmee dateTo');

if ($failures === 0) {
    echo "ALL PASS test_aiu_v2_date_clarification\n";
    exit(0);
}

fwrite(STDERR, "{$failures} FAILURE(S) in test_aiu_v2_date_clarification\n");
exit(1);