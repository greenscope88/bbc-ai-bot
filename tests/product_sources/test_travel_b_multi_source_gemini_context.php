<?php
declare(strict_types=1);

/**
 * Phase 9-B-27: travel_b multi-source URLs in Gemini context (no LINE / Gemini API).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

$failures = 0;

function bridge_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function hybrid_has_resolved_dates(?string $dateFrom, ?string $dateTo): bool
{
    $from = $dateFrom !== null ? trim($dateFrom) : '';
    $to = $dateTo !== null ? trim($dateTo) : '';

    return $from !== '' || $to !== '';
}

function assert_bbctravel_date_mapping(?string $dateFrom, ?string $dateTo, string $url, string $label): void
{
    if (!hybrid_has_resolved_dates($dateFrom, $dateTo)) {
        bridge_assert(
            preg_match('/datefrom=\d{4}-\d{2}-\d{2}/', $url) !== 1,
            $label . ': bbctravel URL must not include resolved datefrom when SearchCondition dates are null'
        );
        bridge_assert(
            preg_match('/dateto=\d{4}-\d{2}-\d{2}/', $url) !== 1,
            $label . ': bbctravel URL must not include resolved dateto when SearchCondition dates are null'
        );

        return;
    }

    if ($dateFrom !== null && trim($dateFrom) !== '') {
        bridge_assert(
            strpos($url, 'datefrom=' . trim($dateFrom)) !== false,
            $label . ': bbctravel URL must map date_from to datefrom=' . trim($dateFrom)
        );
    }

    if ($dateTo !== null && trim($dateTo) !== '') {
        bridge_assert(
            strpos($url, 'dateto=' . trim($dateTo)) !== false,
            $label . ': bbctravel URL must map date_to to dateto=' . trim($dateTo)
        );
    }
}

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';

$travelBSno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;
$referenceDate = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
$userText = '六月底東京';

$multiSourceConfigOn = [
    'enabled' => true,
    'tenant_sno' => $travelBSno,
    'source_instance_keys' => [
        'dayitravel_grp',
        'dayitravel_bbctravel',
        'dayitravel_tourcenter',
    ],
];

$hybridConfigOn = [
    'enabled' => true,
    'allowed_sno' => [$travelBSno],
    'allowed_channels' => [],
    'dry_run_log_enabled' => false,
];

$hybridBuilder = new HybridSearchConditionBuilder(new DateParser($referenceDate));
$multiBuilder = new TravelBMultiSourceLinkBuilder($multiSourceConfigOn);
$searchCondition = $hybridBuilder->parse($userText, ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);

$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 2],
        'items' => [
            ['title' => '東京五日精選 A', 'tourDate' => '2026-07-10', 'price' => 45000],
            ['title' => '東京五日精選 B', 'tourDate' => '2026-07-12', 'price' => 46000],
        ],
        'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=%E6%9D%B1%E4%BA%AC',
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
$contextOff = $service->buildTourContextForPrompt([
    'userText' => $userText,
    'sno' => $travelBSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'hybridSearchConfig' => $hybridConfigOn,
    'travelBMultiSourceLinksConfig' => ['enabled' => false, 'tenant_sno' => $travelBSno],
    'referenceDate' => $referenceDate,
    'includeInstructions' => false,
]);

bridge_assert($contextOff !== '', 'flag off still builds tour context');
bridge_assert(
    strpos($contextOff, GeminiTourContextBuilder::MULTI_SOURCE_LINKS_LABEL) === false,
    'flag off: no multi-source block'
);

$contextOn = $service->buildTourContextForPrompt([
    'userText' => $userText,
    'sno' => $travelBSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'hybridSearchConfig' => $hybridConfigOn,
    'travelBMultiSourceLinksConfig' => $multiSourceConfigOn,
    'referenceDate' => $referenceDate,
]);

bridge_assert($contextOn !== '', 'flag on: context non-empty');
bridge_assert(
    strpos($contextOn, GeminiTourContextBuilder::MULTI_SOURCE_LINKS_LABEL) !== false,
    'flag on: multi-source label present'
);
bridge_assert(strpos($contextOn, 'agenttour:') === false, 'flag on: no agenttour line');
bridge_assert(strpos($contextOn, 'grp:') !== false, 'flag on: grp line');
bridge_assert(strpos($contextOn, 'bbctravel:') !== false, 'flag on: bbctravel line');
bridge_assert(strpos($contextOn, 'tourcenter:') !== false, 'flag on: tourcenter line');
bridge_assert(strpos($contextOn, 'rechoice-travel.agenttour.com.tw') === false, 'flag on: no agenttour URL host');
bridge_assert(strpos($contextOn, 'dayitravel.grp.com.tw') !== false, 'flag on: dayitravel grp host');
bridge_assert(strpos($contextOn, 'dayitravel.bbctravel.com.tw') !== false, 'flag on: dayitravel bbctravel host');
bridge_assert(strpos($contextOn, '/searchlist/all/') !== false, 'flag on: bbctravel unlimited departure path all');
bridge_assert(
    strpos($contextOn, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($contextOn, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    'flag on: bbctravel q URL-encoded 東京'
);
bridge_assert(strpos($contextOn, 'dayitourcenter.com.tw') !== false, 'flag on: dayitourcenter host');

$links = $multiBuilder->buildFromHybridCondition($searchCondition, $travelBSno);
bridge_assert(count($links) === 3, 'builder returns 3 links for 六月底東京');

$bbctravelUrlTokyo = '';
foreach ($links as $row) {
    if (($row['platform'] ?? '') === 'bbctravel') {
        $bbctravelUrlTokyo = (string) ($row['search_url'] ?? '');
        break;
    }
}
bridge_assert(strpos($bbctravelUrlTokyo, '/searchlist/all/') !== false, '六月底東京: bbctravel path all (no departure)');
bridge_assert(
    strpos($bbctravelUrlTokyo, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($bbctravelUrlTokyo, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    '六月底東京: bbctravel q encoded 東京'
);
assert_bbctravel_date_mapping(
    $searchCondition->getDateFrom(),
    $searchCondition->getDateTo(),
    $bbctravelUrlTokyo,
    '六月底東京'
);
assert_bbctravel_date_mapping(
    $searchCondition->getDateFrom(),
    $searchCondition->getDateTo(),
    $contextOn,
    'flag on: context bbctravel date mapping'
);

function bbctravel_url_from_links(array $links): string
{
    foreach ($links as $row) {
        if (($row['platform'] ?? '') === 'bbctravel') {
            return (string) ($row['search_url'] ?? '');
        }
    }

    return '';
}

$kaohsiungCondition = $hybridBuilder->parse('高雄出發東京', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
$kaohsiungUrl = bbctravel_url_from_links(
    $multiBuilder->buildFromHybridCondition(
        $kaohsiungCondition,
        $travelBSno
    )
);
bridge_assert(strpos($kaohsiungUrl, '/searchlist/khh/') !== false, '高雄出發東京: bbctravel path khh');
bridge_assert(
    strpos($kaohsiungUrl, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($kaohsiungUrl, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    '高雄出發東京: bbctravel q encoded 東京'
);

assert_bbctravel_date_mapping(
    $kaohsiungCondition->getDateFrom(),
    $kaohsiungCondition->getDateTo(),
    $kaohsiungUrl,
    '高雄出發東京'
);

$tainanCondition = $hybridBuilder->parse('台南出發東京', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
$tainanUrl = bbctravel_url_from_links(
    $multiBuilder->buildFromHybridCondition(
        $tainanCondition,
        $travelBSno
    )
);
bridge_assert(strpos($tainanUrl, '/searchlist/tnn/') !== false, '台南出發東京: bbctravel path tnn');
bridge_assert(
    strpos($tainanUrl, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($tainanUrl, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    '台南出發東京: bbctravel q encoded 東京'
);

assert_bbctravel_date_mapping(
    $tainanCondition->getDateFrom(),
    $tainanCondition->getDateTo(),
    $tainanUrl,
    '台南出發東京'
);

function assert_bbctravel_url_contains(string $url, string $label, array $fragments): void
{
    foreach ($fragments as $fragment) {
        if ($fragment === 'q=東京') {
            bridge_assert(
                strpos($url, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($url, 'q=%e6%9d%b1%e4%ba%ac') !== false,
                $label . ': bbctravel URL must contain encoded q=東京'
            );
            continue;
        }
        bridge_assert(strpos($url, $fragment) !== false, $label . ': bbctravel URL must contain ' . $fragment);
    }
}

$tainanBareCondition = $hybridBuilder->parse('台南出發東京6月底', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
$tainanBareUrl = bbctravel_url_from_links($multiBuilder->buildFromHybridCondition($tainanBareCondition, $travelBSno));
bridge_assert(strpos($tainanBareUrl, '/searchlist/tnn/') !== false, '台南出發東京6月底: bbctravel path tnn');

$taichungCondition = $hybridBuilder->parse('台中出發東京6月底', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
$taichungUrl = bbctravel_url_from_links($multiBuilder->buildFromHybridCondition($taichungCondition, $travelBSno));
bridge_assert(strpos($taichungUrl, '/searchlist/RMG/') !== false, '台中出發東京6月底: bbctravel path RMG');

// Phase C-1B: bare prefix + BBCTravel departure mapping from real Hybrid parse
$case1Condition = $hybridBuilder->parse('高雄東京6月底', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
bridge_assert($case1Condition->getDepartureCity() === '高雄', 'case1 parse: 高雄東京6月底 departure 高雄');
$case1Url = bbctravel_url_from_links($multiBuilder->buildFromHybridCondition($case1Condition, $travelBSno));
assert_bbctravel_url_contains($case1Url, 'case1 高雄東京6月底', [
    '/searchlist/khh/',
    'q=東京',
    'datefrom=2026-06-21',
    'dateto=2026-06-30',
    'order=1',
    'standby=1',
]);

$case2Condition = $hybridBuilder->parse('台南東京6月底', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
$case2Url = bbctravel_url_from_links($multiBuilder->buildFromHybridCondition($case2Condition, $travelBSno));
assert_bbctravel_url_contains($case2Url, 'case2 台南東京6月底', [
    '/searchlist/tnn/',
    'q=東京',
    'datefrom=2026-06-21',
    'dateto=2026-06-30',
    'order=1',
    'standby=1',
]);

$case3Condition = $hybridBuilder->parse('台中東京6月底', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
$case3Url = bbctravel_url_from_links($multiBuilder->buildFromHybridCondition($case3Condition, $travelBSno));
assert_bbctravel_url_contains($case3Url, 'case3 台中東京6月底', [
    '/searchlist/RMG/',
    'q=東京',
    'datefrom=2026-06-21',
    'dateto=2026-06-30',
    'order=1',
    'standby=1',
]);

$case4Condition = $hybridBuilder->parse('東京6月底', ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
bridge_assert($case4Condition->getDepartureCity() === null, 'case4 parse: 東京6月底 departure null');
$case4Url = bbctravel_url_from_links($multiBuilder->buildFromHybridCondition($case4Condition, $travelBSno));
assert_bbctravel_url_contains($case4Url, 'case4 東京6月底', [
    '/searchlist/all/',
    'q=東京',
    'datefrom=2026-06-21',
    'dateto=2026-06-30',
    'order=1',
    'standby=1',
]);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';
$lineReply = TourFallbackFormatter::formatFromTourContext($contextOn);
bridge_assert(strpos($lineReply, '更多商品來源') !== false, 'formatter: multi-source header');
bridge_assert(strpos($lineReply, 'agenttour') === false, 'formatter: no agenttour');
bridge_assert(strpos($lineReply, 'grp') !== false && strpos($lineReply, 'dayitravel.grp.com.tw') !== false, 'formatter: grp');
bridge_assert(strpos($lineReply, 'bbctravel') !== false && strpos($lineReply, 'dayitravel.bbctravel.com.tw') !== false, 'formatter: bbctravel');
bridge_assert(strpos($lineReply, 'tourcenter') !== false && strpos($lineReply, 'dayitourcenter.com.tw') !== false, 'formatter: tourcenter');
bridge_assert(strpos($lineReply, GeminiTourContextBuilder::SEARCH_URL_LABEL) !== false, 'formatter: legacy search label preserved');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_travel_b_multi_source_gemini_context (六月底東京 → 3 URLs in context)\n");
exit(0);
