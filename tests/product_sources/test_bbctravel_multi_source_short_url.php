<?php
declare(strict_types=1);

/**
 * BBCTravel Golden Reference: multi-source search URL → bbcshops short URL in LINE context.
 * Uses MockShortUrlProvider (no bs_ShortUrl writes).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'HybridSearchConditionBuilder.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'DateParser.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MockShortUrlProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';

$failures = 0;

function short_url_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

function bbctravel_long_url_from_links(array $links): string
{
    foreach ($links as $row) {
        if (($row['platform'] ?? '') === 'bbctravel') {
            return (string) ($row['search_url'] ?? '');
        }
    }

    return '';
}

function bbctravel_line_from_context(string $context): string
{
    foreach (preg_split("/\r\n|\n|\r/", $context) ?: [] as $line) {
        if (preg_match('/^bbctravel:\s*(https?:\/\/\S+)/u', trim($line), $m) === 1) {
            return trim($m[1]);
        }
    }

    return '';
}

$travelBSno = TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO;
$mockProvider = new MockShortUrlProvider();
$multiSourceConfig = [
    'enabled' => true,
    'tenant_sno' => $travelBSno,
    'source_instance_keys' => ['dayitravel_bbctravel'],
];
$hybridConfig = [
    'enabled' => true,
    'allowed_sno' => [$travelBSno],
    'allowed_channels' => [],
    'dry_run_log_enabled' => false,
];

$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [
            ['title' => '大阪精選', 'tourDate' => '2026-07-10', 'price' => 35000],
        ],
        'search_url' => 'https://bonusmee.com/view/cloud/cloud_store_tourdate.php?keyword=test',
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
$multiBuilder = new TravelBMultiSourceLinkBuilder($multiSourceConfig);

/**
 * @return array{0: string, 1: string, 2: string}
 */
function build_bbctravel_case(
    TourPromptContextService $service,
    TravelBMultiSourceLinkBuilder $multiBuilder,
    string $userText,
    DateTimeImmutable $referenceDate,
    array $hybridConfig,
    array $multiSourceConfig,
    MockShortUrlProvider $mockProvider,
    TourSearchApiClient $mockClient,
    string $travelBSno
): array {
    $hybridBuilder = new HybridSearchConditionBuilder(new DateParser($referenceDate));
    $condition = $hybridBuilder->parse($userText, ['merge_legacy_keyword' => true, 'reference_date' => $referenceDate]);
    $longUrl = bbctravel_long_url_from_links($multiBuilder->buildFromHybridCondition($condition, $travelBSno));

    $context = $service->buildTourContextForPrompt([
        'userText' => $userText,
        'sno' => $travelBSno,
        'featureEnabled' => true,
        'searchClient' => $mockClient,
        'hybridSearchConfig' => $hybridConfig,
        'travelBMultiSourceLinksConfig' => $multiSourceConfig,
        'multiSourceShortUrlProvider' => $mockProvider,
        'referenceDate' => $referenceDate,
        'includeInstructions' => false,
    ]);

    $displayUrl = bbctravel_line_from_context($context);
    $lineReply = TourFallbackFormatter::formatFromTourContext($context);

    return [$longUrl, $displayUrl, $lineReply];
}

// Case 1: 大阪近期 → /all/ + fuzzy dates; LINE shows bbcshops short URL
$refCase1 = new DateTimeImmutable('2026-06-06', new DateTimeZone('Asia/Taipei'));
[$long1, $short1, $line1] = build_bbctravel_case(
    $service,
    $multiBuilder,
    '大阪近期',
    $refCase1,
    $hybridConfig,
    $multiSourceConfig,
    $mockProvider,
    $mockClient,
    $travelBSno
);
short_url_assert(strpos($long1, '/searchlist/all/') !== false, 'case1: long URL path /all/');
short_url_assert(strpos($long1, 'q=%E5%A4%A7%E9%98%AA') !== false || strpos($long1, 'q=%e5%a4%a7%e9%98%aa') !== false, 'case1: long URL q=大阪');
short_url_assert(strpos($long1, 'datefrom=2026-06-06') !== false, 'case1: long URL datefrom=2026-06-06');
short_url_assert(strpos($long1, 'dateto=2026-08-05') !== false, 'case1: long URL dateto=2026-08-05');
short_url_assert(strpos($short1, 'https://bbcshops.com/') === 0, 'case1: context bbctravel short URL host');
short_url_assert(strpos($short1, 'dayitravel.bbctravel.com.tw') === false, 'case1: context no long bbctravel host');
short_url_assert($short1 === $mockProvider->shortenSearchUrl($long1, [
    'source_id' => 'bbctravel',
    'short_url_domain' => 'bbcshops.com',
    'domain_namespace' => 'bbcshops',
    'product_category' => 'group_tour',
]), 'case1: short URL matches MockShortUrlProvider');
short_url_assert(strpos($line1, '🔎 更多【大阪】行程 & 出團日：') !== false, 'case1: LINE search footer with 大阪');
short_url_assert(strpos($line1, '📢 更多【大阪】行程如下，歡迎利用以下網頁直接線上報名：') !== false, 'case1: LINE CTA footer with 大阪');
short_url_assert(strpos($line1, '👉 https://bbcshops.com/') !== false, 'case1: LINE CTA url prefix');
short_url_assert(strpos($line1, 'https://bbcshops.com/') !== false, 'case1: LINE reply shows bbcshops short URL');
short_url_assert(strpos($line1, 'dayitravel.bbctravel.com.tw') === false, 'case1: LINE reply no long bbctravel host');
short_url_assert(strpos($line1, 'bbctravel：') === false, 'case1: LINE reply no platform label');

