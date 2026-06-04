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
        'rechoice_agenttour',
        'dayitravel_grp',
        'dayitravel_bbctravel',
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
bridge_assert(strpos($contextOn, 'agenttour:') !== false, 'flag on: agenttour line');
bridge_assert(strpos($contextOn, 'grp:') !== false, 'flag on: grp line');
bridge_assert(strpos($contextOn, 'bbctravel:') !== false, 'flag on: bbctravel line');
bridge_assert(strpos($contextOn, 'agenttour.com.tw') !== false, 'flag on: agenttour URL host');
bridge_assert(strpos($contextOn, 'grp.com.tw') !== false, 'flag on: grp URL host');
bridge_assert(strpos($contextOn, 'bbctravel.com.tw') !== false, 'flag on: bbctravel URL host');

$condition = (new HybridSearchConditionBuilder())->parse($userText, ['merge_legacy_keyword' => true]);
$links = (new TravelBMultiSourceLinkBuilder($multiSourceConfigOn))->buildFromHybridCondition($condition, $travelBSno);
bridge_assert(count($links) === 3, 'builder returns 3 links for 東京五日');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_travel_b_multi_source_gemini_context (東京五日 → 3 URLs in context)\n");
exit(0);
