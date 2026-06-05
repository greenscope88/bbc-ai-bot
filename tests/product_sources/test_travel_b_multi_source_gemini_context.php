<?php
declare(strict_types=1);

/**
 * Phase 9-B-27: travel_b multi-source URLs in Gemini context (no LINE / Gemini API).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';

$failures = 0;

function bridge_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$travelBSno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;
$userText = '東京五日';

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
bridge_assert(strpos($contextOn, '/searchlist/tpetsa/') !== false, 'flag on: bbctravel default path tpetsa');
bridge_assert(
    strpos($contextOn, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($contextOn, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    'flag on: bbctravel q URL-encoded 東京'
);
bridge_assert(strpos($contextOn, 'datefrom=') === false && strpos($contextOn, 'dateto=') === false, 'flag on: no date params in multi-source URLs');
bridge_assert(strpos($contextOn, 'dayitourcenter.com.tw') !== false, 'flag on: dayitourcenter host');

$condition = (new HybridSearchConditionBuilder())->parse($userText, ['merge_legacy_keyword' => true]);
$links = (new TravelBMultiSourceLinkBuilder($multiSourceConfigOn))->buildFromHybridCondition($condition, $travelBSno);
bridge_assert(count($links) === 3, 'builder returns 3 links for 東京五日');

$bbctravelUrlTokyo = '';
foreach ($links as $row) {
    if (($row['platform'] ?? '') === 'bbctravel') {
        $bbctravelUrlTokyo = (string) ($row['search_url'] ?? '');
        break;
    }
}
bridge_assert(strpos($bbctravelUrlTokyo, '/searchlist/tpetsa/') !== false, '東京五日: bbctravel path tpetsa default');
bridge_assert(
    strpos($bbctravelUrlTokyo, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($bbctravelUrlTokyo, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    '東京五日: bbctravel q encoded 東京'
);
bridge_assert(strpos($bbctravelUrlTokyo, 'datefrom=') === false && strpos($bbctravelUrlTokyo, 'dateto=') === false, '東京五日: no date query');

$hybridBuilder = new HybridSearchConditionBuilder();
$multiBuilder = new TravelBMultiSourceLinkBuilder($multiSourceConfigOn);

function bbctravel_url_from_links(array $links): string
{
    foreach ($links as $row) {
        if (($row['platform'] ?? '') === 'bbctravel') {
            return (string) ($row['search_url'] ?? '');
        }
    }

    return '';
}

$kaohsiungUrl = bbctravel_url_from_links(
    $multiBuilder->buildFromHybridCondition(
        $hybridBuilder->parse('高雄出發東京', ['merge_legacy_keyword' => true]),
        $travelBSno
    )
);
bridge_assert(strpos($kaohsiungUrl, '/searchlist/khh/') !== false, '高雄出發東京: bbctravel path khh');
bridge_assert(
    strpos($kaohsiungUrl, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($kaohsiungUrl, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    '高雄出發東京: bbctravel q encoded 東京'
);

$tainanUrl = bbctravel_url_from_links(
    $multiBuilder->buildFromHybridCondition(
        $hybridBuilder->parse('台南出發東京', ['merge_legacy_keyword' => true]),
        $travelBSno
    )
);
bridge_assert(strpos($tainanUrl, '/searchlist/tnn/') !== false, '台南出發東京: bbctravel path tnn');
bridge_assert(
    strpos($tainanUrl, 'q=%E6%9D%B1%E4%BA%AC') !== false || strpos($tainanUrl, 'q=%e6%9d%b1%e4%ba%ac') !== false,
    '台南出發東京: bbctravel q encoded 東京'
);

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

fwrite(STDOUT, "OK: test_travel_b_multi_source_gemini_context (東京五日 → 3 URLs in context)\n");
exit(0);
