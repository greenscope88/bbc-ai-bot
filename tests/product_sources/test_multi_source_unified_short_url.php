<?php
declare(strict_types=1);

/**
 * Phase 3B: unified multi-source short URL (bbctravel + grp + tourcenter).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_prompt_context_service.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GeminiDerivedSearchConditionFixtures.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'MockShortUrlProvider.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tour_fallback_formatter.php';

$failures = 0;

function unified_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

function platform_url_from_context(string $context, string $platform): string
{
    foreach (preg_split("/\r\n|\n|\r/", $context) ?: [] as $line) {
        if (preg_match('/^' . preg_quote($platform, '/') . ':\s*(https?:\/\/\S+)/u', trim($line), $m) === 1) {
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
    'source_instance_keys' => ['dayitravel_grp', 'dayitravel_bbctravel', 'dayitravel_tourcenter'],
];
$ref = new DateTimeImmutable('2026-06-06', new DateTimeZone('Asia/Taipei'));

$mockClient = new TourSearchApiClient('https://example.test/tour/search', 5, static function (): array {
    $body = json_encode([
        'success' => true,
        'pagination' => ['total' => 1],
        'items' => [['title' => '精選', 'tourDate' => '2026-07-10', 'price' => 35000]],
        'search_url' => 'https://bbcshops.com/TESTMAIN',
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);

    return ['ok' => true, 'http_status' => 200, 'body' => $body !== false ? $body : '', 'transport_error' => null];
});

$service = new TourPromptContextService();
$multiBuilder = new TravelBMultiSourceLinkBuilder($multiSourceConfig);
$condition = GeminiDerivedSearchConditionFixtures::recentOsaka();

$longByPlatform = [];
foreach ($multiBuilder->buildFromHybridCondition($condition, $travelBSno) as $row) {
    $platform = (string) ($row['platform'] ?? '');
    if ($platform !== '') {
        $longByPlatform[$platform] = (string) ($row['search_url'] ?? '');
    }
}

$result = $service->buildTourContextResult([
    'userText' => '大阪近期',
    'sno' => $travelBSno,
    'featureEnabled' => true,
    'searchClient' => $mockClient,
    'travelBMultiSourceLinksConfig' => $multiSourceConfig,
    'multiSourceShortUrlProvider' => $mockProvider,
    'referenceDate' => $ref,
    'authoritativeIntent' => GeminiDerivedSearchConditionFixtures::osakaRecentIntent(),
    'includeInstructions' => false,
]);
$context = $result->getLegacyContext();
$lineReply = TourFallbackFormatter::formatFromTourContext($context);

$shortContext = [
    'short_url_domain' => 'bbcshops.com',
    'domain_namespace' => 'bbcshops',
    'product_category' => 'group_tour',
];

foreach (['bbctravel', 'grp', 'tourcenter'] as $platform) {
    unified_assert(isset($longByPlatform[$platform]) && $longByPlatform[$platform] !== '', 'long url exists: ' . $platform);
    $shortUrl = platform_url_from_context($context, $platform);
    unified_assert(strpos($shortUrl, 'https://bbcshops.com/') === 0, 'context short: ' . $platform);
    unified_assert($shortUrl === $mockProvider->shortenSearchUrl($longByPlatform[$platform], array_merge($shortContext, [
        'source_id' => $platform,
    ])), 'context short matches provider: ' . $platform);
}

unified_assert(strpos($longByPlatform['bbctravel'], '/searchlist/all/') !== false, 'bbctravel long path unchanged');
unified_assert(strpos($longByPlatform['grp'], 'ClassifyProduct.aspx') !== false, 'grp long path unchanged');
unified_assert(strpos($longByPlatform['tourcenter'], 'tourcenter.com.tw') !== false, 'tourcenter long url unchanged');

unified_assert(strpos($context, 'dayitravel.grp.com.tw') === false, 'context no grp long host');
unified_assert(strpos($context, 'dayitourcenter.com.tw') === false && strpos($context, 'tourcenter.com.tw') === false, 'context no tourcenter long host');

unified_assert(strpos($lineReply, '📢 更多【大阪】行程如下，歡迎利用以下網頁直接線上報名：') !== false, 'LINE CTA header');
unified_assert(strpos($lineReply, 'dayitravel.grp.com.tw') === false, 'LINE no grp long host');
unified_assert(strpos($lineReply, 'dayitourcenter.com.tw') === false && strpos($lineReply, 'tourcenter.com.tw') === false, 'LINE no tourcenter long host');
unified_assert(strpos($lineReply, '👉 https://bbcshops.com/') !== false, 'LINE CTA prefix');

$ctaCount = 0;
foreach (preg_split("/\r\n|\n|\r/", $lineReply) ?: [] as $line) {
    if (preg_match('/^👉\s+https:\/\/bbcshops\.com\//u', trim($line)) === 1) {
        ++$ctaCount;
    }
}
unified_assert($ctaCount >= 3, 'LINE at least three CTA short urls');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_multi_source_unified_short_url\n");
exit(0);