// Case 2: 高雄東京6月底 → /khh/
$refCase2 = new DateTimeImmutable('2026-06-05', new DateTimeZone('Asia/Taipei'));
[$long2, $short2, $line2] = build_bbctravel_case(
    $service,
    $multiBuilder,
    '高雄東京6月底',
    $refCase2,
    $hybridConfig,
    $multiSourceConfig,
    $mockProvider,
    $mockClient,
    $travelBSno
);
short_url_assert(strpos($long2, '/searchlist/khh/') !== false, 'case2: long URL path /khh/');
short_url_assert(strpos($short2, 'https://bbcshops.com/') === 0, 'case2: context short URL');
short_url_assert(strpos($line2, 'https://bbcshops.com/') !== false, 'case2: LINE reply short URL');
short_url_assert(strpos($line2, '/searchlist/khh/') === false, 'case2: LINE reply no long path');

// Case 3: 台中東京6月底 → /RMG/
[$long3, $short3, $line3] = build_bbctravel_case(
    $service,
    $multiBuilder,
    '台中東京6月底',
    $refCase2,
    $hybridConfig,
    $multiSourceConfig,
    $mockProvider,
    $mockClient,
    $travelBSno
);
short_url_assert(strpos($long3, '/searchlist/RMG/') !== false, 'case3: long URL path /RMG/');
short_url_assert(strpos($short3, 'https://bbcshops.com/') === 0, 'case3: context short URL');
short_url_assert(strpos($line3, 'https://bbcshops.com/') !== false, 'case3: LINE reply short URL');
short_url_assert(strpos($line3, '/searchlist/RMG/') === false, 'case3: LINE reply no long path');

function platform_url_from_context(string $context, string $platform): string
{
    foreach (preg_split("/\r\n|\n|\r/", $context) ?: [] as $line) {
        if (preg_match('/^' . preg_quote($platform, '/') . ':\s*(https?:\/\/\S+)/u', trim($line), $m) === 1) {
            return trim($m[1]);
        }
    }

    return '';
}

// Unified short URL: bbctravel + grp + tourcenter
$fullMultiConfig = [
    'enabled' => true,
    'tenant_sno' => $travelBSno,
    'source_instance_keys' => ['dayitravel_grp', 'dayitravel_bbctravel', 'dayitravel_tourcenter'],
];
$multiBuilderFull = new TravelBMultiSourceLinkBuilder($fullMultiConfig);
$hybridBuilderFull = new HybridSearchConditionBuilder(new DateParser($refCase2));
$conditionFull = $hybridBuilderFull->parse('六月底東京', ['merge_legacy_keyword' => true, 'reference_date' => $refCase2]);
$longGrp = '';
$longTourcenter = '';
foreach ($multiBuilderFull->buildFromHybridCondition($conditionFull, $travelBSno) as $row) {
    if (($row['platform'] ?? '') === 'grp') {
        $longGrp = (string) ($row['search_url'] ?? '');
    }
    if (($row['platform'] ?? '') === 'tourcenter') {
        $longTourcenter = (string) ($row['search_url'] ?? '');
    }
}
$contextFull = $service->buildTourContextForPrompt([
    'userText' => '六月底東京',
    'sno' => $travelBSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'hybridSearchConfig' => $hybridConfig,
    'travelBMultiSourceLinksConfig' => $fullMultiConfig,
    'multiSourceShortUrlProvider' => $mockProvider,
    'referenceDate' => $refCase2,
    'includeInstructions' => false,
]);
$shortGrp = platform_url_from_context($contextFull, 'grp');
$shortTourcenter = platform_url_from_context($contextFull, 'tourcenter');
short_url_assert(strpos($longGrp, 'ClassifyProduct.aspx') !== false, 'scope: grp long URL has ClassifyProduct');
short_url_assert(strpos($shortGrp, 'https://bbcshops.com/') === 0, 'scope: grp context short URL');
short_url_assert($shortGrp === $mockProvider->shortenSearchUrl($longGrp, [
    'source_id' => 'grp',
    'short_url_domain' => 'bbcshops.com',
    'domain_namespace' => 'bbcshops',
    'product_category' => 'group_tour',
]), 'scope: grp short matches provider');
short_url_assert(strpos($contextFull, 'dayitravel.grp.com.tw') === false, 'scope: grp no long URL in context');
short_url_assert(strpos($shortTourcenter, 'https://bbcshops.com/') === 0, 'scope: tourcenter context short URL');
short_url_assert($shortTourcenter === $mockProvider->shortenSearchUrl($longTourcenter, [
    'source_id' => 'tourcenter',
    'short_url_domain' => 'bbcshops.com',
    'domain_namespace' => 'bbcshops',
    'product_category' => 'group_tour',
]), 'scope: tourcenter short matches provider');
short_url_assert(strpos($contextFull, 'dayitourcenter.com.tw') === false, 'scope: tourcenter no long URL in context');
short_url_assert(strpos(bbctravel_line_from_context($contextFull), 'https://bbcshops.com/') === 0, 'scope: bbctravel shortened');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_bbctravel_multi_source_short_url\n");
exit(0);
